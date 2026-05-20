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

Artisan::command('scraper:clean {--hours= : Delete files for finished jobs older than this many hours} {--dry-run : Show files without deleting}', function () {
    $hours = (int) ($this->option('hours') ?: env('SCRAPER_CLEANUP_HOURS', 2));
    $dryRun = (bool) $this->option('dry-run');

    if ($hours < 1) {
        $this->error('The --hours option must be at least 1.');
        return 1;
    }

    $cutoff = now()->subHours($hours);
    $finishedStatuses = ['completed', 'failed', 'stopped'];
    $deletedFiles = 0;
    $deletedDirectories = 0;
    $deletedBytes = 0;
    $matchedPaths = 0;
    $downloadRoot = realpath(base_path('../scraper/downloads')) ?: base_path('../scraper/downloads');
    $zipRoot = realpath(base_path('../storage/zips')) ?: base_path('../storage/zips');
    $isAllowedCleanupPath = function (string $path) use ($downloadRoot, $zipRoot): bool {
        $realPath = realpath($path) ?: $path;

        return str_starts_with($realPath, $downloadRoot . DIRECTORY_SEPARATOR)
            || str_starts_with($realPath, $zipRoot . DIRECTORY_SEPARATOR);
    };

    $jobs = ScrapeJob::query()
        ->whereIn('status', $finishedStatuses)
        ->where(function ($query) use ($cutoff) {
            $query->where(function ($query) use ($cutoff) {
                $query->whereNotNull('delivered_at')
                    ->where('delivered_at', '<=', $cutoff);
            })->orWhere(function ($query) use ($cutoff) {
                $query->whereNull('delivered_at')
                    ->where('updated_at', '<=', $cutoff);
            });
        })
        ->where(function ($query) {
            $query->whereNotNull('download_dir')
                ->orWhereNotNull('download_key')
                ->orWhereNotNull('zip_path');
        })
        ->oldest('updated_at')
        ->get();

    if ($jobs->isEmpty()) {
        $this->info("No finished job files older than {$hours} hour(s) found.");
        return 0;
    }

    $this->line("Found {$jobs->count()} finished job(s) older than {$hours} hour(s).");

    foreach ($jobs as $job) {
        $paths = collect([
            $job->download_dir,
            $job->download_key ? base_path("../scraper/downloads/{$job->download_key}") : null,
            $job->zip_path,
        ])
            ->filter()
            ->unique()
            ->values();

        foreach ($paths as $path) {
            if (! File::exists($path)) {
                continue;
            }

            if (! $isAllowedCleanupPath($path)) {
                $this->warn("Skip unsafe cleanup path for job #{$job->id}: {$path}");
                continue;
            }

            $matchedPaths++;

            if (File::isDirectory($path)) {
                $fileCount = count(File::allFiles($path));
                $bytes = collect(File::allFiles($path))->sum(fn ($file) => $file->getSize());
                $deletedBytes += $bytes;

                if ($dryRun) {
                    $this->line("[dry-run] job #{$job->id} directory {$path} ({$fileCount} file(s))");
                    continue;
                }

                File::deleteDirectory($path);
                $deletedDirectories++;
                $deletedFiles += $fileCount;
                continue;
            }

            if (! File::isFile($path)) {
                continue;
            }

            $deletedBytes += File::size($path);

            if ($dryRun) {
                $this->line("[dry-run] job #{$job->id} file {$path}");
                continue;
            }

            File::delete($path);
            $deletedFiles++;
        }
    }

    $sizeMb = number_format($deletedBytes / 1024 / 1024, 2);
    $verb = $dryRun ? 'Matched' : 'Deleted';
    $count = $dryRun ? $matchedPaths : $deletedFiles;

    $this->info("{$verb} {$count} path/file(s), {$deletedDirectories} directory/directories, {$sizeMb} MB.");
    return 0;
})->purpose('Clean downloads and ZIP files for finished scraper jobs');

Schedule::command('scraper:clean')->everyTwoHours();

try {
    $schedules = ScrapeSchedule::where('is_active', true)->get();
    foreach ($schedules as $schedule) {
        Schedule::call(function () use ($schedule) {
            // Không dispatch nếu đang có job khác chạy
            $alreadyRunning = ScrapeJob::whereIn('status', ['pending', 'processing'])->exists();
            if ($alreadyRunning) {
                \Illuminate\Support\Facades\Log::warning(
                    "Schedule #{$schedule->id}: skipped dispatch — another job is already running."
                );
                return;
            }

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
