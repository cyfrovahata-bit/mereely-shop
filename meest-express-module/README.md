# Meest Express Widget

Готовий модуль для підключення вибору відділень / поштоматів **Meest Express** на будь-який сайт.

Складається з двох частин:
- **`api/meest.php`** — серверна частина (PHP + SQLite), пошук міст, відділень, вулиць
- **`meest-widget.js`** — frontend-компонент (vanilla JS, без залежностей)

---

## Структура файлів

```
meest-express-module/
├── api/
│   └── meest.php        # PHP backend (SQLite)
├── meest-widget.js      # JS-віджет
├── example.html         # Приклад підключення
└── README.md
```

---

## Швидкий старт

### 1. Копіюйте файли в проєкт

```
your-project/
├── api/meest.php
├── data/                # директорія для SQLite (створіть, дайте write-права)
└── meest-widget.js
```

Переконайтесь що директорія `data/` доступна для запису PHP:
```bash
mkdir data && chmod 755 data
```

---

### 2. Підключіть на сторінці

```html
<div id="meest-root"></div>

<script src="/meest-widget.js"></script>
<script>
  const meest = new MeestWidget({
    container: document.getElementById('meest-root'),
    apiBase:   '/api/meest.php',
    onChange:  (result) => {
      if (result) {
        console.log('Обрано:', result.address);
        // result.mode       — 'branch' або 'locker'
        // result.city       — 'Львів'
        // result.cityUid    — UID міста в базі
        // result.branch     — 'Відділення №5, вул. ...'
        // result.branchUid  — UID відділення
        // result.address    — готовий рядок для замовлення
      }
    },
  });

  // Отримати поточний вибір вручну:
  const val = meest.getValue();

  // Скинути форму:
  meest.reset();
</script>
```

---

### 3. Завантажте дані відділень

База поставляється порожньою — потрібно завантажити актуальні дані з Meest.

**Варіант A — через адмінку вашого сайту:**
Використайте ендпоінт `POST /api/meest.php?action=upload` з даними формату:
```json
{ "type": "cities",   "cities":   [ { "uid": "...", "name": "Львів", "type": "місто" } ] }
{ "type": "branches", "branches": [ { "uid": "...", "name": "Відділення №1", "address": "вул. ...", "city_uid": "..." } ] }
```

**Варіант B — з marylee-shop (Marylee Shop):**
В адмінці є готовий інтерфейс: Налаштування → Meest → завантажити ZIP.

---

## API ендпоінти

| Method | action    | Параметри          | Опис                         |
|--------|-----------|---------------------|------------------------------|
| GET    | `status`  | —                   | кількість міст / відділень    |
| GET    | `cities`  | `q`, `limit`        | пошук міста за назвою        |
| GET    | `branches`| `city_uid`, `locker`| відділення або поштомати     |
| GET    | `streets` | `city_uid`, `q`     | вулиці міста (для кур'єра)   |
| POST   | `upload`  | JSON body           | завантаження даних           |

---

## Вбудований режим (без окремого файлу)

Якщо у вас вже є `api/index.php`, скопіюйте вміст `api/meest.php` після рядка `require` або вставте `action`-блоки всередину свого routing:

```php
// у вашому api/index.php
// $db вже оголошений вище
require_once __DIR__ . '/meest.php';
```

---

## Вимоги

- PHP 7.4+
- Розширення: `pdo_sqlite`, `mbstring`
- SQLite3

---

## Ліцензія

MIT — використовуйте вільно.
