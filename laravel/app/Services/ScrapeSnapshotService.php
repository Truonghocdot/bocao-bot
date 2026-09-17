<?php

namespace App\Services;

use App\Models\ScrapeSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ScrapeSnapshotService
{
    public function __construct(private readonly ScraperService $scraperService) {}

    /**
     * @return array{snapshot: ScrapeSnapshot, cache_hit: bool}
     */
    public function resolve(string $fromDate, string $toDate, ?int $pageLimit): array
    {
        $lockKey = 'scrape-snapshot:'.sha1("{$fromDate}|{$toDate}");

        return Cache::lock($lockKey, 15000)->block(
            60,
            fn (): array => $this->resolveWhileLocked($fromDate, $toDate, $pageLimit)
        );
    }

    /**
     * @return array{snapshot: ScrapeSnapshot, cache_hit: bool}
     */
    private function resolveWhileLocked(string $fromDate, string $toDate, ?int $pageLimit): array
    {
        $current = ScrapeSnapshot::query()
            ->where('from_date', $fromDate)
            ->where('to_date', $toDate)
            ->where('status', 'ready')
            ->latest('completed_at')
            ->first();

        $freshMinutes = max(1, (int) config('services.scraper.snapshot_fresh_minutes', 30));
        $isFresh = $current?->checked_at?->greaterThanOrEqualTo(now()->subMinutes($freshMinutes)) ?? false;

        if ($isFresh) {
            $totalPages = (int) $current->last_seen_total_pages;
            $totalRecords = (int) $current->last_seen_total_records;

            if ($this->canReuse($current, $this->requiredPages($pageLimit, $totalPages))) {
                return $this->reuse($current, $totalRecords, $totalPages);
            }

            return $this->build($fromDate, $toDate, $totalRecords, $totalPages, $pageLimit, $current);
        }

        $inspection = $this->scraperService->inspect($fromDate, $toDate);
        $totalRecords = (int) $inspection['totalRecords'];
        $totalPages = (int) $inspection['totalPages'];
        $requiredPages = $this->requiredPages($pageLimit, $totalPages);

        // A lower page count deliberately does not invalidate an existing snapshot.
        if ($current && $totalPages <= (int) $current->source_total_pages && $this->canReuse($current, $requiredPages)) {
            return $this->reuse($current, $totalRecords, $totalPages);
        }

        return $this->build($fromDate, $toDate, $totalRecords, $totalPages, $pageLimit, $current);
    }

    /**
     * @return array{snapshot: ScrapeSnapshot, cache_hit: bool}
     */
    private function reuse(ScrapeSnapshot $snapshot, int $totalRecords, int $totalPages): array
    {
        $snapshot->update([
            'last_seen_total_records' => $totalRecords,
            'last_seen_total_pages' => $totalPages,
            'checked_at' => now(),
            'last_used_at' => now(),
        ]);

        return ['snapshot' => $snapshot->refresh(), 'cache_hit' => true];
    }

    /**
     * @return array{snapshot: ScrapeSnapshot, cache_hit: bool}
     */
    private function build(
        string $fromDate,
        string $toDate,
        int $totalRecords,
        int $totalPages,
        ?int $pageLimit,
        ?ScrapeSnapshot $current
    ): array {
        $requiredPages = $this->requiredPages($pageLimit, $totalPages);
        $snapshot = ScrapeSnapshot::create([
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'status' => 'building',
            'source_total_records' => $totalRecords,
            'source_total_pages' => $totalPages,
            'last_seen_total_records' => $totalRecords,
            'last_seen_total_pages' => $totalPages,
            'scraped_pages' => 0,
            'checked_at' => now(),
            'last_used_at' => now(),
        ]);

        $downloadKey = now()->format('Ymd-His')."-snapshot-{$snapshot->id}";
        $snapshot->update([
            'download_key' => $downloadKey,
            'download_dir' => base_path("../scraper/downloads/{$downloadKey}"),
        ]);

        try {
            if ($requiredPages === 0) {
                $this->promoteEmptySnapshot($snapshot, $current);

                return ['snapshot' => $snapshot->refresh(), 'cache_hit' => false];
            }

            $result = $this->scraperService->download(
                $fromDate,
                $toDate,
                $requiredPages,
                $downloadKey
            );

            $files = array_values(array_filter(
                (array) ($result['files'] ?? []),
                fn ($file): bool => is_array($file)
            ));
            $expectedFiles = (int) ($result['expectedFiles'] ?? count($files));
            $downloadedFiles = (int) ($result['downloaded'] ?? count($files));
            $downloadDir = (string) ($result['downloadDir'] ?? '');

            if ($expectedFiles < 1 || $downloadedFiles !== $expectedFiles || count($files) !== $expectedFiles) {
                throw new RuntimeException(
                    "Snapshot incomplete: downloaded {$downloadedFiles}/{$expectedFiles}, manifest ".count($files).'.'
                );
            }

            $fileRows = $this->validateManifest($downloadDir, $files);

            DB::transaction(function () use (
                $snapshot,
                $current,
                $requiredPages,
                $expectedFiles,
                $downloadDir,
                $fileRows
            ): void {
                $snapshot->files()->createMany($fileRows);
                $snapshot->update([
                    'status' => 'ready',
                    'scraped_pages' => $requiredPages,
                    'expected_files' => $expectedFiles,
                    'downloaded_files' => $expectedFiles,
                    'download_dir' => $downloadDir,
                    'completed_at' => now(),
                    'last_used_at' => now(),
                    'error_message' => null,
                ]);

                if ($current && $current->isNot($snapshot)) {
                    $current->update(['status' => 'superseded']);
                }
            });

            return ['snapshot' => $snapshot->refresh(), 'cache_hit' => false];
        } catch (\Throwable $exception) {
            $snapshot->update([
                'status' => 'failed',
                'error_message' => Str::limit($exception->getMessage(), 2000, '...'),
            ]);

            throw $exception;
        }
    }

    private function promoteEmptySnapshot(ScrapeSnapshot $snapshot, ?ScrapeSnapshot $current): void
    {
        DB::transaction(function () use ($snapshot, $current): void {
            $snapshot->update([
                'status' => 'ready',
                'scraped_pages' => 0,
                'expected_files' => 0,
                'downloaded_files' => 0,
                'completed_at' => now(),
                'last_used_at' => now(),
            ]);

            if ($current && $current->isNot($snapshot)) {
                $current->update(['status' => 'superseded']);
            }
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, array<string, mixed>>
     */
    private function validateManifest(string $downloadDir, array $files): array
    {
        $realDownloadDir = realpath($downloadDir);

        if ($realDownloadDir === false || ! is_dir($realDownloadDir)) {
            throw new RuntimeException('Snapshot download directory does not exist.');
        }

        return array_map(function (array $file) use ($realDownloadDir): array {
            $realPath = realpath((string) ($file['path'] ?? ''));
            $allowedPrefix = rtrim(strtolower($realDownloadDir), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

            if (
                $realPath === false
                || ! str_starts_with(strtolower($realPath), $allowedPrefix)
                || ! is_file($realPath)
                || filesize($realPath) < 1
            ) {
                throw new RuntimeException('Snapshot manifest contains a missing or unsafe file.');
            }

            return [
                'page_number' => max(1, (int) ($file['pageIndex'] ?? 1)),
                'global_index' => max(0, (int) ($file['globalIndex'] ?? 0)),
                'company_name' => isset($file['companyName']) ? (string) $file['companyName'] : null,
                'filename' => basename($realPath),
                'relative_path' => basename($realPath),
                'size_bytes' => filesize($realPath),
            ];
        }, $files);
    }

    private function requiredPages(?int $pageLimit, int $totalPages): int
    {
        if ($totalPages < 1) {
            return 0;
        }

        return $pageLimit === null
            ? $totalPages
            : min(max(1, $pageLimit), $totalPages);
    }

    private function canReuse(ScrapeSnapshot $snapshot, int $requiredPages): bool
    {
        if ((int) $snapshot->scraped_pages < $requiredPages) {
            return false;
        }

        if ((int) $snapshot->downloaded_files === 0) {
            return $requiredPages === 0;
        }

        $files = $snapshot->files()->get();

        if ($files->count() !== (int) $snapshot->downloaded_files) {
            return false;
        }

        $downloadDir = rtrim((string) $snapshot->download_dir, DIRECTORY_SEPARATOR);

        return $files->every(function ($file) use ($downloadDir): bool {
            $path = $downloadDir.DIRECTORY_SEPARATOR.$file->relative_path;

            return is_file($path) && filesize($path) > 0;
        });
    }
}
