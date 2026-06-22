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

// --- Конвертація зображення у WebP через GD ---
function convert_to_webp($src_path, $dest_path, $quality = 82) {
    $info = @getimagesize($src_path);
    if (!$info) return false;
    $mime = $info['mime'];
    $img = null;
    if ($mime === 'image/jpeg') $img = @imagecreatefromjpeg($src_path);
    elseif ($mime === 'image/png') {
        $img = @imagecreatefrompng($src_path);
        if ($img) {
            imagepalettetotruecolor($img);
            imagealphablending($img, true);
            imagesavealpha($img, true);
        }
    }
    elseif ($mime === 'image/webp') $img = @imagecreatefromwebp($src_path);
    elseif ($mime === 'image/gif')  $img = @imagecreatefromgif($src_path);
    if (!$img) return false;
    $ok = imagewebp($img, $dest_path, $quality);
    imagedestroy($img);
    return $ok;
}

$action = $_GET['action'] ?? '';

try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("PRAGMA synchronous=NORMAL");
    $db->exec("PRAGMA foreign_keys=ON");

    // Автоматично додаємо нові колонки якщо ще немає
    $cols = array_column($db->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('care',         $cols)) $db->exec("ALTER TABLE products ADD COLUMN care TEXT NOT NULL DEFAULT ''");
    if (!in_array('material',     $cols)) $db->exec("ALTER TABLE products ADD COLUMN material TEXT NOT NULL DEFAULT ''");
    if (!in_array('manufacturer', $cols)) $db->exec("ALTER TABLE products ADD COLUMN manufacturer TEXT NOT NULL DEFAULT ''");
    if (!in_array('badge',        $cols)) $db->exec("ALTER TABLE products ADD COLUMN badge TEXT NOT NULL DEFAULT ''");

    // Таблиця категорій
    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT NOT NULL UNIQUE,
        image      TEXT NOT NULL DEFAULT '',
        sort_order INTEGER NOT NULL DEFAULT 0
    )");

    // Таблиця замовлень (нова схема)
    $db->exec("CREATE TABLE IF NOT EXISTS orders_new (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        order_num      TEXT NOT NULL,
        customer_name  TEXT,
        customer_phone TEXT,
        customer_email TEXT,
        city           TEXT,
        address        TEXT,
        items          TEXT,
        subtotal       REAL,
        discount       REAL DEFAULT 0,
        shipping_cost  REAL DEFAULT 0,
        total          REAL,
        payment_method TEXT DEFAULT 'online',
        status         TEXT DEFAULT 'new',
        notes          TEXT,
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Таблиця відгуків
    $db->exec("CREATE TABLE IF NOT EXISTS reviews (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        author     TEXT NOT NULL,
        rating     INTEGER NOT NULL CHECK(rating BETWEEN 1 AND 5),
        text       TEXT NOT NULL,
        verified   INTEGER DEFAULT 0,
        approved   INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->sqliteCreateFunction('mb_lower', function($s){ return mb_strtolower((string)$s, 'UTF-8'); }, 1);

} catch (Exception $e) {
    err('DB error: ' . $e->getMessage(), 500);
}

// ===================== ПУБЛІЧНІ =====================

// --- Товари (публічний) ---
if ($action === 'products') {
    $stmt = $db->query("SELECT id, name, description, care, material, manufacturer, price, old_price, images, sizes, colors, category, badge, active FROM products WHERE active=1 ORDER BY sort_order, id");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['images'] = decode_json_col($r['images']);
        $r['sizes']  = decode_json_col($r['sizes']);
        $r['colors'] = decode_json_col($r['colors']);
    }
    json_out($rows);
}

// --- Категорії (публічний) ---
if ($action === 'categories') {
    $rows = $db->query("SELECT id, name, image FROM categories ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
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
    $s = $db->prepare("INSERT OR IGNORE INTO subscribers (email, name) VALUES (?,?)");
    $s->execute([$email, $name]);
    json_out(['ok' => true]);
}

// --- Публічне замовлення (нова схема) ---
if ($action === 'order') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $order_num = $input['order_num'] ?? ('MRL-' . strtoupper(substr(base_convert(time(), 10, 36), -6)));
    $s = $db->prepare("INSERT INTO orders_new
        (order_num, customer_name, customer_phone, customer_email, city, address, items,
         subtotal, discount, shipping_cost, total, payment_method, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $s->execute([
        $order_num,
        $input['customer_name']  ?? '',
        $input['customer_phone'] ?? '',
        $input['customer_email'] ?? '',
        $input['city']           ?? '',
        $input['address']        ?? '',
        is_array($input['items'] ?? null) ? json_encode($input['items'], JSON_UNESCAPED_UNICODE) : ($input['items'] ?? ''),
        (float)($input['subtotal']      ?? 0),
        (float)($input['discount']      ?? 0),
        (float)($input['shipping_cost'] ?? 0),
        (float)($input['total']         ?? 0),
        $input['payment_method'] ?? 'online',
        $input['notes']          ?? '',
    ]);
    $new_id = (int)$db->lastInsertId();

    // Email підтвердження клієнту
    $customer_email = trim($input['customer_email'] ?? '');
    $customer_name  = trim($input['customer_name']  ?? 'Покупцю');
    $items_arr = is_array($input['items'] ?? null) ? $input['items'] : (json_decode($input['items'] ?? '[]', true) ?? []);
    $items_html = '';
    foreach ($items_arr as $it) {
        $iname = htmlspecialchars($it['name'] ?? $it['title'] ?? '', ENT_QUOTES);
        $iqty  = (int)($it['qty']  ?? $it['quantity'] ?? 1);
        $iprice= (float)($it['price'] ?? 0);
        $items_html .= "<tr><td style='padding:6px 12px;border-bottom:1px solid #e8e0d6;'>{$iname}</td><td style='padding:6px 12px;border-bottom:1px solid #e8e0d6;text-align:center;'>{$iqty}</td><td style='padding:6px 12px;border-bottom:1px solid #e8e0d6;text-align:right;'>" . number_format($iprice,0,'.',' ') . " ₴</td></tr>";
    }
    $total_fmt = number_format((float)($input['total'] ?? 0), 0, '.', "\xC2\xA0") . ' ₴';
    $city_esc  = htmlspecialchars($input['city']    ?? '', ENT_QUOTES);
    $addr_esc  = htmlspecialchars($input['address'] ?? '', ENT_QUOTES);

    $html_body = <<<HTML
<!DOCTYPE html><html lang="uk"><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#f1ebe1;font-family:Manrope,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1ebe1;padding:40px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;max-width:600px;">
<tr><td style="background:#1e231f;padding:32px 40px;text-align:center;">
  <span style="font-family:serif;font-size:28px;color:#f1ebe1;letter-spacing:2px;">Marylee</span>
</td></tr>
<tr><td style="padding:40px;">
  <h1 style="margin:0 0 8px;font-size:22px;color:#1e231f;">Дякуємо за замовлення!</h1>
  <p style="color:#6f7a5e;margin:0 0 24px;">Привіт, {$customer_name}! Ваше замовлення <strong>{$order_num}</strong> прийнято.</p>
  <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e8e0d6;border-radius:8px;overflow:hidden;margin-bottom:24px;">
    <tr style="background:#f1ebe1;"><th style="padding:8px 12px;text-align:left;font-size:13px;color:#6f7a5e;">Товар</th><th style="padding:8px 12px;text-align:center;font-size:13px;color:#6f7a5e;">К-ть</th><th style="padding:8px 12px;text-align:right;font-size:13px;color:#6f7a5e;">Ціна</th></tr>
    {$items_html}
    <tr><td colspan="2" style="padding:10px 12px;font-weight:700;color:#1e231f;">Разом</td><td style="padding:10px 12px;font-weight:700;color:#1e231f;text-align:right;">{$total_fmt}</td></tr>
  </table>
  <p style="color:#6f7a5e;margin:0 0 4px;font-size:14px;"><strong>Місто:</strong> {$city_esc}</p>
  <p style="color:#6f7a5e;margin:0 0 24px;font-size:14px;"><strong>Адреса доставки:</strong> {$addr_esc}</p>
  <p style="color:#6f7a5e;font-size:13px;margin:0;">З питань: <a href="mailto:hello@styleroom.pp.ua" style="color:#a7613f;">hello@styleroom.pp.ua</a></p>
</td></tr>
<tr><td style="background:#1e231f;padding:20px 40px;text-align:center;">
  <p style="color:#6f7a5e;font-size:12px;margin:0;">© Marylee Shop · styleroom.pp.ua</p>
</td></tr>
</table>
</td></tr></table>
</body></html>
HTML;

    $from    = 'noreply@styleroom.pp.ua';
    $subject = "=?UTF-8?B?" . base64_encode("Замовлення #{$order_num} підтверджено — Marylee Shop") . "?=";
    $headers = implode("\r\n", [
        "From: Marylee Shop <{$from}>",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
    ]);

    if ($customer_email && filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
        @mail($customer_email, $subject, $html_body, $headers);
    }
    // Повідомлення власнику
    $owner_body = "<p>Нове замовлення <strong>{$order_num}</strong> від {$customer_name} ({$customer_email}, {$customer_phone}).<br>Сума: {$total_fmt}<br>Місто: {$city_esc}<br>Адреса: {$addr_esc}</p>";
    $owner_headers = implode("\r\n", ["From: Marylee Shop <{$from}>", "MIME-Version: 1.0", "Content-Type: text/html; charset=UTF-8"]);
    @mail('hello@styleroom.pp.ua', "=?UTF-8?B?" . base64_encode("Нове замовлення #{$order_num}") . "?=", $owner_body, $owner_headers);

    json_out(['ok' => true, 'id' => $new_id, 'order_num' => $order_num]);
}

// --- Зворотна сумісність ---
if ($action === 'order_create') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $order_num = 'MRL-' . strtoupper(substr(base_convert(time(), 10, 36), -6));
    $contact  = $input['contact']  ?? [];
    $delivery = $input['delivery'] ?? [];
    $s = $db->prepare("INSERT INTO orders_new
        (order_num, customer_name, customer_phone, customer_email, city, address, items, total, notes)
        VALUES (?,?,?,?,?,?,?,?,?)");
    $s->execute([
        $order_num,
        $contact['name']  ?? '',
        $contact['phone'] ?? '',
        $contact['email'] ?? '',
        $delivery['city']    ?? '',
        $delivery['address'] ?? '',
        json_encode($input['items'] ?? [], JSON_UNESCAPED_UNICODE),
        (int)($input['total'] ?? 0),
        $input['note'] ?? '',
    ]);
    json_out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}

// --- Відгуки (публічні — схвалені) ---
if ($action === 'reviews') {
    $product_id = (int)($_GET['product_id'] ?? 0);
    if (!$product_id) json_out([]);
    $s = $db->prepare("SELECT id, author, rating, text, verified, created_at FROM reviews WHERE product_id=? AND approved=1 ORDER BY created_at DESC");
    $s->execute([$product_id]);
    json_out($s->fetchAll(PDO::FETCH_ASSOC));
}

// --- Зберегти відгук (публічний) ---
if ($action === 'review') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $product_id = (int)($input['product_id'] ?? 0);
    $author     = trim($input['author'] ?? '');
    $rating     = (int)($input['rating'] ?? 0);
    $text       = trim($input['text'] ?? '');
    if (!$product_id || !$author || $rating < 1 || $rating > 5 || !$text) err('Заповніть всі поля');
    $s = $db->prepare("INSERT INTO reviews (product_id, author, rating, text) VALUES (?,?,?,?)");
    $s->execute([$product_id, $author, $rating, $text]);
    json_out(['ok' => true]);
}

// --- NP проксі (Nova Poshta API key залишається на сервері) ---
if ($action === 'np') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $s = $db->prepare("SELECT value FROM settings WHERE key='np_api_key'");
    $s->execute();
    $np_key = $s->fetchColumn() ?: '';
    if (!$np_key) err('Nova Poshta API key not configured', 503);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $body['apiKey'] = $np_key;
    $ch = curl_init('https://api.novaposhta.ua/v2.0/json/');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
    $resp = curl_exec($ch); curl_close($ch);
    echo $resp; exit;
}

// --- AI генерація опису / догляду (Claude API) ---
if ($action === 'ai_generate') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $s = $db->prepare("SELECT value FROM settings WHERE key='claude_api_key'");
    $s->execute();
    $api_key = $s->fetchColumn() ?: '';
    if (!$api_key) err('Claude API key not configured. Додайте ключ у Налаштуваннях → AI.', 503);

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $type  = $input['type'] ?? 'description'; // description | care
    $name  = trim($input['name'] ?? '');
    $category = trim($input['category'] ?? '');
    $colors   = implode(', ', array_map(fn($c)=>is_array($c)?$c[0]:($c['name']??$c), (array)($input['colors'] ?? [])));
    $material = trim($input['material'] ?? '');

    if ($type === 'care') {
        $prompt = "Ти копірайтер українського магазину жіночого одягу Marylee Shop. Напиши короткі поради з догляду за виробом «{$name}» (категорія: {$category}" . ($material ? ", матеріал: {$material}" : '') . "). 3-4 пункти, лаконічно, тільки текст правил без зайвих слів. Виводь кожен пункт з нового рядка, починаючи з емодзі (🌡, 🚫, ✋, ☀ тощо). Відповідай тільки українською.";
    } else {
        $prompt = "Ти копірайтер українського магазину жіночого одягу Marylee Shop. Напиши привабливий опис товару «{$name}» (категорія: {$category}" . ($colors ? ", кольори: {$colors}" : '') . ($material ? ", матеріал: {$material}" : '') . "). 2-3 речення, живий стиль, акцент на відчуттях і перевагах. Без кліше типу 'ідеальний вибір'. Тільки текст опису, без заголовка. Відповідай тільки українською.";
    }

    $body = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 300,
        'messages'   => [['role'=>'user','content'=>$prompt]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) err('cURL error: ' . $err, 500);
    $data = json_decode($resp, true);
    $text = $data['content'][0]['text'] ?? '';
    if (!$text) err('AI не відповів. Перевірте ключ.', 500);
    json_out(['ok' => true, 'text' => trim($text)]);
}

// ===================== АДМІН =====================

// --- Список товарів (адмін) ---
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

// --- Зберегти товар ---
if ($action === 'product_save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($input['id'] ?? 0);
    $data = [
        $input['name']         ?? '',
        $input['description']  ?? '',
        $input['care']         ?? '',
        $input['material']     ?? '',
        $input['manufacturer'] ?? '',
        (int)($input['price']     ?? 0),
        (int)($input['old_price'] ?? 0),
        json_encode($input['images'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($input['sizes']  ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($input['colors'] ?? [], JSON_UNESCAPED_UNICODE),
        $input['category']    ?? '',
        $input['badge']       ?? '',
        (int)($input['active']     ?? 1),
        (int)($input['sort_order'] ?? 0),
    ];
    if ($id) {
        $s = $db->prepare("UPDATE products SET name=?, description=?, care=?, material=?, manufacturer=?, price=?, old_price=?, images=?, sizes=?, colors=?, category=?, badge=?, active=?, sort_order=? WHERE id=?");
        $s->execute([...$data, $id]);
        json_out(['ok' => true, 'id' => $id]);
    } else {
        $s = $db->prepare("INSERT INTO products (name, description, care, material, manufacturer, price, old_price, images, sizes, colors, category, badge, active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $s->execute($data);
        json_out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }
}

// --- Видалити товар ---
if ($action === 'product_delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? 0);
    if (!$id) err('No id');
    $db->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
    json_out(['ok' => true]);
}

// --- Завантаження фото товару (конвертація у WebP) ---
if ($action === 'upload_image') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    if (!isset($_FILES['file'])) err('No file');
    $file = $_FILES['file'];
    $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!in_array($file['type'], $allowed_types)) err('Тільки зображення');
    if ($file['size'] > 10 * 1024 * 1024) err('Файл більше 10МБ');
    if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0755, true);

    $name = uniqid('p_') . '.webp';
    $dest = $uploads_dir . $name;

    // Спробуємо конвертувати у WebP через GD
    if (function_exists('imagewebp') && convert_to_webp($file['tmp_name'], $dest)) {
        @unlink($file['tmp_name']);
    } else {
        // Fallback: зберегти як є
        $ext  = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $name = uniqid('p_') . '.' . strtolower($ext);
        $dest = $uploads_dir . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) err('Помилка збереження файлу');
    }

    json_out(['ok' => true, 'url' => '/uploads/products/' . $name]);
}

// --- Категорії (адмін) ---
if ($action === 'category_save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id    = (int)($input['id'] ?? 0);
    $name  = trim($input['name'] ?? '');
    $image = trim($input['image'] ?? '');
    $sort  = (int)($input['sort_order'] ?? 0);
    if (!$name) err('Назва обов\'язкова');
    if ($id) {
        $db->prepare("UPDATE categories SET name=?, image=?, sort_order=? WHERE id=?")->execute([$name, $image, $sort, $id]);
        json_out(['ok' => true, 'id' => $id]);
    } else {
        $db->prepare("INSERT OR IGNORE INTO categories (name, image, sort_order) VALUES (?,?,?)")->execute([$name, $image, $sort]);
        json_out(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }
}

if ($action === 'category_delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? 0);
    if (!$id) err('No id');
    $db->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
    json_out(['ok' => true]);
}

// --- Замовлення (адмін) ---
if ($action === 'orders' && isset($_GET['admin'])) {
    $limit  = min((int)($_GET['limit'] ?? 20), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? '';
    if ($status) {
        $stmt = $db->prepare("SELECT * FROM orders_new WHERE status=? ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$status, $limit, $offset]);
    } else {
        $stmt = $db->prepare("SELECT * FROM orders_new ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['items'] = json_decode($r['items'] ?? '[]', true) ?? [];
    }
    $total_q = $status
        ? $db->prepare("SELECT COUNT(*) FROM orders_new WHERE status=?")
        : $db->prepare("SELECT COUNT(*) FROM orders_new");
    $status ? $total_q->execute([$status]) : $total_q->execute([]);
    json_out(['rows' => $rows, 'total' => (int)$total_q->fetchColumn()]);
}

// Зворотна сумісність: старий ендпоінт
if ($action === 'orders_list') {
    $limit  = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? '';
    if ($status) {
        $stmt = $db->prepare("SELECT * FROM orders_new WHERE status=? ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$status, $limit, $offset]);
    } else {
        $stmt = $db->prepare("SELECT * FROM orders_new ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['items'] = json_decode($r['items'] ?? '[]', true) ?? [];
        // Зворотна сумісність полів для старого UI
        $r['contact']  = ['name' => $r['customer_name'], 'phone' => $r['customer_phone'], 'email' => $r['customer_email']];
        $r['delivery'] = ['city' => $r['city'], 'address' => $r['address']];
    }
    $total_q = $status
        ? $db->prepare("SELECT COUNT(*) FROM orders_new WHERE status=?")
        : $db->prepare("SELECT COUNT(*) FROM orders_new");
    $status ? $total_q->execute([$status]) : $total_q->execute([]);
    json_out(['rows' => $rows, 'total' => (int)$total_q->fetchColumn()]);
}

if ($action === 'order_status') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id     = (int)($input['id'] ?? 0);
    $status = $input['status'] ?? '';
    $allowed = ['new','paid_partial','paid_full','processing','shipped','delivered','cancelled','returned'];
    if (!$id || !in_array($status, $allowed)) err('Invalid');
    $db->prepare("UPDATE orders_new SET status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$status, $id]);
    json_out(['ok' => true]);
}

// --- Відгуки (адмін) ---
if ($action === 'reviews_admin' && isset($_GET['admin'])) {
    $limit  = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $s = $db->prepare("SELECT r.*, p.name as product_name FROM reviews r LEFT JOIN products p ON p.id=r.product_id ORDER BY r.created_at DESC LIMIT ? OFFSET ?");
    $s->execute([$limit, $offset]);
    json_out($s->fetchAll(PDO::FETCH_ASSOC));
}

if ($action === 'review_approve') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $id       = (int)($input['id'] ?? 0);
    $approved = (int)(bool)($input['approved'] ?? false);
    if (!$id) err('No id');
    $db->prepare("UPDATE reviews SET approved=? WHERE id=?")->execute([$approved, $id]);
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

// --- Налаштування ---
if ($action === 'settings_get') {
    $rows = $db->query("SELECT key, value FROM settings WHERE key != 'np_api_key'")->fetchAll(PDO::FETCH_KEY_PAIR);
    json_out($rows);
}

if ($action === 'settings_set') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('POST only');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $s = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?,?)");
    foreach ($input as $k => $v) {
        if (!is_string($k) || !is_string((string)$v)) continue;
        $s->execute([$k, (string)$v]);
    }
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
            $is_locker = mb_stripos($b['name'] ?? '', 'поштомат', 0, 'UTF-8') !== false ? 1 : 0;
            $ins->execute([$b['uid'], $b['name'], $b['address'] ?? '', $b['city_uid'], $is_locker]);
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

// --- Статус бази ---
if ($action === 'status') {
    $cities   = (int)$db->query("SELECT COUNT(*) FROM cities")->fetchColumn();
    $branches = (int)$db->query("SELECT COUNT(*) FROM branches WHERE is_locker=0")->fetchColumn();
    $lockers  = (int)$db->query("SELECT COUNT(*) FROM branches WHERE is_locker=1")->fetchColumn();
    $streets  = (int)$db->query("SELECT COUNT(*) FROM streets")->fetchColumn();
    $updated  = @file_get_contents(__DIR__ . '/../data/updated.txt') ?: '';
    json_out(compact('cities','branches','lockers','streets','updated'));
}

// --- Пошук міст (Meest/Ukrposhta) ---
if ($action === 'cities') {
    $q     = mb_strtolower(trim($_GET['q'] ?? ''), 'UTF-8');
    $limit = min((int)($_GET['limit'] ?? 10), 50);
    if (strlen($q) < 2) json_out([]);
    $s = $db->prepare("SELECT uid, name, type FROM cities WHERE mb_lower(name) LIKE :q ORDER BY LENGTH(name) LIMIT :lim");
    $s->bindValue(':q',   '%' . $q . '%');
    $s->bindValue(':lim', $limit, PDO::PARAM_INT);
    $s->execute();
    json_out($s->fetchAll(PDO::FETCH_ASSOC));
}

// --- Відділення / поштомати (Meest) ---
if ($action === 'branches') {
    $city_uid  = $_GET['city_uid'] ?? '';
    $is_locker = isset($_GET['locker']) ? 1 : 0;
    if (!$city_uid) json_out([]);
    $s = $db->prepare("SELECT uid, name, address FROM branches WHERE city_uid=? AND is_locker=? ORDER BY name LIMIT 200");
    $s->execute([$city_uid, $is_locker]);
    json_out($s->fetchAll(PDO::FETCH_ASSOC));
}

// --- Пошук вулиць (Ukrposhta) ---
if ($action === 'streets') {
    $q        = mb_strtolower(trim($_GET['q'] ?? ''), 'UTF-8');
    $city_uid = $_GET['city_uid'] ?? '';
    $limit    = min((int)($_GET['limit'] ?? 10), 50);
    if (strlen($q) < 2 || !$city_uid) json_out([]);
    $s = $db->prepare("SELECT name FROM streets WHERE city_uid=:cu AND mb_lower(name) LIKE :q ORDER BY LENGTH(name) LIMIT :lim");
    $s->bindValue(':cu',  $city_uid);
    $s->bindValue(':q',   '%' . $q . '%');
    $s->bindValue(':lim', $limit, PDO::PARAM_INT);
    $s->execute();
    $rows = $s->fetchAll(PDO::FETCH_COLUMN);
    json_out(array_map(fn($n)=>['name'=>$n,'label'=>$n], $rows));
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
