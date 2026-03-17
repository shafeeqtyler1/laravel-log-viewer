<?php

declare(strict_types=1);

namespace Shafeeq\LogViewer\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shafeeq\LogViewer\Services\CallFlowService;
use Shafeeq\LogViewer\Services\LogReaderService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LogApiController extends Controller
{
    public function __construct(
        private readonly LogReaderService $reader,
        private readonly CallFlowService  $callFlow
    ) {}

    /**
     * GET /api/files
     * List all log files.
     */
    public function files(): JsonResponse
    {
        try {
            $directory = storage_path('logs');
            $files     = $this->reader->getLogFiles($directory);

            return response()->json([
                'success' => true,
                'data'    => $files,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/entries
     * Get paginated log entries.
     */
    public function entries(Request $request): JsonResponse
    {
        try {
            $file    = $request->input('file');
            $page    = (int) $request->input('page', 1);
            $perPage = (int) $request->input('per_page', config('log-viewer.per_page', 50));

            if (empty($file)) {
                // Auto-select first file
                $files = $this->reader->getLogFiles(storage_path('logs'));
                if (empty($files)) {
                    return response()->json([
                        'success' => true,
                        'data'    => [],
                        'meta'    => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
                    ]);
                }
                $file = $files[0]['path'];
            }

            $filters = [
                'level'       => $request->input('level', ''),
                'source_type' => $request->input('source_type', ''),
                'search'      => $request->input('search', ''),
                'date_from'   => $request->input('date_from', ''),
                'date_to'     => $request->input('date_to', ''),
                'environment' => $request->input('environment', ''),
            ];

            $result = $this->reader->readEntries($file, $filters, $page, $perPage);

            $data = array_map(
                static fn ($entry) => $entry->toArray(),
                $result['data']
            );

            return response()->json([
                'success' => true,
                'data'    => $data,
                'meta'    => [
                    'total'        => $result['total'],
                    'per_page'     => $result['per_page'],
                    'current_page' => $result['current_page'],
                    'last_page'    => $result['last_page'],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/entries/{id}
     * Get a single log entry by ID, with call flow.
     */
    public function entry(Request $request, string $id): JsonResponse
    {
        try {
            $file = $request->input('file');

            if (empty($file)) {
                $files = $this->reader->getLogFiles(storage_path('logs'));
                if (empty($files)) {
                    return $this->errorResponse('No log file specified.', 404);
                }
                $file = $files[0]['path'];
            }

            // Read all entries and find by ID
            $result = $this->reader->readEntries($file, [], 1, PHP_INT_MAX);

            $found = null;
            foreach ($result['data'] as $entry) {
                if ($entry->id === $id) {
                    $found = $entry;
                    break;
                }
            }

            if ($found === null) {
                return $this->errorResponse('Log entry not found.', 404);
            }

            $entryData             = $found->toArray();
            $entryData['callFlow'] = $this->callFlow->buildCallFlow($found);

            return response()->json([
                'success' => true,
                'data'    => $entryData,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/chart
     * Get chart data for the selected file.
     */
    public function chart(Request $request): JsonResponse
    {
        try {
            $file = $request->input('file');

            if (empty($file)) {
                $files = $this->reader->getLogFiles(storage_path('logs'));
                if (empty($files)) {
                    return response()->json(['success' => true, 'data' => ['labels' => [], 'datasets' => []]]);
                }
                $file = $files[0]['path'];
            }

            $chartData = $this->reader->readForChart($file);

            return response()->json([
                'success' => true,
                'data'    => $chartData,
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * DELETE /api/file
     * Delete a log file.
     */
    public function delete(Request $request): JsonResponse
    {
        try {
            $file = $request->input('file');

            if (empty($file)) {
                return $this->errorResponse('No file specified.', 422);
            }

            $deleted = $this->reader->deleteFile($file);

            if (!$deleted) {
                return $this->errorResponse('Could not delete file.', 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'File deleted successfully.',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * GET /api/download
     * Download a log file.
     */
    public function download(Request $request): BinaryFileResponse|JsonResponse
    {
        try {
            $file = $request->input('file');

            if (empty($file)) {
                return $this->errorResponse('No file specified.', 422);
            }

            return $this->reader->downloadFile($file);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    private function errorResponse(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
