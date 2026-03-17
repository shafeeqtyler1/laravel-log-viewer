<?php

declare(strict_types=1);

namespace Shafeeq\LogViewer\Services;

use Shafeeq\LogViewer\Models\LogEntry;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LogReaderService
{
    private const CHUNK_SIZE = 1048576; // 1 MB
    private const LARGE_FILE_THRESHOLD = 10485760; // 10 MB

    public function __construct(
        private readonly LogParserService $parser
    ) {}

    /**
     * Get all .log files from storage/logs directory.
     *
     * @return array<int, array{name: string, path: string, size: int, size_human: string, modified_at: string, modified_human: string}>
     */
    public function getLogFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files  = [];
        $handle = opendir($directory);

        if ($handle === false) {
            return [];
        }

        while (($file = readdir($handle)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $fullPath = $directory . DIRECTORY_SEPARATOR . $file;

            if (!is_file($fullPath) || !str_ends_with($file, '.log')) {
                continue;
            }

            $size     = filesize($fullPath);
            $modified = filemtime($fullPath);

            $files[] = [
                'name'           => $file,
                'path'           => $fullPath,
                'size'           => $size,
                'size_human'     => $this->formatBytes((int) $size),
                'modified_at'    => date('Y-m-d H:i:s', (int) $modified),
                'modified_human' => $this->humanDiff((int) $modified),
            ];
        }

        closedir($handle);

        // Sort by modified descending
        usort($files, static fn ($a, $b) => strcmp($b['modified_at'], $a['modified_at']));

        return $files;
    }

    /**
     * Read and paginate log entries from a file, with optional filtering.
     *
     * @param  array{level?: string, source_type?: string, search?: string, date_from?: string, date_to?: string, environment?: string} $filters
     * @return array{data: LogEntry[], total: int, per_page: int, current_page: int, last_page: int}
     */
    public function readEntries(string $filePath, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $cacheEnabled = config('log-viewer.cache_enabled', true);
        $cacheTtl     = (int) config('log-viewer.cache_ttl', 300);
        $cacheKey     = 'log_viewer_' . md5($filePath . filemtime($filePath));

        if ($cacheEnabled) {
            $allEntries = Cache::remember($cacheKey, $cacheTtl, fn () => $this->parseFile($filePath));
        } else {
            $allEntries = $this->parseFile($filePath);
        }

        // Apply filters
        $filtered = $this->applyFilters($allEntries, $filters);

        // Most recent first
        $filtered = array_reverse($filtered);

        $total    = count($filtered);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page     = max(1, min($page, $lastPage));
        $offset   = ($page - 1) * $perPage;
        $data     = array_slice($filtered, $offset, $perPage);

        return [
            'data'         => $data,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => $lastPage,
        ];
    }

    /**
     * Read file data grouped by minute/hour for charting.
     *
     * @return array{labels: string[], datasets: array<int, array{label: string, data: int[], borderColor: string}>}
     */
    public function readForChart(string $filePath): array
    {
        $cacheKey = 'log_viewer_chart_' . md5($filePath . filemtime($filePath));

        return Cache::remember($cacheKey, 60, function () use ($filePath) {
            $entries = $this->parseFile($filePath);

            if (empty($entries)) {
                return ['labels' => [], 'datasets' => []];
            }

            // Determine time span
            $timestamps = array_map(
                static fn (LogEntry $e) => strtotime(substr($e->timestamp, 0, 19)) ?: 0,
                $entries
            );

            $minTs = min($timestamps);
            $maxTs = max($timestamps);
            $span  = $maxTs - $minTs;

            // Choose bucket size: <2h → by minute, else by hour
            $bucketSeconds = $span < 7200 ? 60 : 3600;
            $format        = $bucketSeconds === 60 ? 'H:i' : 'Y-m-d H:00';

            // Build label buckets
            $labelsMap = [];
            for ($ts = $minTs; $ts <= $maxTs + $bucketSeconds; $ts += $bucketSeconds) {
                $label            = date($format, $ts);
                $labelsMap[$label] = $ts;
            }

            $labels = array_keys($labelsMap);

            $levels      = ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];
            $levelColors = [
                'DEBUG'    => '#6B7280',
                'INFO'     => '#3B82F6',
                'WARNING'  => '#F59E0B',
                'ERROR'    => '#EF4444',
                'CRITICAL' => '#B91C1C',
            ];

            // Initialize counts
            $counts = [];
            foreach ($levels as $lvl) {
                $counts[$lvl] = array_fill_keys($labels, 0);
            }

            foreach ($entries as $entry) {
                $ts     = strtotime(substr($entry->timestamp, 0, 19)) ?: 0;
                $bucket = date($format, (int) floor($ts / $bucketSeconds) * $bucketSeconds);
                $lvl    = strtoupper($entry->level);

                if (!isset($counts[$lvl])) {
                    $counts[$lvl] = array_fill_keys($labels, 0);
                }
                if (isset($counts[$lvl][$bucket])) {
                    $counts[$lvl][$bucket]++;
                }
            }

            $datasets = [];
            foreach ($levels as $lvl) {
                $data = array_values($counts[$lvl] ?? []);

                // Only include levels that have at least one entry
                if (array_sum($data) === 0) {
                    continue;
                }

                $datasets[] = [
                    'label'       => $lvl,
                    'data'        => $data,
                    'borderColor' => $levelColors[$lvl] ?? '#6B7280',
                    'tension'     => 0.3,
                    'fill'        => false,
                    'pointRadius' => 2,
                ];
            }

            return [
                'labels'   => $labels,
                'datasets' => $datasets,
            ];
        });
    }

    /**
     * Read a chunk of the file (for very large files).
     */
    public function readChunk(string $filePath, int $offset = 0, int $chunkSize = self::CHUNK_SIZE): string
    {
        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            return '';
        }

        if ($offset < 0) {
            // Read from end
            fseek($fh, $offset, SEEK_END);
        } else {
            fseek($fh, $offset);
        }

        $data = fread($fh, $chunkSize);
        fclose($fh);

        return $data !== false ? $data : '';
    }

    /**
     * Delete a log file.
     */
    public function deleteFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        // Security: ensure the file is inside storage/logs
        $this->assertSafePath($filePath);

        return unlink($filePath);
    }

    /**
     * Stream a log file as a download response.
     */
    public function downloadFile(string $filePath): BinaryFileResponse
    {
        $this->assertSafePath($filePath);

        return new BinaryFileResponse($filePath, 200, [
            'Content-Type'        => 'text/plain',
            'Content-Disposition' => 'attachment; filename="' . basename($filePath) . '"',
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Parse a log file into an array of LogEntry objects.
     *
     * @return LogEntry[]
     */
    private function parseFile(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [];
        }

        $fileSize  = filesize($filePath);
        $threshold = config('log-viewer.max_file_size_mb', 50) * 1048576;

        if ($fileSize === false) {
            return [];
        }

        if ($fileSize > $threshold) {
            // For very large files, only read the last portion
            $readBytes = min($fileSize, self::CHUNK_SIZE * 5); // up to 5MB from end
            $content   = $this->readChunk($filePath, -$readBytes);

            // Trim to first complete log entry (starts with '[')
            $firstBracket = strpos($content, '[');
            if ($firstBracket !== false) {
                $content = substr($content, $firstBracket);
            }
        } else {
            $content = file_get_contents($filePath);
            if ($content === false) {
                return [];
            }
        }

        return $this->parser->parse($content);
    }

    /**
     * Apply filters to an array of LogEntry objects.
     *
     * @param  LogEntry[]                                                                                    $entries
     * @param  array{level?: string, source_type?: string, search?: string, date_from?: string, date_to?: string, environment?: string} $filters
     * @return LogEntry[]
     */
    private function applyFilters(array $entries, array $filters): array
    {
        $level       = strtoupper($filters['level'] ?? '');
        $sourceType  = strtoupper($filters['source_type'] ?? '');
        $search      = strtolower($filters['search'] ?? '');
        $dateFrom    = $filters['date_from'] ?? '';
        $dateTo      = $filters['date_to'] ?? '';
        $environment = $filters['environment'] ?? '';

        return array_filter($entries, static function (LogEntry $entry) use (
            $level,
            $sourceType,
            $search,
            $dateFrom,
            $dateTo,
            $environment
        ): bool {
            if ($level !== '' && strtoupper($entry->level) !== $level) {
                return false;
            }

            if ($sourceType !== '' && strtoupper($entry->sourceType) !== $sourceType) {
                return false;
            }

            if ($environment !== '' && strtolower($entry->environment) !== strtolower($environment)) {
                return false;
            }

            if ($search !== '' && !str_contains(strtolower($entry->message), $search)) {
                return false;
            }

            if ($dateFrom !== '') {
                $ts = strtotime(substr($entry->timestamp, 0, 19));
                if ($ts !== false && $ts < strtotime($dateFrom)) {
                    return false;
                }
            }

            if ($dateTo !== '') {
                $ts = strtotime(substr($entry->timestamp, 0, 19));
                if ($ts !== false && $ts > strtotime($dateTo)) {
                    return false;
                }
            }

            return true;
        });
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    private function humanDiff(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return (int) ($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return (int) ($diff / 3600) . 'h ago';
        }
        return (int) ($diff / 86400) . 'd ago';
    }

    /**
     * Ensure the file path is within the storage/logs directory to prevent directory traversal.
     */
    private function assertSafePath(string $filePath): void
    {
        $realPath    = realpath($filePath);
        $storageLogs = realpath(storage_path('logs'));

        if ($realPath === false || $storageLogs === false) {
            abort(403, 'Invalid file path.');
        }

        if (!str_starts_with($realPath, $storageLogs)) {
            abort(403, 'Access denied: path outside storage/logs.');
        }
    }
}
