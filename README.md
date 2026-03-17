# Laravel Log Viewer

Advanced Log Viewer package for Laravel — a beautiful, feature-rich log inspection dashboard.

## Features

- Beautiful, modern UI (Tailwind CSS + Alpine.js + Chart.js)
- Log file navigator with file size and modification time
- Activity chart (multi-level, time-bucketed)
- Paginated, searchable, filterable log table
- Source type detection: JOB, REQUEST, COMMAND, SYSTEM
- Call flow visualization from stack traces
- Full context + stack trace display in modal
- Large file support (reads from end using fseek)
- Configurable caching, sensitive data masking, middleware

## Requirements

- PHP 8.2+
- Laravel 10 or 11

## Installation

```bash
composer require shafeeq/laravel-log-viewer
```

The service provider is auto-discovered via Laravel's package discovery.

## Publish Config

```bash
php artisan vendor:publish --tag=log-viewer-config
```

This creates `config/log-viewer.php` in your application.

## Publish Views

```bash
php artisan vendor:publish --tag=log-viewer-views
```

This copies views to `resources/views/vendor/log-viewer/`.

## Usage

Visit `/log-viewer` in your browser.

## Configuration

Key options in `config/log-viewer.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `route_prefix` | `log-viewer` | URI prefix for all routes |
| `middleware` | `['web']` | Middleware applied to routes |
| `auth_enabled` | `false` | Require authentication |
| `allowed_ips` | `[]` | Restrict by IP (empty = all) |
| `max_file_size_mb` | `50` | Max file size before chunked reading |
| `per_page` | `50` | Default entries per page |
| `cache_enabled` | `true` | Enable parsed entry caching |
| `cache_ttl` | `300` | Cache TTL in seconds |
| `hide_patterns` | `[]` | Regex patterns to mask sensitive data |
| `timezone` | `null` | Display timezone (null = app timezone) |

## Security

To protect the log viewer in production, set `auth_enabled => true` or add custom middleware:

```php
// config/log-viewer.php
'middleware' => ['web', 'auth', 'can:view-logs'],
```

## API Endpoints

All endpoints are prefixed by `route_prefix` (default: `/log-viewer`):

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/files` | List log files |
| GET | `/api/entries` | Paginated entries with filters |
| GET | `/api/entries/{id}` | Single entry with call flow |
| GET | `/api/chart` | Chart data |
| DELETE | `/api/file` | Delete a log file |
| GET | `/api/download` | Download a log file |

## Facade

```php
use Shafeeq\LogViewer\Facades\LogViewer;

$files   = LogViewer::getLogFiles(storage_path('logs'));
$entries = LogViewer::readEntries('/path/to/laravel.log', ['level' => 'ERROR']);
```

## License

MIT
