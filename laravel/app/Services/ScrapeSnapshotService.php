<?php

namespace App\Services;

use App\Models\ScrapeSnapshot;
use App\Models\ScrapeSnapshotFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ScrapeSnapshotService
{
    private const ROWS_PER_PAGE = 20;

    public function __construct(private readonly ScraperService $scraperService) {}

    /**
     * @return array{snapshot: ScrapeSnapshot, cache_hit: bool}
     */
    public function resolve(
        string $fromDate,
        string $toDate,
        ?int $pageLimit,
        bool $refreshEmptySnapshot = false
    ): array {
        $lockKey = 'scrape-snapshot:'.sha1("{$fromDate}|{$toDate}");

        return Cache::lock($lockKey, 15000)->block(
            60,
            fn (): array => $this->resolveWhileLocked(
                $fromDate,
                $toDate,
                $pageLimit,
                $refreshEmptySnapshot
            )
        );
    }

    /**
     * @return array{snapshot: ScrapeSnapshot, cache_hit: bool}
     */
    private function resolveWhileLocked(
        string $fromDate,
        string $toDate,
        ?int $pageLimit,
        bool $refreshEmptySnapshot
    ): array {
        $this->recoverFailedSnapshots($fromDate, $toDate);

        $latestObservation = $this->latestObservation($fromDate, $toDate);
        [$knownRecords, $knownPages] = $this->latestKnownTotals($fromDate, $toDate);
        $best = $this->selectBestSnapshot($fromDate, $toDate, $pageLimit, $knownRecords, $knownPages);
        $freshMinutes = max(1, (int) config('services.scraper.snapshot_fresh_minutes', 120));
        $isFresh = $latestObservation?->checked_at?->greaterThanOrEqualTo(now()->subMinutes($freshMinutes)) ?? false;

        $mustRecheckEmpty = $refreshEmptySnapshot
            && $best !== null
            && (int) $best->source_total_records === 0;

        if ($isFresh && ! $mustRecheckEmpty) {
            if ($best) {
                return $this->reuse($best, $knownRecords, $knownPages, false);
            }

            throw $this->completenessError();
        }

        $inspection = $this->scraperService->inspect($fromDate, $toDate);
        $totalRecords = max($knownRecords, (int) $inspection['totalRecords']);
        $totalPages = max($knownPages, (int) $inspection['totalPages']);
        $best = $this->selectBestSnapshot($fromDate, $toDate, $pageLimit, $totalRecords, $totalPages);
        $expectedFiles = $this->expectedFilesForRequest($pageLimit, $totalRecords, $totalPages);

        if ($best && $this->validFileCount($best, $pageLimit) >= $expectedFiles) {
            return $this->reuse($best, $totalRecords, $totalPages, true);
        }

        return $this->build($fromDate, $toDate, $totalRecords, $totalPages, $pageLimit, $best);
    }

    /**
     * @return array{snapshot: ScrapeSnapshot, cache_hit: bool}
     */
    private function reuse(
        ScrapeSnapshot $snapshot,
        int $totalRecords,
        int $totalPages,
        bool $checkedNow
    ): array {
        $updates = [
            'last_seen_total_records' => $totalRecords,
            'last_seen_total_pages' => $totalPages,
            'last_used_at' => now(),
        ];

        if ($checkedNow) {
            $updates['checked_at'] = now();
        }

        $snapshot->update($updates);

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
        $expectedFiles = $this->expectedFilesForRequest($pageLimit, $totalRecords, $totalPages);
        $snapshot = ScrapeSnapshot::create([
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'status' => 'building',
            'source_total_records' => $totalRecords,
            'source_total_pages' => $totalPages,
            'last_seen_total_records' => $totalRecords,
            'last_seen_total_pages' => $totalPages,
            'scraped_pages' => 0,
            'expected_files' => $expectedFiles,
            'checked_at' => now(),
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
            $resultExpectedFiles = (int) ($result['expectedFiles'] ?? count($files));
            $expectedFiles = max($expectedFiles, $resultExpectedFiles);
            $downloadDir = (string) ($result['downloadDir'] ?? '');
            $fileRows = $this->validateManifest($downloadDir, $files);

            if (count($fileRows) !== $expectedFiles) {
                throw new RuntimeException(
                    'Snapshot incomplete: valid '.count($fileRows)."/{$expectedFiles} PDF files."
                );
            }

            DB::transaction(function () use (
                $snapshot,
                $fromDate,
                $toDate,
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

                ScrapeSnapshot::query()
                    ->where('from_date', $fromDate)
                    ->where('to_date', $toDate)
                    ->where('status', 'ready')
                    ->whereKeyNot($snapshot->id)
                    ->update(['status' => 'superseded']);
            });

            return ['snapshot' => $snapshot->refresh(), 'cache_hit' => false];
        } catch (\Throwable $exception) {
            $this->recoverPartialSnapshot($snapshot, $exception);

            [$latestRecords, $latestPages] = $this->latestKnownTotals($fromDate, $toDate);
            $best = $this->selectBestSnapshot(
                $fromDate,
                $toDate,
                $pageLimit,
                $latestRecords,
                $latestPages
            );

            if ($best) {
                return [
                    'snapshot' => $this->markUsed($best, $latestRecords, $latestPages),
                    'cache_hit' => $best->id !== $snapshot->id,
                ];
            }

            throw $this->completenessError($exception);
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

    private function recoverPartialSnapshot(ScrapeSnapshot $snapshot, \Throwable $exception): void
    {
        $downloadDir = (string) $snapshot->download_dir;
        $paths = is_dir($downloadDir)
            ? (glob(rtrim($downloadDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.pdf') ?: [])
            : [];
        sort($paths);

        $fileRows = [];
        foreach ($paths as $path) {
            if (! ScrapeSnapshotFile::isValidPdfPath($path)) {
                continue;
            }

            $filename = basename($path);
            if (preg_match('/^(\d+)_/u', $filename, $matches) !== 1) {
                continue;
            }

            $globalIndex = max(0, (int) $matches[1] - 1);
            $companyName = preg_replace('/^\d+_|\.pdf$/iu', '', $filename);

            $fileRows[] = [
                'page_number' => intdiv($globalIndex, self::ROWS_PER_PAGE) + 1,
                'global_index' => $globalIndex,
                'company_name' => str_replace('_', ' ', (string) $companyName),
                'filename' => $filename,
                'relative_path' => $filename,
                'size_bytes' => filesize($path),
            ];
        }

        DB::transaction(function () use ($snapshot, $exception, $fileRows): void {
            $snapshot->files()->delete();
            if ($fileRows !== []) {
                $snapshot->files()->createMany($fileRows);
            }

            $snapshot->update([
                'status' => $fileRows === [] ? 'failed' : 'partial',
                'scraped_pages' => $fileRows === [] ? 0 : max(array_column($fileRows, 'page_number')),
                'downloaded_files' => count($fileRows),
                'completed_at' => now(),
                'error_message' => Str::limit($exception->getMessage(), 2000, '...'),
            ]);
        });
    }

    private function recoverFailedSnapshots(string $fromDate, string $toDate): void
    {
        ScrapeSnapshot::query()
            ->where('from_date', $fromDate)
            ->where('to_date', $toDate)
            ->where('status', 'failed')
            ->where('downloaded_files', 0)
            ->whereNotNull('download_dir')
            ->get()
            ->each(function (ScrapeSnapshot $snapshot): void {
                if (! is_dir((string) $snapshot->download_dir)) {
                    return;
                }

                $this->recoverPartialSnapshot(
                    $snapshot,
                    new RuntimeException($snapshot->error_message ?: 'Previous scrape was interrupted.')
                );
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
                || ! ScrapeSnapshotFile::isValidPdfPath($realPath)
            ) {
                throw new RuntimeException('Snapshot manifest contains a missing, invalid, or unsafe PDF.');
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

    private function latestObservation(string $fromDate, string $toDate): ?ScrapeSnapshot
    {
        return ScrapeSnapshot::query()
            ->where('from_date', $fromDate)
            ->where('to_date', $toDate)
            ->whereNotNull('checked_at')
            ->latest('checked_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function latestKnownTotals(string $fromDate, string $toDate): array
    {
        $query = ScrapeSnapshot::query()
            ->where('from_date', $fromDate)
            ->where('to_date', $toDate);

        return [
            max(0, (int) (clone $query)->max('source_total_records')),
            max(0, (int) (clone $query)->max('source_total_pages')),
        ];
    }

    private function selectBestSnapshot(
        string $fromDate,
        string $toDate,
        ?int $pageLimit,
        int $totalRecords,
        int $totalPages
    ): ?ScrapeSnapshot {
        $expectedFiles = $this->expectedFilesForRequest($pageLimit, $totalRecords, $totalPages);
        $candidates = ScrapeSnapshot::query()
            ->where('from_date', $fromDate)
            ->where('to_date', $toDate)
            ->whereIn('status', ['ready', 'partial'])
            ->latest('completed_at')
            ->latest('id')
            ->get();

        if ($expectedFiles === 0) {
            return $candidates->first(fn (ScrapeSnapshot $snapshot): bool => $snapshot->status === 'ready'
                && (int) $snapshot->source_total_records === 0);
        }

        $minimumRatio = max(0, min(100, (float) config('services.scraper.snapshot_min_completeness', 60))) / 100;
        $ranked = $candidates->map(function (ScrapeSnapshot $snapshot) use ($pageLimit, $expectedFiles): array {
            $validFiles = $this->validFileCount($snapshot, $pageLimit);

            return [
                'snapshot' => $snapshot,
                'valid_files' => $validFiles,
                'ratio' => $validFiles / $expectedFiles,
                'completed_at' => $snapshot->completed_at?->getTimestamp() ?? 0,
            ];
        })->filter(fn (array $candidate): bool => $candidate['ratio'] > $minimumRatio)
            ->sort(function (array $left, array $right): int {
                return $right['valid_files'] <=> $left['valid_files']
                    ?: $right['completed_at'] <=> $left['completed_at']
                    ?: $right['snapshot']->id <=> $left['snapshot']->id;
            });

        return $ranked->first()['snapshot'] ?? null;
    }

    private function validFileCount(ScrapeSnapshot $snapshot, ?int $pageLimit): int
    {
        $query = $snapshot->files();
        if ($pageLimit !== null) {
            $query->where('page_number', '<=', $pageLimit);
        }

        $downloadDir = rtrim((string) $snapshot->download_dir, DIRECTORY_SEPARATOR);

        return $query->get()->filter(
            fn (ScrapeSnapshotFile $file): bool => ScrapeSnapshotFile::isValidPdfPath(
                $downloadDir.DIRECTORY_SEPARATOR.$file->relative_path
            )
        )->count();
    }

    private function markUsed(ScrapeSnapshot $snapshot, int $totalRecords, int $totalPages): ScrapeSnapshot
    {
        $snapshot->update([
            'last_seen_total_records' => $totalRecords,
            'last_seen_total_pages' => $totalPages,
            'last_used_at' => now(),
        ]);

        return $snapshot->refresh();
    }

    private function expectedFilesForRequest(?int $pageLimit, int $totalRecords, int $totalPages): int
    {
        $requiredPages = $this->requiredPages($pageLimit, $totalPages);

        if ($requiredPages === 0) {
            return 0;
        }

        return min($totalRecords, $requiredPages * self::ROWS_PER_PAGE);
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

    private function completenessError(?\Throwable $previous = null): RuntimeException
    {
        return new RuntimeException(
            'SNAPSHOT_COMPLETENESS_TOO_LOW: Không có snapshot đủ dữ liệu để gửi.',
            0,
            $previous
        );
    }
}
