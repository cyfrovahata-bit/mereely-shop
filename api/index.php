<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$db_path = __DIR__ . '/../data/mereely.db';

function json_out($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function err($msg, $code = 400) {
    http_response_code($code);
    json_out(['error' => $msg]);
}

$action = $_GET['action'] ?? '';

try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    err('DB not available', 500);
}

// --- Пошук міст (для Meest і Укрпошти) ---
if ($action === 'cities') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) json_out([]);
    $limit = min((int)($_GET['limit'] ?? 10), 50);

    $stmt = $db->prepare("
        SELECT uid, name, type FROM cities
        WHERE name LIKE :q ESCAPE '\\'
        ORDER BY CASE WHEN name LIKE :qs ESCAPE '\\' THEN 0 ELSE 1 END, name
        LIMIT :limit
    ");
    $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    $like_start = str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    $stmt->bindValue(':q', $like);
    $stmt->bindValue(':qs', $like_start);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    json_out($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// --- Відділення/поштомати Meest для міста ---
if ($action === 'branches') {
    $city_uid = trim($_GET['city_uid'] ?? '');
    if (!$city_uid) json_out([]);
    $type = $_GET['type'] ?? 'branch'; // branch | locker

    $stmt = $db->prepare("
        SELECT uid, name, address FROM branches
        WHERE city_uid = :city_uid AND is_locker = :is_locker
        ORDER BY name
    ");
    $stmt->bindValue(':city_uid', $city_uid);
    $stmt->bindValue(':is_locker', $type === 'locker' ? 1 : 0, PDO::PARAM_INT);
    $stmt->execute();
    json_out($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// --- Вулиці для міста ---
if ($action === 'streets') {
    $city_uid = trim($_GET['city_uid'] ?? '');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) json_out([]);

    if ($city_uid) {
        $stmt = $db->prepare("
            SELECT name FROM streets
            WHERE city_uid = :city_uid AND name LIKE :q ESCAPE '\\'
            ORDER BY CASE WHEN name LIKE :qs ESCAPE '\\' THEN 0 ELSE 1 END, name
            LIMIT 10
        ");
        $stmt->bindValue(':city_uid', $city_uid);
    } else {
        $stmt = $db->prepare("
            SELECT DISTINCT name FROM streets
            WHERE name LIKE :q ESCAPE '\\'
            ORDER BY CASE WHEN name LIKE :qs ESCAPE '\\' THEN 0 ELSE 1 END, name
            LIMIT 10
        ");
    }
    $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    $like_start = str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    $stmt->bindValue(':q', $like);
    $stmt->bindValue(':qs', $like_start);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    json_out($rows);
}

// --- Завантаження даних з ZIP (тільки для адмінки) ---
if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');

    $token = $_POST['token'] ?? '';
    $admin_token = file_exists(__DIR__ . '/../data/admin_token.txt')
        ? trim(file_get_contents(__DIR__ . '/../data/admin_token.txt'))
        : '';
    if (!$admin_token || $token !== $admin_token) err('Unauthorized', 401);

    $type = $_POST['type'] ?? '';

    // приймаємо JSON-дані
    if ($type === 'cities') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['cities'])) err('Invalid data');

        $db->exec('BEGIN');
        $db->exec('DELETE FROM cities');
        $ins = $db->prepare('INSERT OR REPLACE INTO cities (uid, name, type) VALUES (?,?,?)');
        foreach ($data['cities'] as $c) {
            $ins->execute([$c['uid'], $c['name'], $c['type'] ?? '']);
        }
        $db->exec('COMMIT');
        json_out(['ok' => true, 'count' => count($data['cities'])]);
    }

    if ($type === 'branches') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['branches'])) err('Invalid data');

        $db->exec('BEGIN');
        $db->exec('DELETE FROM branches');
        $ins = $db->prepare('INSERT OR REPLACE INTO branches (uid, name, address, city_uid, is_locker) VALUES (?,?,?,?,?)');
        foreach ($data['branches'] as $b) {
            $is_locker = (int)(stripos($b['name'] ?? '', 'поштомат') !== false);
            $ins->execute([$b['uid'], $b['name'], $b['address'] ?? '', $b['cityUid'], $is_locker]);
        }
        $db->exec('COMMIT');
        json_out(['ok' => true, 'count' => count($data['branches'])]);
    }

    if ($type === 'streets') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['streets'])) err('Invalid data');

        $db->exec('BEGIN');
        $db->exec('DELETE FROM streets');
        $ins = $db->prepare('INSERT INTO streets (city_uid, name) VALUES (?,?)');
        foreach ($data['streets'] as $city_uid => $names) {
            foreach ($names as $name) {
                $ins->execute([$city_uid, $name]);
            }
        }
        $db->exec('COMMIT');
        json_out(['ok' => true]);
    }

    if ($type === 'updated') {
        $date = $_POST['date'] ?? date('d.m.Y H:i');
        file_put_contents(__DIR__ . '/../data/updated.txt', $date);
        json_out(['ok' => true]);
    }

    if ($type === 'clear') {
        $db->exec('DELETE FROM cities');
        $db->exec('DELETE FROM branches');
        $db->exec('DELETE FROM streets');
        @unlink(__DIR__ . '/../data/updated.txt');
        json_out(['ok' => true]);
    }

    err('Unknown type');
}

// --- Статус бази ---
if ($action === 'status') {
    $counts = [
        'cities'   => $db->query("SELECT COUNT(*) FROM cities")->fetchColumn(),
        'branches' => $db->query("SELECT COUNT(*) FROM branches")->fetchColumn(),
        'streets'  => $db->query("SELECT COUNT(*) FROM streets")->fetchColumn(),
    ];
    $updated = file_exists(__DIR__ . '/../data/updated.txt')
        ? trim(file_get_contents(__DIR__ . '/../data/updated.txt'))
        : '';
    json_out(['counts' => $counts, 'updated' => $updated]);
}

err('Unknown action');
