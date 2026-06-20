<?php
// Запускати один раз для створення таблиць
$db_path = __DIR__ . '/../data/mereely.db';

$db = new PDO('sqlite:' . $db_path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("PRAGMA journal_mode=WAL");
$db->exec("PRAGMA synchronous=NORMAL");

$db->exec("
    CREATE TABLE IF NOT EXISTS cities (
        uid TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        type TEXT DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS idx_cities_name ON cities(name);

    CREATE TABLE IF NOT EXISTS branches (
        uid TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        address TEXT DEFAULT '',
        city_uid TEXT NOT NULL,
        is_locker INTEGER DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS idx_branches_city ON branches(city_uid, is_locker);

    CREATE TABLE IF NOT EXISTS streets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        city_uid TEXT NOT NULL,
        name TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_streets_city ON streets(city_uid);
    CREATE INDEX IF NOT EXISTS idx_streets_name ON streets(name);
");

echo "DB initialized OK\n";
echo "Path: $db_path\n";
