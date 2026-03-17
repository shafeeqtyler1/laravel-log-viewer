<?php

declare(strict_types=1);

namespace Shafeeq\LogViewer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file'        => ['nullable', 'string'],
            'page'        => ['nullable', 'integer', 'min:1'],
            'per_page'    => ['nullable', 'integer', 'min:10', 'max:200'],
            'level'       => ['nullable', 'string', 'in:DEBUG,INFO,WARNING,ERROR,CRITICAL,NOTICE,ALERT,EMERGENCY'],
            'source_type' => ['nullable', 'string', 'in:JOB,REQUEST,COMMAND,SYSTEM'],
            'search'      => ['nullable', 'string', 'max:200'],
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date'],
            'environment' => ['nullable', 'string'],
        ];
    }
}
