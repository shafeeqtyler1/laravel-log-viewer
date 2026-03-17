<?php

declare(strict_types=1);

namespace Shafeeq\LogViewer\Services;

use Shafeeq\LogViewer\Models\LogEntry;
use Carbon\Carbon;

class LogParserService
{
    /**
     * Main regex to match a Laravel log line header.
     * Group 1: datetime, Group 2: environment, Group 3: level, Group 4: message body
     */
    private const LOG_PATTERN = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)\] (\w+)\.(\w+): (.*)/s';

    /**
     * Parse a full log file text into an array of LogEntry objects.
     *
     * @param  string $rawText  Full file contents
     * @return LogEntry[]
     */
    public function parse(string $rawText): array
    {
        // Split the file into individual log entry blocks.
        // Each block starts with a bracketed timestamp [YYYY-
        $blocks = preg_split('/(?=^\[\d{4}-)/m', $rawText, -1, PREG_SPLIT_NO_EMPTY);

        if ($blocks === false) {
            return [];
        }

        $entries = [];
        $offset  = 0;

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                $offset += strlen($block) + 1;
                continue;
            }

            $entry = $this->parseLine($block);
            if ($entry !== null) {
                $entry->fileOffset = $offset;
                $entries[]         = $entry;
            }

            $offset += strlen($block) + 1;
        }

        return $entries;
    }

    /**
     * Parse a single log entry block (which may span multiple raw lines).
     */
    public function parseLine(string $rawLine): ?LogEntry
    {
        if (!preg_match(self::LOG_PATTERN, $rawLine, $matches)) {
            return null;
        }

        $datetimeStr = $matches[1];
        $environment = $matches[2];
        $level       = strtoupper($matches[3]);
        $body        = $matches[4];

        // Separate message from context JSON
        [$message, $context, $stackTrace] = $this->parseBody($body);

        // Apply hide patterns from config
        $hidePatterns = config('log-viewer.hide_patterns', []);
        foreach ($hidePatterns as $pattern) {
            try {
                $message = (string) preg_replace($pattern, '[HIDDEN]', $message);
            } catch (\Throwable) {
                // skip invalid regex
            }
        }

        // Build timestamps
        $appTimezone   = config('log-viewer.timezone') ?? config('app.timezone', 'UTC');
        $utcTimestamp  = $datetimeStr;
        $localTimestamp = $datetimeStr;

        try {
            $dt            = Carbon::createFromFormat('Y-m-d H:i:s', substr($datetimeStr, 0, 19), 'UTC');
            $utcTimestamp  = $dt->toIso8601String();
            $localTimestamp = $dt->setTimezone($appTimezone)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            // keep raw string if parsing fails
        }

        $entry                = new LogEntry();
        $entry->timestamp     = $utcTimestamp;
        $entry->timestampLocal = $localTimestamp;
        $entry->level         = $level;
        $entry->environment   = $environment;
        $entry->message       = trim($message);
        $entry->context       = $context;
        $entry->stackTrace    = $stackTrace;
        $entry->rawLine       = $rawLine;
        $entry->fileOffset    = 0;

        // Generate ID from key fields
        $entry->id = sha1($datetimeStr . $level . substr($entry->message, 0, 100));

        // Detect source type and entrypoint
        $entry->sourceType = $this->detectSourceType($entry);
        $entry->entrypoint = $this->detectEntrypoint($entry);

        // Extract SQL info for database exceptions
        $entry->sqlInfo = $this->extractSqlInfo($entry->message, $entry->stackTrace);

        return $entry;
    }

    /**
     * Parse the body portion of the log line to extract message, context JSON, and stack trace.
     *
     * @return array{0: string, 1: array, 2: array}
     */
    private function parseBody(string $body): array
    {
        $context    = [];
        $stackTrace = [];

        // Laravel sometimes wraps context + exception in JSON at end of message.
        // Pattern: message {context_json} {exception_json}
        // We'll try to extract trailing JSON objects.
        $message = $body;

        // Extract stack trace from exception JSON if present
        // Look for {"exception":"[object] ...","context":...} or {"exception":"..."}
        // First, try to find trailing JSON blobs
        $jsonStart = $this->findFirstJsonStart($message);

        if ($jsonStart !== false) {
            $jsonPart = substr($message, $jsonStart);
            $message  = rtrim(substr($message, 0, $jsonStart));

            // May have multiple JSON objects concatenated: {...} {...}
            $jsonObjects = $this->splitJsonObjects($jsonPart);

            foreach ($jsonObjects as $jsonStr) {
                $decoded = json_decode($jsonStr, true);
                if (is_array($decoded)) {
                    // Check if this is the exception object
                    if (isset($decoded['exception'])) {
                        $stackTrace = $this->parseExceptionJson($decoded['exception']);
                        // Remove exception key from context
                        unset($decoded['exception']);
                        if (!empty($decoded)) {
                            $context = array_merge($context, $decoded);
                        }
                    } else {
                        $context = array_merge($context, $decoded);
                    }
                }
            }
        }

        // Also parse inline stack trace blocks in the message body.
        // Handles both "[stacktrace]" and "Stack trace:\n#0 ..." (PHP native format).
        if (
            str_contains($message, '[stacktrace]') ||
            str_contains($message, "Stack trace:\n") ||
            str_contains($message, '#0 ')
        ) {
            [$message, $inlineTrace] = $this->extractInlineStackTrace($message);
            if (!empty($inlineTrace) && empty($stackTrace)) {
                $stackTrace = $inlineTrace;
            }
        }

        return [$message, $context, $stackTrace];
    }

    /**
     * Find the position of the first '{' that starts a JSON object, searching from right to left
     * for the outermost object boundary.
     */
    private function findFirstJsonStart(string $text): int|false
    {
        // Walk from end to find JSON objects
        $len   = strlen($text);
        $depth = 0;
        $start = false;

        for ($i = $len - 1; $i >= 0; $i--) {
            $ch = $text[$i];
            if ($ch === '}') {
                $depth++;
            } elseif ($ch === '{') {
                $depth--;
                if ($depth === 0) {
                    $start = $i;
                }
            }
        }

        return $start;
    }

    /**
     * Split a string that may contain multiple JSON objects back-to-back into individual JSON strings.
     */
    private function splitJsonObjects(string $text): array
    {
        $objects = [];
        $i       = 0;
        $len     = strlen($text);

        while ($i < $len) {
            // Skip whitespace
            while ($i < $len && ctype_space($text[$i])) {
                $i++;
            }
            if ($i >= $len || $text[$i] !== '{') {
                break;
            }

            $start = $i;
            $depth = 0;
            $inStr = false;
            $escape = false;

            while ($i < $len) {
                $ch = $text[$i];

                if ($escape) {
                    $escape = false;
                    $i++;
                    continue;
                }

                if ($ch === '\\' && $inStr) {
                    $escape = true;
                    $i++;
                    continue;
                }

                if ($ch === '"') {
                    $inStr = !$inStr;
                }

                if (!$inStr) {
                    if ($ch === '{') {
                        $depth++;
                    } elseif ($ch === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $objects[] = substr($text, $start, $i - $start + 1);
                            $i++;
                            break;
                        }
                    }
                }
                $i++;
            }
        }

        return $objects;
    }

    /**
     * Parse exception JSON string from Laravel log format:
     * "[object] (ExceptionClass(code: N): message at file.php:line)\n[stacktrace]\n#0 ..."
     */
    private function parseExceptionJson(string $exceptionStr): array
    {
        $frames = [];

        // Look for [stacktrace] section
        $tracePos = strpos($exceptionStr, '[stacktrace]');
        if ($tracePos === false) {
            $tracePos = strpos($exceptionStr, '#0 ');
        }

        if ($tracePos !== false) {
            $traceText = substr($exceptionStr, $tracePos);
            $frames    = $this->parseStackTraceText($traceText);
        }

        return $frames;
    }

    /**
     * Extract inline stack trace from message text.
     * Handles: "[stacktrace]", "Stack trace:\n", and bare "#0 " markers.
     * Also merges frames from chained exceptions ("Next ExceptionClass:").
     *
     * @return array{0: string, 1: array}
     */
    private function extractInlineStackTrace(string $text): array
    {
        // Candidate markers in priority order
        $candidates = [];

        $pos = strpos($text, '[stacktrace]');
        if ($pos !== false) {
            $candidates[] = $pos;
        }

        $pos = strpos($text, "Stack trace:\n");
        if ($pos !== false) {
            $candidates[] = $pos;
        }

        $pos = strpos($text, '#0 ');
        if ($pos !== false) {
            $candidates[] = $pos;
        }

        if (empty($candidates)) {
            return [$text, []];
        }

        $marker    = min($candidates);
        $message   = rtrim(substr($text, 0, $marker));
        $traceText = substr($text, $marker);

        // Collect all frame lines from ALL chained exceptions in the block.
        // Laravel logs chained exceptions with "Next ExceptionClass: ..." sections.
        $allFrames = $this->parseAllStackTraces($traceText);

        return [$message, $allFrames];
    }

    /**
     * Parse all stack trace frames from a text that may contain multiple
     * chained exception blocks separated by "Next ExceptionClass:".
     * Returns de-duplicated frames ordered by frame number.
     */
    private function parseAllStackTraces(string $text): array
    {
        // Split on "Next SomeClass:" boundaries so we capture every chain link
        $sections = preg_split('/\nNext\s+\S+:/m', $text, -1, PREG_SPLIT_NO_EMPTY);

        $seen   = [];
        $merged = [];

        foreach ($sections as $section) {
            $frames = $this->parseStackTraceText($section);
            foreach ($frames as $frame) {
                $key = ($frame['file'] ?? '') . ':' . ($frame['line'] ?? 0) . ':' . ($frame['function'] ?? '');
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $merged[]   = $frame;
                }
            }
        }

        return $merged;
    }

    /**
     * Parse a stack trace text block into structured frames.
     */
    private function parseStackTraceText(string $text): array
    {
        $frames = [];
        $lines  = explode("\n", $text);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === '[stacktrace]') {
                continue;
            }

            $frame = $this->parseStackFrame($line);
            if ($frame !== null) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    /**
     * Parse a single stack frame line like:
     * #0 /path/to/File.php(42): ClassName->method(args)
     * #0 {main}
     */
    public function parseStackFrame(string $line): ?array
    {
        // Match: #N /path/file.php(line): Class->method() or Class::method()
        if (preg_match('/^#(\d+)\s+(.+?)\((\d+)\):\s+(.+)$/', $line, $m)) {
            $classMethod = $m[4];
            $class       = '';
            $method      = '';

            if (preg_match('/^(.+?)(?:->|::)(\w+)\(/', $classMethod, $cm)) {
                $class  = $cm[1];
                $method = $cm[2];
            } else {
                $method = $classMethod;
            }

            return [
                'frame'    => (int) $m[1],
                'file'     => $m[2],
                'line'     => (int) $m[3],
                'class'    => $class,
                'function' => $method,
                'raw'      => $line,
            ];
        }

        // Match: #N {main}
        if (preg_match('/^#(\d+)\s+\{main\}/', $line, $m)) {
            return [
                'frame'    => (int) $m[1],
                'file'     => '{main}',
                'line'     => 0,
                'class'    => '',
                'function' => '{main}',
                'raw'      => $line,
            ];
        }

        return null;
    }

    /**
     * Detect the source type of a log entry (JOB, REQUEST, COMMAND, SYSTEM).
     */
    public function detectSourceType(LogEntry $entry): string
    {
        $message    = $entry->message;
        $stackTrace = $entry->stackTrace;
        $context    = $entry->context;

        // JOB detection
        if (
            str_contains($message, 'App\\Jobs\\') ||
            str_contains($message, 'App\Jobs\\') ||
            isset($context['job']) ||
            isset($context['jobId']) ||
            $this->stackContains($stackTrace, ['Jobs\\', '\\Jobs\\'])
        ) {
            return 'JOB';
        }

        // REQUEST detection
        if (
            preg_match('/\b(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\b/', $message) ||
            $this->stackContains($stackTrace, ['Controllers\\', '\\Http\\Controllers']) ||
            $this->stackContains($stackTrace, ['\\Middleware\\'])
        ) {
            return 'REQUEST';
        }

        // COMMAND detection
        if (
            str_contains(strtolower($message), 'artisan') ||
            $this->stackContains($stackTrace, ['Console\\Commands\\', 'Artisan\\']) ||
            isset($context['command']) ||
            ($entry->environment === 'artisan')
        ) {
            return 'COMMAND';
        }

        return 'SYSTEM';
    }

    /**
     * Detect the entrypoint label for a log entry.
     */
    public function detectEntrypoint(LogEntry $entry): string
    {
        $message    = $entry->message;
        $stackTrace = $entry->stackTrace;

        switch ($entry->sourceType) {
            case 'JOB':
                // Try to extract job class from message
                if (preg_match('/App\\\\Jobs\\\\(\w+)/', $message, $m)) {
                    return $m[1];
                }
                if (preg_match('/App\\\Jobs\\\\(\w+)/', $message, $m)) {
                    return $m[1];
                }
                // Try from stack trace
                foreach ($stackTrace as $frame) {
                    if (isset($frame['class']) && str_contains($frame['class'], 'Jobs\\')) {
                        $parts = explode('\\', $frame['class']);
                        return end($parts);
                    }
                }
                return 'Job';

            case 'REQUEST':
                // Try to extract HTTP method + path from message
                if (preg_match('/\b(GET|POST|PUT|PATCH|DELETE)\s+(\/[^\s]*)/', $message, $m)) {
                    return $m[1] . ' ' . $m[2];
                }
                // Try to get controller name from stack
                foreach ($stackTrace as $frame) {
                    if (isset($frame['class']) && str_contains($frame['class'], 'Controllers\\')) {
                        $parts = explode('\\', $frame['class']);
                        $cls   = end($parts);
                        $fn    = $frame['function'] ?? '';
                        return $fn ? $cls . '@' . $fn : $cls;
                    }
                }
                return 'Request';

            case 'COMMAND':
                // Try artisan command name
                if (preg_match('/artisan\s+([\w:]+)/', strtolower($message), $m)) {
                    return 'artisan ' . $m[1];
                }
                foreach ($stackTrace as $frame) {
                    if (isset($frame['class']) && str_contains($frame['class'], 'Commands\\')) {
                        $parts = explode('\\', $frame['class']);
                        return end($parts);
                    }
                }
                return 'artisan';

            case 'SYSTEM':
            default:
                // First app class from stack trace
                foreach ($stackTrace as $frame) {
                    if (isset($frame['class']) && str_contains($frame['class'], 'App\\')) {
                        $parts = explode('\\', $frame['class']);
                        return end($parts);
                    }
                }
                return 'System';
        }
    }

    /**
     * Extract SQL/database details from an exception message + stack trace.
     * Returns an empty array when the entry is not a SQL exception.
     *
     * @return array{exception_type:string, sqlstate:string, error_message:string, sql:string, table:string, column:string, model:string, operation:string}
     */
    public function extractSqlInfo(string $message, array $stackTrace): array
    {
        // Only process SQL-related exceptions
        if (
            !str_contains($message, 'SQLSTATE') &&
            !str_contains($message, 'QueryException') &&
            !str_contains($message, 'PDOException') &&
            !str_contains($message, 'PDO->') &&
            !str_contains($message, 'Database\\')
        ) {
            return [];
        }

        $info = [
            'exception_type' => '',
            'sqlstate'       => '',
            'error_message'  => '',
            'sql'            => '',
            'table'          => '',
            'column'         => '',
            'model'          => '',
            'operation'      => '',
        ];

        // Exception class name (first word ending in Exception)
        if (preg_match('/(\w*Exception)/', $message, $m)) {
            $info['exception_type'] = $m[1];
        }

        // SQLSTATE code
        if (preg_match('/SQLSTATE\[([^\]]+)\](?::\s*\w+\s+\w+:\s*\d+\s+)?([^(]+)?/', $message, $m)) {
            $info['sqlstate']      = $m[1];
            $info['error_message'] = trim($m[2] ?? '');
        }

        // SQL query embedded in QueryException: "SQL: <query>"
        if (preg_match('/\bSQL:\s+(.+?)(?:\s*\))?$/ms', $message, $m)) {
            $info['sql'] = trim($m[1]);
        }

        // Determine DML operation
        if (preg_match('/\b(select|insert|update|delete|replace)\b/i', $info['sql'] ?: $message, $m)) {
            $info['operation'] = strtoupper($m[1]);
        }

        // Table name from SQL (first backtick-quoted identifier)
        if (preg_match('/`([a-z_][a-z0-9_]*)`/i', $info['sql'] ?: $message, $m)) {
            $info['table'] = $m[1];
        }

        // Unknown column name
        if (preg_match("/Unknown column '([^']+)'/i", $message, $m)) {
            $info['column'] = $m[1];
        }

        // Affected model: look for App\Models\* in stack trace class names
        foreach ($stackTrace as $frame) {
            $class = $frame['class'] ?? '';
            if (preg_match('/App\\\\Models\\\\(\w+)/', $class, $m)) {
                $info['model'] = $m[1];
                break;
            }
        }

        // Fallback: derive model from table name (snake_case → PascalCase, singular)
        if ($info['model'] === '' && $info['table'] !== '') {
            $parts = explode('_', $info['table']);
            $parts = array_map('ucfirst', $parts);
            // Simple singular: strip trailing 's' if present (heuristic)
            $last = end($parts);
            if (strlen($last) > 1 && substr($last, -1) === 's') {
                $parts[count($parts) - 1] = substr($last, 0, -1);
            }
            $info['model'] = implode('', $parts);
        }

        return $info;
    }

    /**
     * Check if any frame in the stack trace contains any of the given needles.
     */
    private function stackContains(array $stackTrace, array $needles): bool
    {
        foreach ($stackTrace as $frame) {
            $classStr = ($frame['class'] ?? '') . ($frame['file'] ?? '');
            foreach ($needles as $needle) {
                if (str_contains($classStr, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
