<?php

namespace Tests\Feature;

use App\Models\ScrapeJob;
use App\Models\ScrapeSnapshot;
use App\Services\ScraperService;
use App\Services\ScrapeSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ScrapeSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_fresh_snapshot_is_reused_without_inspecting_dkkd(): void
    {
        [$snapshot] = $this->createReadySnapshot(3, 3, 1);
        $scraper = Mockery::mock(ScraperService::class);
        $scraper->shouldNotReceive('inspect');
        $scraper->shouldNotReceive('download');

        $result = (new ScrapeSnapshotService($scraper))->resolve(
            '17/09/2026',
            '17/09/2026',
            1
        );

        $this->assertTrue($result['cache_hit']);
        $this->assertSame($snapshot->id, $result['snapshot']->id);
    }

    public function test_higher_page_count_builds_and_promotes_a_complete_snapshot(): void
    {
        [$oldSnapshot] = $this->createReadySnapshot(1, 1, 1, now()->subHours(3));
        $newDirectory = $this->makeDirectory('new');
        $firstPath = $this->makePdf($newDirectory, '0001_First.pdf');
        $secondPath = $this->makePdf($newDirectory, '0002_Second.pdf');

        $scraper = Mockery::mock(ScraperService::class);
        $scraper->shouldReceive('inspect')->once()->andReturn([
            'totalRecords' => 2,
            'totalPages' => 2,
        ]);
        $scraper->shouldReceive('download')->once()->with(
            '17/09/2026',
            '17/09/2026',
            2,
            Mockery::type('string')
        )->andReturn([
            'downloaded' => 2,
            'expectedFiles' => 2,
            'downloadDir' => $newDirectory,
            'files' => [
                $this->manifest($firstPath, 1, 0, 'First'),
                $this->manifest($secondPath, 2, 1, 'Second'),
            ],
        ]);

        $service = new ScrapeSnapshotService($scraper);
        $result = $service->resolve(
            '17/09/2026',
            '17/09/2026',
            null
        );
        $secondResult = $service->resolve('17/09/2026', '17/09/2026', null);

        $this->assertFalse($result['cache_hit']);
        $this->assertTrue($secondResult['cache_hit']);
        $this->assertSame($result['snapshot']->id, $secondResult['snapshot']->id);
        $this->assertSame(2, $result['snapshot']->scraped_pages);
        $this->assertSame(2, $result['snapshot']->files()->count());
        $this->assertSame('superseded', $oldSnapshot->refresh()->status);
    }

    public function test_empty_result_is_cached_as_a_ready_snapshot(): void
    {
        $scraper = Mockery::mock(ScraperService::class);
        $scraper->shouldReceive('inspect')->once()->andReturn([
            'totalRecords' => 0,
            'totalPages' => 0,
        ]);
        $scraper->shouldNotReceive('download');

        $result = (new ScrapeSnapshotService($scraper))->resolve(
            '17/09/2026',
            '17/09/2026',
            null
        );

        $this->assertSame('ready', $result['snapshot']->status);
        $this->assertSame(0, $result['snapshot']->downloaded_files);
    }

    public function test_manual_request_rechecks_a_fresh_empty_snapshot_and_downloads_new_records(): void
    {
        $emptySnapshot = ScrapeSnapshot::create([
            'from_date' => '17/09/2026',
            'to_date' => '17/09/2026',
            'status' => 'ready',
            'source_total_records' => 0,
            'source_total_pages' => 0,
            'last_seen_total_records' => 0,
            'last_seen_total_pages' => 0,
            'scraped_pages' => 0,
            'expected_files' => 0,
            'downloaded_files' => 0,
            'checked_at' => now(),
            'completed_at' => now(),
        ]);
        $newDirectory = $this->makeDirectory('manual-refresh');
        $newPath = $this->makePdf($newDirectory, '0001_New.pdf');

        $scraper = Mockery::mock(ScraperService::class);
        $scraper->shouldReceive('inspect')->once()->andReturn([
            'totalRecords' => 1,
            'totalPages' => 1,
        ]);
        $scraper->shouldReceive('download')->once()->andReturn([
            'downloaded' => 1,
            'expectedFiles' => 1,
            'downloadDir' => $newDirectory,
            'files' => [$this->manifest($newPath, 1, 0, 'New')],
        ]);

        $result = (new ScrapeSnapshotService($scraper))->resolve(
            '17/09/2026',
            '17/09/2026',
            null,
            true
        );

        $this->assertFalse($result['cache_hit']);
        $this->assertSame(1, $result['snapshot']->downloaded_files);
        $this->assertSame('superseded', $emptySnapshot->refresh()->status);
    }

    public function test_manual_request_rechecks_empty_snapshot_before_confirming_no_data(): void
    {
        $emptySnapshot = ScrapeSnapshot::create([
            'from_date' => '17/09/2026',
            'to_date' => '17/09/2026',
            'status' => 'ready',
            'source_total_records' => 0,
            'source_total_pages' => 0,
            'last_seen_total_records' => 0,
            'last_seen_total_pages' => 0,
            'scraped_pages' => 0,
            'expected_files' => 0,
            'downloaded_files' => 0,
            'checked_at' => now(),
            'completed_at' => now(),
        ]);

        $scraper = Mockery::mock(ScraperService::class);
        $scraper->shouldReceive('inspect')->once()->andReturn([
            'totalRecords' => 0,
            'totalPages' => 0,
        ]);
        $scraper->shouldNotReceive('download');

        $result = (new ScrapeSnapshotService($scraper))->resolve(
            '17/09/2026',
            '17/09/2026',
            null,
            true
        );

        $this->assertTrue($result['cache_hit']);
        $this->assertSame($emptySnapshot->id, $result['snapshot']->id);
        $this->assertTrue($result['snapshot']->checked_at->isToday());
    }

    public function test_lower_page_count_keeps_a_covering_snapshot(): void
    {
        [$snapshot] = $this->createReadySnapshot(3, 3, 20, now()->subHours(3));
        $scraper = Mockery::mock(ScraperService::class);
        $scraper->shouldReceive('inspect')->once()->andReturn([
            'totalRecords' => 20,
            'totalPages' => 2,
        ]);
        $scraper->shouldNotReceive('download');

        $result = (new ScrapeSnapshotService($scraper))->resolve(
            '17/09/2026',
            '17/09/2026',
            1
        );

        $this->assertTrue($result['cache_hit']);
        $this->assertSame($snapshot->id, $result['snapshot']->id);
        $this->assertSame(3, $result['snapshot']->last_seen_total_pages);
    }

    public function test_partial_snapshot_above_sixty_percent_is_returned(): void
    {
        $scraper = Mockery::mock(ScraperService::class);
        $scraper->shouldReceive('inspect')->once()->andReturn([
            'totalRecords' => 10,
            'totalPages' => 1,
        ]);
        $scraper->shouldReceive('download')->once()->andReturnUsing(function (
            ?string $fromDate,
            ?string $toDate,
            ?int $limit,
            string $downloadKey
        ): array {
            $directory = base_path("../scraper/downloads/{$downloadKey}");
            File::ensureDirectoryExists($directory);
            $this->temporaryDirectories[] = $directory;

            for ($index = 1; $index <= 7; $index++) {
                $this->makePdf($directory, sprintf('%04d_Test.pdf', $index));
            }

            throw new RuntimeException('PAGE_CONTEXT_LOST');
        });

        $result = (new ScrapeSnapshotService($scraper))->resolve(
            '17/09/2026',
            '17/09/2026',
            null
        );

        $this->assertFalse($result['cache_hit']);
        $this->assertSame('partial', $result['snapshot']->status);
        $this->assertSame(7, $result['snapshot']->files()->count());
    }

    public function test_snapshot_at_exactly_sixty_percent_is_rejected(): void
    {
        $scraper = Mockery::mock(ScraperService::class);
        $scraper->shouldReceive('inspect')->once()->andReturn([
            'totalRecords' => 10,
            'totalPages' => 1,
        ]);
        $scraper->shouldReceive('download')->once()->andReturnUsing(function (
            ?string $fromDate,
            ?string $toDate,
            ?int $limit,
            string $downloadKey
        ): array {
            $directory = base_path("../scraper/downloads/{$downloadKey}");
            File::ensureDirectoryExists($directory);
            $this->temporaryDirectories[] = $directory;

            for ($index = 1; $index <= 6; $index++) {
                $this->makePdf($directory, sprintf('%04d_Test.pdf', $index));
            }

            throw new RuntimeException('PAGE_CONTEXT_LOST');
        });

        try {
            (new ScrapeSnapshotService($scraper))->resolve(
                '17/09/2026',
                '17/09/2026',
                null
            );
            $this->fail('A snapshot at exactly 60% must not be returned.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SNAPSHOT_COMPLETENESS_TOO_LOW', $exception->getMessage());
        }

        $snapshot = ScrapeSnapshot::latest('id')->firstOrFail();
        $this->assertSame('partial', $snapshot->status);
        $this->assertSame(6, $snapshot->files()->count());
    }

    public function test_pdf_with_invalid_content_does_not_count_toward_completeness(): void
    {
        [$snapshot, $directory] = $this->createReadySnapshot(1, 1, 7);
        $snapshot->update([
            'status' => 'partial',
            'source_total_records' => 10,
            'last_seen_total_records' => 10,
        ]);
        file_put_contents($directory.DIRECTORY_SEPARATOR.'0007_Test.pdf', 'Not a PDF');

        $scraper = Mockery::mock(ScraperService::class);
        $scraper->shouldNotReceive('inspect');
        $scraper->shouldNotReceive('download');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SNAPSHOT_COMPLETENESS_TOO_LOW');

        (new ScrapeSnapshotService($scraper))->resolve('17/09/2026', '17/09/2026', null);
    }

    public function test_more_complete_snapshot_wins_over_newer_snapshot(): void
    {
        [$moreComplete] = $this->createReadySnapshot(1, 1, 8, now());
        $moreComplete->update([
            'status' => 'partial',
            'source_total_records' => 10,
            'last_seen_total_records' => 10,
            'completed_at' => now()->subHour(),
        ]);

        [$newer] = $this->createReadySnapshot(1, 1, 7, now());
        $newer->update([
            'status' => 'partial',
            'source_total_records' => 10,
            'last_seen_total_records' => 10,
            'completed_at' => now(),
        ]);

        $scraper = Mockery::mock(ScraperService::class);
        $scraper->shouldNotReceive('inspect');
        $scraper->shouldNotReceive('download');

        $result = (new ScrapeSnapshotService($scraper))->resolve(
            '17/09/2026',
            '17/09/2026',
            null
        );

        $this->assertSame($moreComplete->id, $result['snapshot']->id);
    }

    public function test_failed_snapshot_with_existing_pdfs_is_recovered_for_delivery(): void
    {
        [$snapshot, $directory] = $this->createReadySnapshot(1, 1, 7);
        $snapshot->files()->delete();
        $snapshot->update([
            'status' => 'failed',
            'downloaded_files' => 0,
            'source_total_records' => 10,
            'last_seen_total_records' => 10,
            'error_message' => 'PAGE_CONTEXT_LOST',
        ]);

        $scraper = Mockery::mock(ScraperService::class);
        $scraper->shouldNotReceive('inspect');
        $scraper->shouldNotReceive('download');

        $result = (new ScrapeSnapshotService($scraper))->resolve(
            '17/09/2026',
            '17/09/2026',
            null
        );

        $this->assertSame($snapshot->id, $result['snapshot']->id);
        $this->assertSame('partial', $snapshot->refresh()->status);
        $this->assertSame(7, $snapshot->files()->count());
        $this->assertDirectoryExists($directory);
    }

    public function test_snapshot_cleanup_preserves_active_delivery_and_removes_unused_snapshot(): void
    {
        [$activeSnapshot, $activeDirectory] = $this->createReadySnapshot(1, 1, 1, now()->subDays(8));
        [$unusedSnapshot, $unusedDirectory] = $this->createReadySnapshot(1, 1, 1, now()->subDays(8));
        $activeSnapshot->update(['last_used_at' => now()->subDays(8)]);
        $unusedSnapshot->update(['last_used_at' => now()->subDays(8)]);
        ScrapeJob::create([
            'chat_id' => '123456',
            'scrape_snapshot_id' => $activeSnapshot->id,
            'status' => 'delivering',
        ]);

        $this->artisan('scraper:clean-snapshots')->assertSuccessful();

        $this->assertDatabaseHas('scrape_snapshots', ['id' => $activeSnapshot->id]);
        $this->assertDirectoryExists($activeDirectory);
        $this->assertDatabaseMissing('scrape_snapshots', ['id' => $unusedSnapshot->id]);
        $this->assertDirectoryDoesNotExist($unusedDirectory);
    }

    protected function createReadySnapshot(
        int $sourcePages,
        int $scrapedPages,
        int $fileCount,
        $checkedAt = null
    ): array {
        $directory = $this->makeDirectory('ready');
        $snapshot = ScrapeSnapshot::create([
            'from_date' => '17/09/2026',
            'to_date' => '17/09/2026',
            'status' => 'ready',
            'source_total_records' => $fileCount,
            'source_total_pages' => $sourcePages,
            'last_seen_total_records' => $fileCount,
            'last_seen_total_pages' => $sourcePages,
            'scraped_pages' => $scrapedPages,
            'expected_files' => $fileCount,
            'downloaded_files' => $fileCount,
            'download_dir' => $directory,
            'checked_at' => $checkedAt ?: now(),
            'completed_at' => now(),
            'last_used_at' => now(),
        ]);

        for ($index = 0; $index < $fileCount; $index++) {
            $filename = sprintf('%04d_Test.pdf', $index + 1);
            $path = $this->makePdf($directory, $filename);
            $snapshot->files()->create([
                'page_number' => intdiv($index, 20) + 1,
                'global_index' => $index,
                'company_name' => 'Test',
                'filename' => $filename,
                'relative_path' => $filename,
                'size_bytes' => filesize($path),
            ]);
        }

        return [$snapshot, $directory];
    }

    protected function makeDirectory(string $suffix): string
    {
        $directory = base_path('../scraper/downloads/test-'.$suffix.'-'.uniqid());
        File::ensureDirectoryExists($directory);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    protected function makePdf(string $directory, string $filename): string
    {
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, '%PDF-test');

        return $path;
    }

    protected function manifest(string $path, int $page, int $index, string $company): array
    {
        return [
            'path' => $path,
            'filename' => basename($path),
            'pageIndex' => $page,
            'globalIndex' => $index,
            'companyName' => $company,
        ];
    }
}
