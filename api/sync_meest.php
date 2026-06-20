<?php
// Запускати через cron: 0 4 * * * php /path/to/api/sync_meest.php >> /path/to/data/sync.log 2>&1
$zip_url  = 'https://meest-group.com/media/location/locations.zip';
$db_path  = __DIR__ . '/../data/mereely.db';
$tmp_zip  = sys_get_temp_dir() . '/meest_locations_' . getmypid() . '.zip';
$log_file = __DIR__ . '/../data/sync.log';

function log_msg($msg) {
    global $log_file;
    $line = '[' . date('d.m.Y H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($log_file, $line, FILE_APPEND);
    echo $line;
}

function parse_line($line) {
    return strpos($line, "\t") !== false ? explode("\t", $line) : explode(';', $line);
}

function is_uid($s) {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', trim((string)$s));
}

function decode_text($bytes) {
    $s = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
    if ($s !== false && mb_check_encoding($s, 'UTF-8')) {
        return ltrim($s, "\xEF\xBB\xBF");
    }
    return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1251');
}

log_msg('Починаємо синхронізацію Meest...');

// --- Завантаження ZIP ---
$ctx = stream_context_create(['http' => [
    'timeout'          => 120,
    'follow_location'  => true,
    'user_agent'       => 'Mozilla/5.0 (compatible; MeestSync/1.0)',
]]);

log_msg('Завантажую ' . $zip_url);
$bytes = @file_get_contents($zip_url, false, $ctx);
if (!$bytes || strlen($bytes) < 1000) {
    log_msg('ПОМИЛКА: не вдалося завантажити ZIP (отримано ' . strlen((string)$bytes) . ' байт)');
    exit(1);
}
file_put_contents($tmp_zip, $bytes);
log_msg('ZIP завантажено: ' . round(strlen($bytes) / 1024 / 1024, 1) . ' МБ');

// --- Розпакування через ZipArchive ---
$zip = new ZipArchive();
if ($zip->open($tmp_zip) !== true) {
    log_msg('ПОМИЛКА: не вдалося відкрити ZIP');
    @unlink($tmp_zip);
    exit(1);
}

$city_text   = null;
$branch_text = null;
$street_text = null;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (!preg_match('/\.txt$/i', $name)) continue;

    $raw  = $zip->getFromIndex($i);
    $text = decode_text($raw);
    $lines = array_filter(explode("\n", str_replace("\r", '', $text)));
    $data_lines = array_values(array_filter($lines, function($l) {
        $p = parse_line($l);
        return is_uid($p[0] ?? '');
    }));
    if (!$data_lines) continue;

    $p    = parse_line($data_lines[0]);
    $cols = count($p);
    $last_is_uid  = is_uid($p[$cols - 1] ?? '');
    $many_uids    = count(array_filter($p, 'is_uid')) >= 3;

    if ($cols >= 8 && $many_uids) {
        $city_text = $text;
        log_msg('Файл міст: ' . $name);
    } elseif ($cols === 4 && $last_is_uid) {
        $has_addr = false;
        foreach (array_slice($data_lines, 0, 30) as $dl) {
            $pp = parse_line($dl);
            if (isset($pp[2]) && preg_match('/\d/', $pp[2])) { $has_addr = true; break; }
        }
        if ($has_addr) {
            $branch_text = $text;
            log_msg('Файл відділень: ' . $name);
        }
    } elseif ($cols >= 10 && !$many_uids && $street_text === null) {
        $street_text = $text;
        log_msg('Файл вулиць: ' . $name);
    }
}
$zip->close();
@unlink($tmp_zip);

if (!$city_text)   { log_msg('ПОМИЛКА: файл міст не знайдено'); exit(1); }
if (!$branch_text) { log_msg('ПОМИЛКА: файл відділень не знайдено'); exit(1); }

// --- Підключення до БД ---
$db = new PDO('sqlite:' . $db_path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("PRAGMA synchronous=NORMAL");

// --- Міста ---
log_msg('Парсую міста...');
$cities = [];
foreach (explode("\n", str_replace("\r", '', $city_text)) as $line) {
    $p = parse_line(trim($line));
    if (!is_uid($p[0] ?? '')) continue;
    $cities[] = [trim($p[0]), trim($p[1] ?? ''), trim($p[3] ?? '')];
}
log_msg('Знайдено міст: ' . count($cities));

$db->exec('BEGIN');
$db->exec('DELETE FROM cities');
$ins = $db->prepare('INSERT OR REPLACE INTO cities (uid, name, type) VALUES (?,?,?)');
foreach ($cities as $c) $ins->execute($c);
$db->exec('COMMIT');
log_msg('Міста збережено');

// --- Відділення ---
log_msg('Парсую відділення...');
$branches = [];
foreach (explode("\n", str_replace("\r", '', $branch_text)) as $line) {
    $p = parse_line(trim($line));
    if (count($p) < 4 || !is_uid($p[0] ?? '') || !is_uid($p[count($p)-1] ?? '')) continue;
    $branches[] = [
        trim($p[0]),
        trim($p[1] ?? ''),
        trim($p[2] ?? ''),
        trim($p[count($p)-1]),
        (int)(mb_stripos($p[1] ?? '', 'поштомат', 0, 'UTF-8') !== false),
    ];
}
log_msg('Знайдено відділень: ' . count($branches));

$db->exec('BEGIN');
$db->exec('DELETE FROM branches');
$ins = $db->prepare('INSERT OR REPLACE INTO branches (uid, name, address, city_uid, is_locker) VALUES (?,?,?,?,?)');
foreach ($branches as $b) $ins->execute($b);
$db->exec('COMMIT');
log_msg('Відділення збережено');

// --- Вулиці ---
if ($street_text) {
    log_msg('Парсую вулиці...');
    $street_count = 0;
    $db->exec('BEGIN');
    $db->exec('DELETE FROM streets');
    $ins = $db->prepare('INSERT INTO streets (city_uid, name) VALUES (?,?)');
    foreach (explode("\n", str_replace("\r", '', $street_text)) as $line) {
        $p = parse_line(trim($line));
        if (count($p) < 6) continue;
        $city_uid = trim($p[5]);
        $sname    = trim($p[3]);
        $stype    = trim($p[1]);
        if (!$sname || $sname === '---') continue;
        $ins->execute([$city_uid, trim($stype . ' ' . $sname)]);
        $street_count++;
    }
    $db->exec('COMMIT');
    log_msg('Вулиць збережено: ' . $street_count);
}

// --- Дата оновлення ---
file_put_contents(__DIR__ . '/../data/updated.txt', date('d.m.Y H:i'));
log_msg('Готово! Оновлено: ' . date('d.m.Y H:i'));
