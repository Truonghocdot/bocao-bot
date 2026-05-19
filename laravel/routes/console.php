<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\File;
use App\Models\ScrapeSchedule;
use App\Models\ScrapeJob;
use App\Jobs\RunScraperJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('scraper:clean {--hours= : Delete files older than this many hours} {--active-grace-minutes= : Treat recently stopped jobs as active for this many minutes} {--dry-run : Show files without deleting}', function () {
    $hours = (int) ($this->option('hours') ?: env('SCRAPER_CLEANUP_HOURS', 24));
    $activeGraceMinutes = (int) ($this->option('active-grace-minutes') ?: env('SCRAPER_ACTIVE_GRACE_MINUTES', 30));
    $dryRun = (bool) $this->option('dry-run');

    if ($hours < 1) {
        $this->error('The --hours option must be at least 1.');
        return 1;
    }

    if ($activeGraceMinutes < 1) {
        $this->error('The --active-grace-minutes option must be at least 1.');
        return 1;
    }

    $activeJobs = ScrapeJob::query()
        ->whereIn('status', ['pending', 'processing'])
        ->orWhere(function ($query) use ($activeGraceMinutes) {
            $query->whereIn('status', ['stopped', 'failed'])
                ->whereNull('delivered_at')
                ->where('updated_at', '>=', now()->subMinutes($activeGraceMinutes));
        })
        ->latest()
        ->get();

    $unknownActiveJobs = $activeJobs->filter(fn (ScrapeJob $job) => ! $job->download_dir && ! $job->zip_path);
    if ($unknownActiveJobs->isNotEmpty()) {
        $this->warn('Skip cleanup because old active job(s) do not have download tracking yet.');

        $unknownActiveJobs->each(function (ScrapeJob $job) {
            $range = $job->from_date ? "{$job->from_date} -> {$job->to_date}" : 'no date range';
            $zip = $job->zip_path ?: 'no zip yet';
            $this->line("#{$job->id} {$job->status} {$range}; zip: {$zip}");
        });

        return 0;
    }

    if ($activeJobs->isNotEmpty()) {
        $this->line("Protecting {$activeJobs->count()} active job(s) during cleanup.");
    }

    $activeSchedules = ScrapeSchedule::query()
        ->where('is_active', true)
        ->count();

    if ($activeSchedules > 0) {
        $this->line("Active schedule(s): {$activeSchedules}. No running job found, cleanup can continue.");
    }

    $cutoff = now()->subHours($hours)->getTimestamp();
    $protectedPaths = $activeJobs
        ->flatMap(fn (ScrapeJob $job) => [$job->download_dir, $job->zip_path])
        ->filter()
        ->map(fn (string $path) => realpath($path) ?: $path)
        ->all();
    $isProtectedPath = fn (string $path): bool => collect($protectedPaths)
        ->contains(fn (string $protectedPath) => $path === $protectedPath || str_starts_with($path, $protectedPath . DIRECTORY_SEPARATOR));

    $targets = [
        base_path('../scraper/downloads'),
        base_path('../storage/zips'),
        base_path('../storage/errors'),
    ];

    $deletedFiles = 0;
    $deletedBytes = 0;
    $matchedFiles = 0;

    foreach ($targets as $target) {
        if (! File::isDirectory($target)) {
            $this->line("Skip missing directory: {$target}");
            continue;
        }

        foreach (File::allFiles($target) as $file) {
            $pathname = $file->getPathname();
            $realPath = $file->getRealPath() ?: $pathname;

            if ($isProtectedPath($realPath)) {
                $this->line("Skip protected file: {$pathname}");
                continue;
            }

            if ($file->getMTime() > $cutoff) {
                continue;
            }

            $matchedFiles++;
            $deletedBytes += $file->getSize();

            if ($dryRun) {
                $this->line("[dry-run] {$pathname}");
                continue;
            }

            File::delete($pathname);
            $deletedFiles++;
        }

        if (! $dryRun) {
            collect(File::directories($target))
                ->sortByDesc(fn (string $dir) => substr_count($dir, DIRECTORY_SEPARATOR))
                ->each(function (string $dir) use ($isProtectedPath) {
                    $realDir = realpath($dir) ?: $dir;
                    if ($isProtectedPath($realDir)) {
                        return;
                    }

                    if (File::isDirectory($dir) && count(File::files($dir)) === 0 && count(File::directories($dir)) === 0) {
                        File::deleteDirectory($dir);
                    }
                });
        }
    }

    $sizeMb = number_format($deletedBytes / 1024 / 1024, 2);
    $verb = $dryRun ? 'Matched' : 'Deleted';
    $count = $dryRun ? $matchedFiles : $deletedFiles;

    $this->info("{$verb} {$count} old file(s), {$sizeMb} MB.");
    return 0;
})->purpose('Clean old scraper downloads, ZIP files, and error screenshots');

Schedule::command('scraper:clean')->everyTwoHours();

try {
    $schedules = ScrapeSchedule::where('is_active', true)->get();
    foreach ($schedules as $schedule) {
        Schedule::call(function () use ($schedule) {
            $days     = $schedule->days_back ?? 1;
            $fromDate = now()->subDays($days - 1)->format('d/m/Y');
            $toDate   = now()->format('d/m/Y');

            $job = ScrapeJob::create([
                'chat_id'     => $schedule->chat_id,
                'target_chat_id' => $schedule->target_chat_id ?: $schedule->chat_id,
                'scrape_schedule_id' => $schedule->id,
                'status'      => 'pending',
                'from_date'   => $fromDate,
                'to_date'     => $toDate,
                'max_records' => $schedule->max_records,
            ]);

            $downloadKey = now()->format('Ymd-His') . "-job-{$job->id}";
            $downloadDir = base_path("../scraper/downloads/{$downloadKey}");

            $job->update([
                'download_key' => $downloadKey,
                'download_dir' => $downloadDir,
            ]);

            $schedule->update([
                'last_download_key' => $downloadKey,
                'last_download_dir' => $downloadDir,
            ]);

            RunScraperJob::dispatch($job);
        })->cron($schedule->cron_expression);
    }
} catch (\Exception $e) {
    // Ignore DB errors when migrating
}
