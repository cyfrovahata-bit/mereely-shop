<?php
// Запускати для додавання нових таблиць (безпечно запускати повторно)
$db_path = __DIR__ . '/../data/mereely.db';

$db = new PDO('sqlite:' . $db_path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("PRAGMA synchronous=NORMAL");

$db->exec("
    CREATE TABLE IF NOT EXISTS settings (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    );

    CREATE TABLE IF NOT EXISTS products (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT NOT NULL DEFAULT '',
        description TEXT NOT NULL DEFAULT '',
        price      INTEGER NOT NULL DEFAULT 0,
        old_price  INTEGER NOT NULL DEFAULT 0,
        images     TEXT NOT NULL DEFAULT '[]',
        sizes      TEXT NOT NULL DEFAULT '[]',
        colors     TEXT NOT NULL DEFAULT '[]',
        category   TEXT NOT NULL DEFAULT '',
        active     INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_products_active ON products(active, sort_order);

    CREATE TABLE IF NOT EXISTS orders (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        contact    TEXT NOT NULL DEFAULT '{}',
        delivery   TEXT NOT NULL DEFAULT '{}',
        items      TEXT NOT NULL DEFAULT '[]',
        total      INTEGER NOT NULL DEFAULT 0,
        status     TEXT NOT NULL DEFAULT 'new',
        note       TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status, created_at);

    CREATE TABLE IF NOT EXISTS subscribers (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        email      TEXT NOT NULL UNIQUE,
        name       TEXT NOT NULL DEFAULT '',
        active     INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_subscribers_email ON subscribers(email);
");

echo "Migration OK\n";
echo "Path: $db_path\n";
