# Laravel Log Viewer

Advanced Log Viewer package for Laravel — a beautiful, feature-rich log inspection dashboard.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Security](#security)
- [UI Overview](#ui-overview)
- [REST API Reference](#rest-api-reference)
- [API Integration Guide](#api-integration-guide)
- [PHP Facade](#php-facade)
- [Extending the Package](#extending-the-package)
- [License](#license)

---

## Features

- Modern UI — Tailwind CSS, Alpine.js, Chart.js
- Log file navigator with file size and modification time
- Activity chart grouped by level (DEBUG / INFO / WARNING / ERROR / CRITICAL)
- Paginated, searchable, sortable, filterable log table
- Source type auto-detection: **JOB · REQUEST · COMMAND · SYSTEM**
- Call flow visualization extracted from stack traces
- SQL exception details — SQLSTATE, table, column, model, full query
- Full context JSON + stack trace in detail modal
- UTC + browser local timezone display
- Large file support (chunked `fseek` reads for GB-size files)
- Encrypted file tokens — full paths never exposed in API responses
- Configurable caching, sensitive data masking, middleware

---

## Requirements

- PHP **8.2+**
- Laravel **10** or **11**

---

## Installation

```bash
composer require shafeeq/laravel-log-viewer
```

The service provider is auto-discovered via Laravel's package discovery.

### Publish config

```bash
php artisan vendor:publish --tag=log-viewer-config
```

Creates `config/log-viewer.php` in your application.

### Publish views (optional — to customise the UI)

```bash
php artisan vendor:publish --tag=log-viewer-views
```

Copies views to `resources/views/vendor/log-viewer/`.

### Open the dashboard

```
http://your-app.test/log-viewer
```

---

## Configuration

`config/log-viewer.php`

| Key | Default | Description |
|-----|---------|-------------|
| `route_prefix` | `log-viewer` | URI prefix for all routes |
| `middleware` | `['web']` | Middleware stack applied to all routes |
| `auth_enabled` | `false` | Require authentication |
| `allowed_ips` | `[]` | Restrict access by IP (empty = allow all) |
| `max_file_size_mb` | `50` | Files larger than this are read from the end only |
| `per_page` | `50` | Default entries per page |
| `cache_enabled` | `true` | Cache parsed log entries |
| `cache_ttl` | `300` | Cache TTL in seconds |
| `hide_patterns` | `[]` | Array of regex patterns to mask sensitive values |
| `timezone` | `null` | Server-side display timezone (`null` = app timezone) |

---

## Security

### Restrict to authenticated admins

```php
// config/log-viewer.php
'middleware' => ['web', 'auth', 'can:view-logs'],
```

### Restrict by IP

```php
'allowed_ips' => ['127.0.0.1', '10.0.0.0/8'],
```

### Mask sensitive data

```php
'hide_patterns' => [
    '/password["\s:=]+[^\s,}]+/i',
    '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
    '/\d{4}-\d{4}-\d{4}-\d{4}/',   // credit card numbers
],
```

### Encrypted file tokens

All API endpoints accept an **encrypted file token** instead of a raw filesystem path.
The token is generated with Laravel's `Crypt::encryptString()` (AES-256-CBC + HMAC).
Full paths are **never** returned in any API response. Tampered or forged tokens return `422`.

---

## UI Overview

```
┌──────────────────────────────────────────────────────────────────┐
│ HEADER  Log Viewer — App Name          logs/laravel.log  1.2 MB  │
├───────────────┬──────────────────────────────────────────────────┤
│               │ Search │ Level ▾ │ Type ▾ │ Env │ Time Range ▾  │
│  SIDEBAR      ├──────────────────────────────────────────────────┤
│  Log Files    │  ACTIVITY CHART (Chart.js multi-line)            │
│               ├──────────────────────────────────────────────────┤
│  laravel.log  │  Timestamp UTC/Local │ Entrypoint │ Level │ Msg  │
│  worker.log   │  ...                                             │
│               │  PAGINATION                                       │
└───────────────┴──────────────────────────────────────────────────┘
```

Clicking any row opens a detail modal with:

- Full message
- SQL Info banner (table, column, model, query) for DB exceptions
- Call flow visualization — `Controller → Service → Job → Model`
- Context JSON (collapsible)
- Stack trace with highlighted app vs vendor frames (collapsible)
- Raw log line (collapsible)

---

## REST API Reference

All endpoints are prefixed with `/{route_prefix}` (default: `/log-viewer`).

All responses follow the envelope:

```json
{ "success": true, "data": { ... } }
{ "success": false, "message": "Error description" }
```

---

### GET `/log-viewer/api/files`

List all `.log` files from `storage/logs`.

**Response**

```json
{
  "success": true,
  "data": [
    {
      "name":           "laravel.log",
      "display_path":   "logs/laravel.log",
      "encrypted_path": "eyJpdiI6Ik...AES-256 token...==",
      "size":           204800,
      "size_human":     "200 KB",
      "modified_at":    "2026-03-17 10:45:00",
      "modified_human": "5m ago"
    }
  ]
}
```

> `encrypted_path` is required for all other endpoints. Never use `display_path` as a file identifier.

---

### GET `/log-viewer/api/entries`

Paginated log entries for a file.

**Query parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `file` | string | yes | `encrypted_path` from `/api/files` |
| `page` | integer | no | Page number (default: 1) |
| `per_page` | integer | no | Items per page (10–200, default: 50) |
| `level` | string | no | `DEBUG` `INFO` `NOTICE` `WARNING` `ERROR` `CRITICAL` `ALERT` `EMERGENCY` |
| `source_type` | string | no | `JOB` `REQUEST` `COMMAND` `SYSTEM` |
| `environment` | string | no | e.g. `production`, `local` |
| `search` | string | no | Full-text search on message (max 200 chars) |
| `date_from` | datetime | no | ISO date or `Y-m-d H:i:s` |
| `date_to` | datetime | no | ISO date or `Y-m-d H:i:s` |

**Response**

```json
{
  "success": true,
  "data": [
    {
      "id":              "a3f2c1d9...",
      "timestamp":       "2026-03-17T10:20:12+00:00",
      "timestamp_local": "2026-03-17 15:50:12",
      "level":           "ERROR",
      "environment":     "production",
      "source_type":     "JOB",
      "entrypoint":      "GenerateReportJob",
      "message":         "Payment failed for order #8821",
      "context":         { "order_id": 8821 },
      "stack_trace":     [],
      "sql_info":        {},
      "raw_line":        "[2026-03-17 10:20:12] production.ERROR: ..."
    }
  ],
  "meta": {
    "total":        521,
    "per_page":     50,
    "current_page": 1,
    "last_page":    11
  }
}
```

---

### GET `/log-viewer/api/entries/{id}`

Single log entry with full call flow.

**Query parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `file` | string | yes | `encrypted_path` |

**Response**

```json
{
  "success": true,
  "data": {
    "id":         "a3f2c1d9...",
    "level":      "ERROR",
    "message":    "Payment failed for order #8821",
    "sql_info": {
      "exception_type": "QueryException",
      "sqlstate":       "42S22",
      "error_message":  "Column not found",
      "sql":            "update `orders` set `status` = ? where `id` = ?",
      "table":          "orders",
      "column":         "status_flag",
      "model":          "Order",
      "operation":      "UPDATE"
    },
    "callFlow": [
      {
        "order":  0,
        "type":   "database",
        "class":  "App\\Models\\Order",
        "method": "UPDATE",
        "label":  "UPDATE `orders` — unknown column `status_flag`",
        "file":   "",
        "line":   0
      },
      {
        "order":  1,
        "type":   "seeder",
        "class":  "Database\\Seeders\\OrderSeeder",
        "method": "run",
        "label":  "OrderSeeder->run()",
        "file":   "/database/seeders/OrderSeeder.php",
        "line":   95
      }
    ],
    "stack_trace": [
      {
        "frame":    0,
        "file":     "/vendor/laravel/framework/src/Illuminate/Database/Connection.php",
        "line":     592,
        "class":    "PDO",
        "function": "prepare",
        "raw":      "#0 ..."
      }
    ]
  }
}
```

---

### GET `/log-viewer/api/chart`

Time-bucketed log counts for the activity chart.

**Query parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `file` | string | yes | `encrypted_path` |

**Response**

```json
{
  "success": true,
  "data": {
    "labels": ["14:20", "14:21", "14:22"],
    "datasets": [
      {
        "label":       "ERROR",
        "data":        [3, 0, 7],
        "borderColor": "#EF4444",
        "tension":     0.3,
        "fill":        false,
        "pointRadius": 2
      }
    ]
  }
}
```

---

### DELETE `/log-viewer/api/file`

Delete a log file permanently.

**Request body (JSON)**

```json
{ "file": "<encrypted_path>" }
```

**Headers required**

```
Content-Type: application/json
X-CSRF-TOKEN: <token>
```

**Response**

```json
{ "success": true, "message": "File deleted successfully." }
```

---

### GET `/log-viewer/api/download`

Download a log file as a text attachment.

**Query parameters**

| Parameter | Type | Required |
|-----------|------|----------|
| `file` | string | yes — `encrypted_path` |

Returns the raw log file with `Content-Disposition: attachment`.

---

## API Integration Guide

This section is for developers who want to build their **own frontend, CLI tool, mobile app, or monitoring service** on top of the log viewer API.

### Authentication

Add your chosen middleware in `config/log-viewer.php`:

```php
'middleware' => ['web', 'auth:sanctum'],
```

For machine-to-machine access, use Laravel Sanctum API tokens:

```bash
Authorization: Bearer <sanctum-token>
```

### Step 1 — Get the encrypted file token

You must always start by calling `/api/files` to get valid `encrypted_path` tokens.
Tokens are ephemeral — they change when the app key changes. Do not hardcode or store them.

```js
const res   = await fetch('/log-viewer/api/files');
const files = (await res.json()).data;
const token = files[0].encrypted_path;  // use this for all subsequent calls
```

### Step 2 — Fetch entries

```js
const params = new URLSearchParams({
    file:    token,
    page:    1,
    level:   'ERROR',
    search:  'payment failed',
});

const res  = await fetch('/log-viewer/api/entries?' + params);
const json = await res.json();

console.log(`${json.meta.total} entries found`);
json.data.forEach(entry => {
    console.log(`[${entry.level}] ${entry.timestamp} — ${entry.message}`);
});
```

### Step 3 — Fetch a single entry with call flow

```js
const res  = await fetch(`/log-viewer/api/entries/${entry.id}?file=${encodeURIComponent(token)}`);
const json = await res.json();

json.data.callFlow.forEach(step => {
    console.log(`${step.order}. [${step.type}] ${step.label}`);
});
```

### Step 4 — Build a chart

```js
const res  = await fetch(`/log-viewer/api/chart?file=${encodeURIComponent(token)}`);
const json = await res.json();

// json.data is ready to pass directly to Chart.js:
new Chart(ctx, {
    type: 'line',
    data: json.data,   // { labels: [...], datasets: [...] }
});
```

### PHP / Laravel integration example

```php
use Illuminate\Support\Facades\Http;

$base  = 'https://your-app.com/log-viewer';
$token = config('services.log_viewer_token'); // store in env

// List files
$files = Http::withToken($token)->get("{$base}/api/files")->json('data');
$encryptedPath = $files[0]['encrypted_path'];

// Get ERROR entries
$result = Http::withToken($token)->get("{$base}/api/entries", [
    'file'  => $encryptedPath,
    'level' => 'ERROR',
    'page'  => 1,
])->json();

foreach ($result['data'] as $entry) {
    logger()->info("Remote error: {$entry['message']}");
}
```

### cURL example

```bash
# Step 1: get file token
TOKEN=$(curl -s "https://your-app.com/log-viewer/api/files" \
  -H "Authorization: Bearer $SANCTUM_TOKEN" \
  | jq -r '.data[0].encrypted_path')

# Step 2: fetch latest errors
curl -s "https://your-app.com/log-viewer/api/entries" \
  -H "Authorization: Bearer $SANCTUM_TOKEN" \
  --data-urlencode "file=$TOKEN" \
  --data-urlencode "level=ERROR" \
  --data-urlencode "per_page=20" \
  | jq '.data[] | {level, message, entrypoint}'
```

### Custom frontend integration

If you want to build your own UI instead of using the built-in dashboard:

1. **Disable the default view** by pointing `route_prefix` to an internal-only prefix and building your own routes that proxy to the services.

2. **Use the PHP services directly** in your own controller:

```php
use Shafeeq\LogViewer\Services\LogReaderService;
use Shafeeq\LogViewer\Services\CallFlowService;

class MyLogController extends Controller
{
    public function __construct(
        private LogReaderService $reader,
        private CallFlowService  $callFlow,
    ) {}

    public function index()
    {
        $files = $this->reader->getLogFiles(storage_path('logs'));
        return view('my-logs', compact('files'));
    }

    public function entries(Request $request)
    {
        // Decrypt the token the user sent
        $filePath = $this->reader->decryptPath($request->input('file'));
        abort_if($filePath === null, 422, 'Invalid file token');

        $result = $this->reader->readEntries($filePath, [
            'level'  => $request->input('level'),
            'search' => $request->input('search'),
        ], $request->integer('page', 1));

        return response()->json($result);
    }

    public function show(Request $request, string $id)
    {
        $filePath = $this->reader->decryptPath($request->input('file'));
        abort_if($filePath === null, 422);

        $result = $this->reader->readEntries($filePath, [], 1, PHP_INT_MAX);

        foreach ($result['data'] as $entry) {
            if ($entry->id === $id) {
                return response()->json([
                    'entry'     => $entry->toArray(),
                    'callFlow'  => $this->callFlow->buildCallFlow($entry),
                ]);
            }
        }

        abort(404);
    }
}
```

### Error responses

All errors follow this shape:

```json
{ "success": false, "message": "Invalid or tampered file token." }
```

| HTTP Status | Meaning |
|-------------|---------|
| `422` | Invalid/tampered encrypted file token, or missing required parameter |
| `403` | Path outside `storage/logs` (directory traversal attempt blocked) |
| `404` | Log entry ID not found |
| `500` | Unexpected server error (message included) |

---

## PHP Facade

```php
use Shafeeq\LogViewer\Facades\LogViewer;

// List files (returns display_path + encrypted_path, never raw paths)
$files = LogViewer::getLogFiles(storage_path('logs'));

// Decrypt a token and read entries
$path    = LogViewer::decryptPath($encryptedPath);
$result  = LogViewer::readEntries($path, ['level' => 'ERROR'], page: 1);

foreach ($result['data'] as $entry) {
    echo "[{$entry->level}] {$entry->message}\n";
}
```

---

## Extending the Package

### Custom log parser

Bind your own parser in a service provider:

```php
use Shafeeq\LogViewer\Services\LogParserService;

$this->app->extend(LogParserService::class, function ($parser, $app) {
    return new MyCustomLogParser();
});
```

### Custom source type detection

Override `detectSourceType()` in a subclass and rebind:

```php
class MyLogParser extends LogParserService
{
    public function detectSourceType(\Shafeeq\LogViewer\Models\LogEntry $entry): string
    {
        if (str_contains($entry->message, 'Livewire')) {
            return 'LIVEWIRE';
        }
        return parent::detectSourceType($entry);
    }
}
```

---

## License

MIT
