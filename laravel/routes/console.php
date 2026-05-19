<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\ScrapeSchedule;
use App\Models\ScrapeJob;
use App\Jobs\RunScraperJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

try {
    $schedules = ScrapeSchedule::where('is_active', true)->get();
    foreach ($schedules as $schedule) {
        Schedule::call(function () use ($schedule) {
            $days     = $schedule->days_back ?? 1;
            $fromDate = now()->subDays($days - 1)->format('d/m/Y');
            $toDate   = now()->format('d/m/Y');

            $job = ScrapeJob::create([
                'chat_id'     => $schedule->chat_id,
                'status'      => 'pending',
                'from_date'   => $fromDate,
                'to_date'     => $toDate,
                'max_records' => $schedule->max_records,
            ]);
            RunScraperJob::dispatch($job);
        })->cron($schedule->cron_expression);
    }
} catch (\Exception $e) {
    // Ignore DB errors when migrating
}
