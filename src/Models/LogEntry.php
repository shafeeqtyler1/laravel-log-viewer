<?php

declare(strict_types=1);

namespace Shafeeq\LogViewer\Models;

class LogEntry
{
    public string $id;
    public string $timestamp;
    public string $timestampLocal;
    public string $level;
    public string $environment;
    public string $sourceType;
    public string $entrypoint;
    public string $message;
    public array  $context;
    public array  $stackTrace;
    public array  $sqlInfo;      // populated for SQL/DB exceptions
    public string $rawLine;
    public int    $fileOffset;

    public function __construct()
    {
        $this->id             = '';
        $this->timestamp      = '';
        $this->timestampLocal = '';
        $this->level          = '';
        $this->environment    = '';
        $this->sourceType     = 'SYSTEM';
        $this->entrypoint     = '';
        $this->message        = '';
        $this->context        = [];
        $this->stackTrace     = [];
        $this->sqlInfo        = [];
        $this->rawLine        = '';
        $this->fileOffset     = 0;
    }

    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'timestamp'       => $this->timestamp,
            'timestamp_local' => $this->timestampLocal,
            'level'           => $this->level,
            'environment'     => $this->environment,
            'source_type'     => $this->sourceType,
            'entrypoint'      => $this->entrypoint,
            'message'         => $this->message,
            'context'         => $this->context,
            'stack_trace'     => $this->stackTrace,
            'sql_info'        => $this->sqlInfo,
            'raw_line'        => $this->rawLine,
            'file_offset'     => $this->fileOffset,
        ];
    }

    public static function fromArray(array $data): self
    {
        $entry                = new self();
        $entry->id            = $data['id'] ?? '';
        $entry->timestamp     = $data['timestamp'] ?? '';
        $entry->timestampLocal = $data['timestamp_local'] ?? '';
        $entry->level         = $data['level'] ?? '';
        $entry->environment   = $data['environment'] ?? '';
        $entry->sourceType    = $data['source_type'] ?? 'SYSTEM';
        $entry->entrypoint    = $data['entrypoint'] ?? '';
        $entry->message       = $data['message'] ?? '';
        $entry->context       = $data['context'] ?? [];
        $entry->stackTrace    = $data['stack_trace'] ?? [];
        $entry->sqlInfo       = $data['sql_info'] ?? [];
        $entry->rawLine       = $data['raw_line'] ?? '';
        $entry->fileOffset    = $data['file_offset'] ?? 0;

        return $entry;
    }
}
