<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LogViewer::auth(function ($request) {
            if ((bool) env('LOG_VIEWER_PUBLIC', false)) {
                return true;
            }

            $allowedIps = array_filter(array_map(
                'trim',
                explode(',', (string) env('LOG_VIEWER_ALLOWED_IPS', ''))
            ));

            return in_array($request->ip(), $allowedIps, true);
        });
    }
}
