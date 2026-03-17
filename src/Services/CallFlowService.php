<?php

declare(strict_types=1);

namespace Shafeeq\LogViewer\Services;

use Shafeeq\LogViewer\Models\LogEntry;

class CallFlowService
{
    /**
     * Build a call flow array from a LogEntry's stack trace.
     *
     * @return array<int, array{order: int, type: string, class: string, method: string, file: string, line: int, label: string}>
     */
    public function buildCallFlow(LogEntry $entry): array
    {
        $frames = $entry->stackTrace;

        if (empty($frames)) {
            return [];
        }

        // Separate app frames from vendor frames
        $appFrames    = [];
        $vendorFrames = [];

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? '';

            if ($this->isAppFrame($file, $frame['class'] ?? '')) {
                $appFrames[] = $frame;
            } else {
                $vendorFrames[] = $frame;
            }
        }

        // If no app frames, include the first vendor frame
        if (empty($appFrames) && !empty($vendorFrames)) {
            $appFrames = [$vendorFrames[0]];
        }

        $flow  = [];
        $order = 0;

        foreach ($appFrames as $frame) {
            $class  = $frame['class'] ?? '';
            $method = $frame['function'] ?? '';
            $file   = $frame['file'] ?? '';
            $line   = $frame['line'] ?? 0;

            $type  = $this->detectFrameType($class, $file);
            $label = $this->buildLabel($class, $method);

            $flow[] = [
                'order'  => $order++,
                'type'   => $type,
                'class'  => $class,
                'method' => $method,
                'file'   => $file,
                'line'   => $line,
                'label'  => $label,
            ];
        }

        return $flow;
    }

    /**
     * Parse a raw stack frame line.
     * Handles: #N /path/file.php(line): Class->method()
     */
    public function parseStackFrame(string $frameLine): array
    {
        $frameLine = trim($frameLine);

        if (preg_match('/^#(\d+)\s+(.+?)\((\d+)\):\s+(.+)$/', $frameLine, $m)) {
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
                'raw'      => $frameLine,
            ];
        }

        return [
            'frame'    => 0,
            'file'     => '',
            'line'     => 0,
            'class'    => '',
            'function' => $frameLine,
            'raw'      => $frameLine,
        ];
    }

    /**
     * Determine if a frame belongs to app code (not vendor).
     */
    private function isAppFrame(string $file, string $class): bool
    {
        if (str_contains($file, '/vendor/') || str_contains($file, '\\vendor\\')) {
            return false;
        }

        if (str_contains($class, 'Illuminate\\') || str_contains($class, 'Symfony\\')) {
            return false;
        }

        if (str_contains($file, '/app/') || str_contains($file, '\\app\\')) {
            return true;
        }

        if (str_contains($class, 'App\\')) {
            return true;
        }

        return false;
    }

    /**
     * Detect the frame type from class path.
     */
    private function detectFrameType(string $class, string $file): string
    {
        $path = $class . $file;

        if (str_contains($path, 'Controllers') || str_contains($path, 'Controller')) {
            return 'controller';
        }

        if (str_contains($path, '\\Services\\') || str_contains($path, '/Services/')) {
            return 'service';
        }

        if (str_contains($path, '\\Jobs\\') || str_contains($path, '/Jobs/')) {
            return 'job';
        }

        if (str_contains($path, '\\Observers\\') || str_contains($path, '/Observers/')) {
            return 'observer';
        }

        if (str_contains($path, '\\Middleware\\') || str_contains($path, '/Middleware/')) {
            return 'middleware';
        }

        if (str_contains($path, '\\Models\\') || str_contains($path, '/Models/')) {
            return 'model';
        }

        if (
            str_contains($path, '\\Events\\') || str_contains($path, '/Events/') ||
            str_contains($path, '\\Listeners\\') || str_contains($path, '/Listeners/')
        ) {
            return 'event';
        }

        if (str_contains($path, 'Console\\Commands') || str_contains($path, '/Commands/')) {
            return 'command';
        }

        return 'other';
    }

    /**
     * Build a short label for display.
     */
    private function buildLabel(string $class, string $method): string
    {
        if ($class === '') {
            return $method ?: 'unknown';
        }

        $parts = explode('\\', $class);
        $short = end($parts);

        if ($method !== '' && $method !== '{main}') {
            return $short . '->' . $method . '()';
        }

        return $short;
    }
}
