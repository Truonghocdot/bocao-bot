<?php

use App\Jobs\RunScraperJob;
use App\Jobs\WarmTodaySnapshotJob;
use App\Models\ScrapeJob;
use App\Models\ScrapeSchedule;
use App\Models\ScrapeSnapshot;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('scraper:clean {--hours= : Delete files for finished jobs older than this many hours} {--dry-run : Show files without deleting}', function () {
    $hours = $this->option('hours') !== null
        ? (int) $this->option('hours')
        : 2;
    $dryRun = (bool) $this->option('dry-run');

    if ($hours < 1) {
        $this->error('The --hours option must be at least 1.');

        return 1;
    }

    $cutoff = now()->subHours($hours);
    $finishedStatuses = ['completed', 'completed_with_errors', 'failed', 'stopped'];
    $deletedFiles = 0;
    $deletedDirectories = 0;
    $deletedBytes = 0;
    $matchedPaths = 0;
    $downloadRoot = realpath(base_path('../scraper/downloads')) ?: base_path('../scraper/downloads');
    $zipRoot = realpath(base_path('../storage/zips')) ?: base_path('../storage/zips');
    $isAllowedCleanupPath = function (string $path) use ($downloadRoot, $zipRoot): bool {
        $realPath = realpath($path) ?: $path;

        return str_starts_with($realPath, $downloadRoot.DIRECTORY_SEPARATOR)
            || str_starts_with($realPath, $zipRoot.DIRECTORY_SEPARATOR);
    };

    $jobs = ScrapeJob::query()
        ->whereNull('scrape_snapshot_id')
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

Schedule::command('scraper:clean --hours=2')->everyTwoHours();

Artisan::command('scraper:clean-snapshots {--dry-run : Show snapshots without deleting}', function () {
    $dryRun = (bool) $this->option('dry-run');
    $readyCutoff = now()->subDays(max(1, (int) config('services.scraper.snapshot_retention_days', 7)));
    $obsoleteCutoff = now()->subDay();
    $activeStatuses = ['pending', 'processing', 'waiting_snapshot', 'delivering'];
    $downloadRoot = realpath(base_path('../scraper/downloads')) ?: base_path('../scraper/downloads');

    $snapshots = ScrapeSnapshot::query()
        ->whereDoesntHave('jobs', fn ($query) => $query->whereIn('status', $activeStatuses))
        ->where(function ($query) use ($readyCutoff, $obsoleteCutoff) {
            $query->where(function ($query) use ($readyCutoff) {
                $query->where('status', 'ready')
                    ->where(function ($query) use ($readyCutoff) {
                        $query->where('last_used_at', '<=', $readyCutoff)
                            ->orWhere(function ($query) use ($readyCutoff) {
                                $query->whereNull('last_used_at')
                                    ->where('updated_at', '<=', $readyCutoff);
                            });
                    });
            })->orWhere(function ($query) use ($obsoleteCutoff) {
                $query->whereIn('status', ['superseded', 'failed'])
                    ->where('updated_at', '<=', $obsoleteCutoff);
            });
        })
        ->oldest('updated_at')
        ->get();

    foreach ($snapshots as $snapshot) {
        $path = $snapshot->download_dir;

        if ($path && File::exists($path)) {
            $realPath = realpath($path) ?: $path;
            $allowed = str_starts_with(
                strtolower($realPath),
                strtolower(rtrim($downloadRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            );

            if (! $allowed) {
                $this->warn("Skip unsafe snapshot path #{$snapshot->id}: {$path}");

                continue;
            }

            if ($dryRun) {
                $this->line("[dry-run] snapshot #{$snapshot->id}: {$path}");

                continue;
            }

            File::deleteDirectory($path);
        } elseif ($dryRun) {
            $this->line("[dry-run] snapshot #{$snapshot->id}: database record only");

            continue;
        }

        $snapshot->delete();
    }

    $this->info(($dryRun ? 'Matched ' : 'Deleted ').$snapshots->count().' snapshot(s).');
})->purpose('Clean expired, superseded, and failed scrape snapshots');

Schedule::command('scraper:clean-snapshots')->hourly();
Schedule::job(new WarmTodaySnapshotJob)
    ->everyThirtyMinutes()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(30);

try {
    $schedules = ScrapeSchedule::where('is_active', true)->get();
    foreach ($schedules as $schedule) {
        Schedule::call(function () use ($schedule) {
            $schedule->refresh();

            if (! $schedule->is_active) {
                return;
            }

            $fromDate = $schedule->from_date;
            $toDate = $schedule->to_date;

            if (! $fromDate || ! $toDate) {
                Log::warning(
                    "Schedule #{$schedule->id}: skipped dispatch — missing fixed from/to date."
                );

                return;
            }

            $targetChatIds = $schedule->target_chat_ids ?: [
                $schedule->target_chat_id ?: $schedule->chat_id,
            ];

            $job = ScrapeJob::create([
                'chat_id' => $schedule->chat_id,
                'target_chat_id' => $targetChatIds[0],
                'target_chat_ids' => $targetChatIds,
                'scrape_schedule_id' => $schedule->id,
                'status' => 'pending',
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'max_records' => $schedule->max_records,
            ]);

            $schedule->update(['is_active' => false]);

            RunScraperJob::dispatch($job);
        })->cron($schedule->cron_expression);
    }
} catch (Exception $e) {
    // Ignore DB errors when migrating
}
