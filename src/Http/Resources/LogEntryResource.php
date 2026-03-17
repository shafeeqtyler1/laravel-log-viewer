<?php

declare(strict_types=1);

namespace Shafeeq\LogViewer\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Shafeeq\LogViewer\Models\LogEntry;

/**
 * @property LogEntry $resource
 */
class LogEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var LogEntry $entry */
        $entry = $this->resource;

        return [
            'id'              => $entry->id,
            'timestamp'       => $entry->timestamp,
            'timestamp_local' => $entry->timestampLocal,
            'level'           => $entry->level,
            'environment'     => $entry->environment,
            'source_type'     => $entry->sourceType,
            'entrypoint'      => $entry->entrypoint,
            'message'         => $entry->message,
            'context'         => $entry->context,
            'stack_trace'     => $entry->stackTrace,
            'file_offset'     => $entry->fileOffset,
        ];
    }
}
