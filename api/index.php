<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$db_path     = __DIR__ . '/../data/mereely.db';
$uploads_dir = __DIR__ . '/../uploads/products/';

function json_out($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function err($msg, $code = 400) {
    http_response_code($code);
    json_out(['error' => $msg]);
}

function decode_json_col($v) {
    return json_decode($v ?: '[]', true) ?? [];
}

$action = $_GET['action'] ?? '';

try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->sqliteCreateFunction('mb_lower', function($s){ return mb_strtolower((string)$s, 'UTF-8'); }, 1);
} catch (Exception $e) {
    err('DB not available', 500);
}

// ============================================================
// ПУБЛІЧНІ ENDPOINTS (читання)
// ============================================================

// --- Пошук міст ---
if ($action === 'cities') {
    $q = mb_strtolower(trim($_GET['q'] ?? ''), 'UTF-8');
    if (mb_strlen($q) < 2) json_out([]);
    $limit = min((int)($_GET['limit'] ?? 10), 50);
    $stmt = $db->prepare("
        SELECT uid, name, type FROM cities
        WHERE mb_lower(name) LIKE :q ESCAPE '\\'
        ORDER BY CASE WHEN mb_lower(name) LIKE :qs ESCAPE '\\' THEN 0 ELSE 1 END, name
        LIMIT :limit
    ");
    $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    $stmt->bindValue(':q',  $like);
    $stmt->bindValue(':qs', str_replace(['%','_'], ['\\%','\\_'], $q) . '%');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    json_out($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// --- Відділення/поштомати Meest ---
if ($action === 'branches') {
    $city_uid = trim($_GET['city_uid'] ?? '');
    if (!$city_uid) json_out([]);
    $type = $_GET['type'] ?? 'branch';
    $stmt = $db->prepare("SELECT uid, name, address FROM branches WHERE city_uid=:c AND is_locker=:l ORDER BY name");
    $stmt->execute([':c' => $city_uid, ':l' => $type === 'locker' ? 1 : 0]);
    json_out($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// --- Вулиці ---
if ($action === 'streets') {
    $city_uid = trim($_GET['city_uid'] ?? '');
    $q = mb_strtolower(trim($_GET['q'] ?? ''), 'UTF-8');
    if (mb_strlen($q) < 2) json_out([]);
    $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    $like_s = str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    if ($city_uid) {
        $stmt = $db->prepare("SELECT name FROM streets WHERE city_uid=:c AND mb_lower(name) LIKE :q ESCAPE '\\' ORDER BY CASE WHEN mb_lower(name) LIKE :qs ESCAPE '\\' THEN 0 ELSE 1 END, name LIMIT 10");
        $stmt->bindValue(':c', $city_uid);
    } else {
        $stmt = $db->prepare("SELECT DISTINCT name FROM streets WHERE mb_lower(name) LIKE :q ESCAPE '\\' ORDER BY CASE WHEN mb_lower(name) LIKE :qs ESCAPE '\\' THEN 0 ELSE 1 END, name LIMIT 10");
    }
    $stmt->bindValue(':q', $like);
    $stmt->bindValue(':qs', $like_s);
    $stmt->execute();
    json_out($stmt->fetchAll(PDO::FETCH_COLUMN));
}

// --- Список товарів (публічний) ---
if ($action === 'products') {
    $stmt = $db->query("SELECT id, name, description, price, old_price, images, sizes, colors, category FROM products WHERE active=1 ORDER BY sort_order, id");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['images'] = decode_json_col($r['images']);
        $r['sizes']  = decode_json_col($r['sizes']);
        $r['colors'] = decode_json_col($r['colors']);
    }
    json_out($rows);
}

// --- Налаштування (публічні, без секретних) ---
if ($action === 'settings_public') {
    $allowed = ['shop_name','shop_phone','shop_email','shop_instagram','shop_telegram'];
    $result = [];
    foreach ($allowed as $k) {
        $s = $db->prepare("SELECT value FROM settings WHERE key=?");
        $s->execute([$k]);
        $result[$k] = $s->fetchColumn() ?: '';
    }
    json_out($result);
}

// --- Підписка (публічна) ---
if ($action === 'subscribe') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = trim(strtolower($input['email'] ?? ''));
    $name  = trim($input['name'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Невірний email');
    try {
        $s = $db->prepare("INSERT INTO subscribers (email, name) VALUES (?,?) ON CONFLICT(email) DO UPDATE SET active=1");
        $s->execute([$email, $name]);
        json_out(['ok' => true]);
    } catch (Exception $e) { err('Помилка збереження'); }
}

// --- Нове замовлення (публічне) ---
if ($action === 'order_create') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $contact  = $input['contact']  ?? [];
    $delivery = $input['delivery'] ?? [];
    $items    = $input['items']    ?? [];
    $total    = (int)($input['total'] ?? 0);
    $note     = trim($input['note'] ?? '');
    if (!$contact || !$items) err('Дані неповні');
    $s = $db->prepare("INSERT INTO orders (contact, delivery, items, total, note) VALUES (?,?,?,?,?)");
    $s->execute([json_encode($contact, JSON_UNESCAPED_UNICODE), json_encode($delivery, JSON_UNESCAPED_UNICODE), json_encode($items, JSON_UNESCAPED_UNICODE), $total, $note]);
    json_out(['ok' => true, 'id' => $db->lastInsertId()]);
}

// ============================================================
// АДМІН ENDPOINTS
// ============================================================

// --- Статус бази ---
if ($action === 'status') {
    $counts = [
        'cities'   => $db->query("SELECT COUNT(*) FROM cities")->fetchColumn(),
        'branches' => $db->query("SELECT COUNT(*) FROM branches WHERE is_locker=0")->fetchColumn(),
        'lockers'  => $db->query("SELECT COUNT(*) FROM branches WHERE is_locker=1")->fetchColumn(),
        'streets'  => $db->query("SELECT COUNT(*) FROM streets")->fetchColumn(),
        'products' => $db->query("SELECT COUNT(*) FROM products")->fetchColumn(),
        'orders'   => $db->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
        'subscribers' => $db->query("SELECT COUNT(*) FROM subscribers WHERE active=1")->fetchColumn(),
    ];
    $updated = file_exists(__DIR__ . '/../data/updated.txt') ? trim(file_get_contents(__DIR__ . '/../data/updated.txt')) : '';
    json_out(['counts' => $counts, 'updated' => $updated]);
}

// --- Налаштування (читання/запис) ---
if ($action === 'settings_get') {
    $rows = $db->query("SELECT key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    json_out($rows);
}

if ($action === 'settings_set') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $s = $db->prepare("INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
    foreach ($input as $k => $v) {
        if (preg_match('/^[a-z0-9_]{1,64}$/', $k)) $s->execute([(string)$k, (string)$v]);
    }
    json_out(['ok' => true]);
}

// --- Товари (адмін) ---
if ($action === 'admin_products') {
    $stmt = $db->query("SELECT * FROM products ORDER BY sort_order, id");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['images'] = decode_json_col($r['images']);
        $r['sizes']  = decode_json_col($r['sizes']);
        $r['colors'] = decode_json_col($r['colors']);
    }
    json_out($rows);
}

if ($action === 'product_save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($input['id'] ?? 0);
    $data = [
        $input['name']        ?? '',
        $input['description'] ?? '',
        (int)($input['price']     ?? 0),
        (int)($input['old_price'] ?? 0),
        json_encode($input['images'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($input['sizes']  ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($input['colors'] ?? [], JSON_UNESCAPED_UNICODE),
        $input['category']    ?? '',
        (int)($input['active']     ?? 1),
        (int)($input['sort_order'] ?? 0),
    ];
    if ($id) {
        $s = $db->prepare("UPDATE products SET name=?, description=?, price=?, old_price=?, images=?, sizes=?, colors=?, category=?, active=?, sort_order=? WHERE id=?");
        $s->execute([...$data, $id]);
        json_out(['ok' => true, 'id' => $id]);
    } else {
        $s = $db->prepare("INSERT INTO products (name, description, price, old_price, images, sizes, colors, category, active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $s->execute($data);
        json_out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }
}

if ($action === 'product_delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? 0);
    if (!$id) err('No id');
    $db->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
    json_out(['ok' => true]);
}

// --- Завантаження фото товару ---
if ($action === 'upload_image') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    if (!isset($_FILES['file'])) err('No file');
    $file = $_FILES['file'];
    $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!in_array($file['type'], $allowed_types)) err('Тільки зображення');
    if ($file['size'] > 10 * 1024 * 1024) err('Файл більше 10МБ');

    if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);

    $ext  = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $name = uniqid('p_') . '.' . strtolower($ext);
    $dest = $uploads_dir . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) err('Помилка збереження файлу');
    json_out(['ok' => true, 'url' => '/uploads/products/' . $name]);
}

// --- Замовлення (адмін) ---
if ($action === 'orders_list') {
    $limit  = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? '';
    if ($status) {
        $stmt = $db->prepare("SELECT * FROM orders WHERE status=? ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$status, $limit, $offset]);
    } else {
        $stmt = $db->prepare("SELECT * FROM orders ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['contact']  = json_decode($r['contact'],  true) ?? [];
        $r['delivery'] = json_decode($r['delivery'], true) ?? [];
        $r['items']    = json_decode($r['items'],    true) ?? [];
    }
    $total = $db->query("SELECT COUNT(*) FROM orders" . ($status ? " WHERE status='$status'" : ''))->fetchColumn();
    json_out(['rows' => $rows, 'total' => (int)$total]);
}

if ($action === 'order_status') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id     = (int)($input['id'] ?? 0);
    $status = $input['status'] ?? '';
    $allowed = ['new','confirmed','shipped','delivered','cancelled'];
    if (!$id || !in_array($status, $allowed)) err('Invalid');
    $db->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$status, $id]);
    json_out(['ok' => true]);
}

// --- Підписники (адмін) ---
if ($action === 'subscribers_list') {
    $stmt = $db->query("SELECT id, email, name, active, created_at FROM subscribers ORDER BY created_at DESC");
    json_out($stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($action === 'subscriber_delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? 0);
    if (!$id) err('No id');
    $db->prepare("UPDATE subscribers SET active=0 WHERE id=?")->execute([$id]);
    json_out(['ok' => true]);
}

// --- Завантаження даних Meest з ZIP ---
if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $type = $input['type'] ?? '';

    if ($type === 'cities') {
        if (!isset($input['cities'])) err('Invalid data');
        $db->exec('BEGIN');
        $db->exec('DELETE FROM cities');
        $ins = $db->prepare('INSERT OR REPLACE INTO cities (uid, name, type) VALUES (?,?,?)');
        foreach ($input['cities'] as $c) $ins->execute([$c['uid'], $c['name'], $c['type'] ?? '']);
        $db->exec('COMMIT');
        json_out(['ok' => true, 'count' => count($input['cities'])]);
    }

    if ($type === 'branches') {
        if (!isset($input['branches'])) err('Invalid data');
        $db->exec('BEGIN');
        $db->exec('DELETE FROM branches');
        $ins = $db->prepare('INSERT OR REPLACE INTO branches (uid, name, address, city_uid, is_locker) VALUES (?,?,?,?,?)');
        foreach ($input['branches'] as $b) {
            $is_locker = (int)(mb_stripos($b['name'] ?? '', 'поштомат', 0, 'UTF-8') !== false);
            $ins->execute([$b['uid'], $b['name'], $b['address'] ?? '', $b['cityUid'], $is_locker]);
        }
        $db->exec('COMMIT');
        json_out(['ok' => true, 'count' => count($input['branches'])]);
    }

    if ($type === 'streets') {
        if (!isset($input['streets'])) err('Invalid data');
        $db->exec('BEGIN');
        $db->exec('DELETE FROM streets');
        $ins = $db->prepare('INSERT INTO streets (city_uid, name) VALUES (?,?)');
        foreach ($input['streets'] as $city_uid => $names)
            foreach ($names as $name) $ins->execute([$city_uid, $name]);
        $db->exec('COMMIT');
        json_out(['ok' => true]);
    }

    if ($type === 'updated') {
        file_put_contents(__DIR__ . '/../data/updated.txt', $input['date'] ?? date('d.m.Y H:i'));
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

// --- Діагностика ---
if ($action === 'debug') {
    $city = trim($_GET['city'] ?? '');
    $rows = $db->prepare("SELECT uid, name, type FROM cities WHERE mb_lower(name) LIKE :q LIMIT 20");
    $rows->execute([':q' => '%' . mb_strtolower($city, 'UTF-8') . '%']);
    $cities = $rows->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($cities as $c) {
        $br = $db->prepare("SELECT COUNT(*) FROM branches WHERE city_uid=? AND is_locker=0");
        $br->execute([$c['uid']]);
        $lk = $db->prepare("SELECT COUNT(*) FROM branches WHERE city_uid=? AND is_locker=1");
        $lk->execute([$c['uid']]);
        $result[] = ['city'=>$c['name'],'type'=>$c['type'],'uid'=>$c['uid'],'branches'=>(int)$br->fetchColumn(),'lockers'=>(int)$lk->fetchColumn()];
    }
    json_out($result);
}

err('Unknown action');
