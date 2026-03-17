<?php

declare(strict_types=1);

namespace Shafeeq\LogViewer\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LogFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name'           => $this->resource['name'],
            'path'           => $this->resource['path'],
            'size'           => $this->resource['size'],
            'size_human'     => $this->resource['size_human'],
            'modified_at'    => $this->resource['modified_at'],
            'modified_human' => $this->resource['modified_human'],
        ];
    }
}
