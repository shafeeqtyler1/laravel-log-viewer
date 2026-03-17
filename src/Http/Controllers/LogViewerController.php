<?php

declare(strict_types=1);

namespace Shafeeq\LogViewer\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;

class LogViewerController extends Controller
{
    public function index(): View
    {
        $config = [
            'route_prefix'   => config('log-viewer.route_prefix', 'log-viewer'),
            'per_page'       => config('log-viewer.per_page', 50),
            'auth_enabled'   => config('log-viewer.auth_enabled', false),
            'cache_enabled'  => config('log-viewer.cache_enabled', true),
            'app_name'       => config('app.name', 'Laravel'),
            'app_timezone'   => config('log-viewer.timezone') ?? config('app.timezone', 'UTC'),
        ];

        return view('log-viewer::index', compact('config'));
    }
}
