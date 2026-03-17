<?php

use Illuminate\Support\Facades\Route;
use Shafeeq\LogViewer\Http\Controllers\LogViewerController;
use Shafeeq\LogViewer\Http\Controllers\Api\LogApiController;

Route::prefix(config('log-viewer.route_prefix', 'log-viewer'))
    ->middleware(config('log-viewer.middleware', ['web']))
    ->group(function () {
        Route::get('/', [LogViewerController::class, 'index'])->name('log-viewer.index');
        Route::get('/api/files', [LogApiController::class, 'files'])->name('log-viewer.api.files');
        Route::get('/api/entries', [LogApiController::class, 'entries'])->name('log-viewer.api.entries');
        Route::get('/api/entries/{id}', [LogApiController::class, 'entry'])->name('log-viewer.api.entry');
        Route::get('/api/chart', [LogApiController::class, 'chart'])->name('log-viewer.api.chart');
        Route::delete('/api/file', [LogApiController::class, 'delete'])->name('log-viewer.api.delete');
        Route::get('/api/download', [LogApiController::class, 'download'])->name('log-viewer.api.download');
    });
