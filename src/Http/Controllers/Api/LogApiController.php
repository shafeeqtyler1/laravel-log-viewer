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
     * List all log files. Full paths are never returned — only display_path + encrypted_path.
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
            $encryptedFile = $request->input('file');
            $page          = (int) $request->input('page', 1);
            $perPage       = (int) $request->input('per_page', config('log-viewer.per_page', 50));

            if (empty($encryptedFile)) {
                // Auto-select first file
                $files = $this->reader->getLogFiles(storage_path('logs'));
                if (empty($files)) {
                    return response()->json([
                        'success' => true,
                        'data'    => [],
                        'meta'    => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
                    ]);
                }
                $encryptedFile = $files[0]['encrypted_path'];
            }

            $file = $this->resolveFilePath($encryptedFile);
            if ($file === null) {
                return $this->errorResponse('Invalid or tampered file token.', 422);
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
            $encryptedFile = $request->input('file');

            if (empty($encryptedFile)) {
                $files = $this->reader->getLogFiles(storage_path('logs'));
                if (empty($files)) {
                    return $this->errorResponse('No log file specified.', 404);
                }
                $encryptedFile = $files[0]['encrypted_path'];
            }

            $file = $this->resolveFilePath($encryptedFile);
            if ($file === null) {
                return $this->errorResponse('Invalid or tampered file token.', 422);
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
            $encryptedFile = $request->input('file');

            if (empty($encryptedFile)) {
                $files = $this->reader->getLogFiles(storage_path('logs'));
                if (empty($files)) {
                    return response()->json(['success' => true, 'data' => ['labels' => [], 'datasets' => []]]);
                }
                $encryptedFile = $files[0]['encrypted_path'];
            }

            $file = $this->resolveFilePath($encryptedFile);
            if ($file === null) {
                return $this->errorResponse('Invalid or tampered file token.', 422);
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
            $encryptedFile = $request->input('file');

            if (empty($encryptedFile)) {
                return $this->errorResponse('No file specified.', 422);
            }

            $file = $this->resolveFilePath($encryptedFile);
            if ($file === null) {
                return $this->errorResponse('Invalid or tampered file token.', 422);
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
            $encryptedFile = $request->input('file');

            if (empty($encryptedFile)) {
                return $this->errorResponse('No file specified.', 422);
            }

            $file = $this->resolveFilePath($encryptedFile);
            if ($file === null) {
                return $this->errorResponse('Invalid or tampered file token.', 422);
            }

            return $this->reader->downloadFile($file);
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Decrypt an encrypted file token and validate it resolves to a real,
     * safe path. Returns null when the token is invalid or tampered.
     */
    private function resolveFilePath(string $encryptedFile): ?string
    {
        $path = $this->reader->decryptPath($encryptedFile);

        if ($path === null) {
            return null;
        }

        // Double-check the decrypted path stays inside storage/logs
        $realPath    = realpath($path);
        $storageLogs = realpath(storage_path('logs'));

        if ($realPath === false || $storageLogs === false) {
            return null;
        }

        if (!str_starts_with($realPath, $storageLogs)) {
            return null;
        }

        return $realPath;
    }

    private function errorResponse(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
