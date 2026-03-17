<?php

declare(strict_types=1);

namespace Shafeeq\LogViewer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array getLogFiles(string $directory)
 * @method static array readEntries(string $filePath, array $filters = [], int $page = 1, int $perPage = 50)
 * @method static array readForChart(string $filePath)
 * @method static bool  deleteFile(string $filePath)
 *
 * @see \Shafeeq\LogViewer\Services\LogReaderService
 */
class LogViewer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'log-viewer';
    }
}
