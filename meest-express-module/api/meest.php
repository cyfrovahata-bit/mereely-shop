<?php
/**
 * Meest Express — backend API module
 * Підключайте окремо або вставте ці actions у ваш api/index.php
 *
 * Потрібно: PHP 7.4+, PDO SQLite3, розширення mbstring
 * DB: SQLite, шлях задається через $db (PDO-об'єкт) або константою MEEST_DB_PATH
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

// --- Якщо використовуєте як окремий файл ---
if (!isset($db)) {
    $db_path = defined('MEEST_DB_PATH') ? MEEST_DB_PATH : __DIR__ . '/../data/meest.db';
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
}

$db->sqliteCreateFunction('mb_lower', fn($s) => mb_strtolower((string)$s, 'UTF-8'), 1);

function meest_json($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function meest_err($msg, $code = 400) {
    http_response_code($code);
    meest_json(['error' => $msg]);
}

// Ініціалізація таблиць
$db->exec("CREATE TABLE IF NOT EXISTS meest_cities (
    uid  TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT ''
)");
$db->exec("CREATE TABLE IF NOT EXISTS meest_branches (
    uid       TEXT PRIMARY KEY,
    name      TEXT NOT NULL,
    address   TEXT NOT NULL DEFAULT '',
    city_uid  TEXT NOT NULL,
    is_locker INTEGER NOT NULL DEFAULT 0
)");
$db->exec("CREATE TABLE IF NOT EXISTS meest_streets (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    city_uid TEXT NOT NULL,
    name     TEXT NOT NULL
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_meest_branches_city ON meest_branches(city_uid, is_locker)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_meest_streets_city  ON meest_streets(city_uid)");

$action = $_GET['action'] ?? '';

// --- Статус бази ---
if ($action === 'status') {
    $cities   = (int)$db->query("SELECT COUNT(*) FROM meest_cities")->fetchColumn();
    $branches = (int)$db->query("SELECT COUNT(*) FROM meest_branches WHERE is_locker=0")->fetchColumn();
    $lockers  = (int)$db->query("SELECT COUNT(*) FROM meest_branches WHERE is_locker=1")->fetchColumn();
    $streets  = (int)$db->query("SELECT COUNT(*) FROM meest_streets")->fetchColumn();
    $updated  = @file_get_contents(__DIR__ . '/../data/meest_updated.txt') ?: '';
    meest_json(compact('cities','branches','lockers','streets','updated'));
}

// --- Завантаження даних (з адмінки) ---
if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') meest_err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $type  = $input['type'] ?? '';

    if ($type === 'cities') {
        if (!isset($input['cities'])) meest_err('Invalid data');
        $db->exec('BEGIN');
        $db->exec('DELETE FROM meest_cities');
        $ins = $db->prepare('INSERT OR REPLACE INTO meest_cities (uid, name, type) VALUES (?,?,?)');
        foreach ($input['cities'] as $c) $ins->execute([$c['uid'], $c['name'], $c['type'] ?? '']);
        $db->exec('COMMIT');
        meest_json(['ok' => true, 'count' => count($input['cities'])]);
    }
    if ($type === 'branches') {
        if (!isset($input['branches'])) meest_err('Invalid data');
        $db->exec('BEGIN');
        $db->exec('DELETE FROM meest_branches');
        $ins = $db->prepare('INSERT OR REPLACE INTO meest_branches (uid, name, address, city_uid, is_locker) VALUES (?,?,?,?,?)');
        foreach ($input['branches'] as $b) {
            $is_locker = mb_stripos($b['name'] ?? '', 'поштомат', 0, 'UTF-8') !== false ? 1 : 0;
            $ins->execute([$b['uid'], $b['name'], $b['address'] ?? '', $b['city_uid'], $is_locker]);
        }
        $db->exec('COMMIT');
        meest_json(['ok' => true, 'count' => count($input['branches'])]);
    }
    if ($type === 'streets') {
        if (!isset($input['streets'])) meest_err('Invalid data');
        $db->exec('BEGIN');
        $db->exec('DELETE FROM meest_streets');
        $ins = $db->prepare('INSERT INTO meest_streets (city_uid, name) VALUES (?,?)');
        foreach ($input['streets'] as $city_uid => $names)
            foreach ($names as $name) $ins->execute([$city_uid, $name]);
        $db->exec('COMMIT');
        meest_json(['ok' => true]);
    }
    if ($type === 'updated') {
        file_put_contents(__DIR__ . '/../data/meest_updated.txt', $input['date'] ?? date('d.m.Y H:i'));
        meest_json(['ok' => true]);
    }
    if ($type === 'clear') {
        $db->exec('DELETE FROM meest_cities');
        $db->exec('DELETE FROM meest_branches');
        $db->exec('DELETE FROM meest_streets');
        @unlink(__DIR__ . '/../data/meest_updated.txt');
        meest_json(['ok' => true]);
    }
    meest_err('Unknown type');
}

// --- Пошук міст ---
if ($action === 'cities') {
    $q     = mb_strtolower(trim($_GET['q'] ?? ''), 'UTF-8');
    $limit = min((int)($_GET['limit'] ?? 10), 50);
    if (strlen($q) < 2) meest_json([]);
    $s = $db->prepare("SELECT uid, name, type FROM meest_cities WHERE mb_lower(name) LIKE :q ORDER BY LENGTH(name) LIMIT :lim");
    $s->bindValue(':q',   '%' . $q . '%');
    $s->bindValue(':lim', $limit, PDO::PARAM_INT);
    $s->execute();
    meest_json($s->fetchAll(PDO::FETCH_ASSOC));
}

// --- Відділення / поштомати ---
if ($action === 'branches') {
    $city_uid  = $_GET['city_uid'] ?? '';
    $is_locker = isset($_GET['locker']) ? 1 : 0;
    if (!$city_uid) meest_json([]);
    $s = $db->prepare("SELECT uid, name, address FROM meest_branches WHERE city_uid=? AND is_locker=? ORDER BY name LIMIT 200");
    $s->execute([$city_uid, $is_locker]);
    meest_json($s->fetchAll(PDO::FETCH_ASSOC));
}

// --- Пошук вулиць (для кур'єрської доставки) ---
if ($action === 'streets') {
    $q        = mb_strtolower(trim($_GET['q'] ?? ''), 'UTF-8');
    $city_uid = $_GET['city_uid'] ?? '';
    $limit    = min((int)($_GET['limit'] ?? 10), 50);
    if (strlen($q) < 2 || !$city_uid) meest_json([]);
    $s = $db->prepare("SELECT name FROM meest_streets WHERE city_uid=:cu AND mb_lower(name) LIKE :q ORDER BY LENGTH(name) LIMIT :lim");
    $s->bindValue(':cu',  $city_uid);
    $s->bindValue(':q',   '%' . $q . '%');
    $s->bindValue(':lim', $limit, PDO::PARAM_INT);
    $s->execute();
    $rows = $s->fetchAll(PDO::FETCH_COLUMN);
    meest_json(array_map(fn($n) => ['name' => $n, 'label' => $n], $rows));
}
