<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        LogViewer::auth(function ($request) {
            if (filter_var(config('app.log_viewer_public', false), FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }

            $allowedIps = array_filter(array_map(
                'trim',
                explode(',', (string) config('app.log_viewer_allowed_ips', ''))
            ));

            return in_array($request->ip(), $allowedIps, true);
        });
    }
}