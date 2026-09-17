<?php

namespace Tests\Feature;

use App\Models\ScrapeJob;
use App\Models\ScrapeSnapshot;
use App\Services\ScraperService;
use App\Services\ScrapeSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
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
        [$oldSnapshot] = $this->createReadySnapshot(1, 1, 1, now()->subHour());
        $newDirectory = $this->makeDirectory('new');
        $firstPath = $this->makePdf($newDirectory, '0001_First.pdf');
        $secondPath = $this->makePdf($newDirectory, '0002_Second.pdf');

        $scraper = Mockery::mock(ScraperService::class);
        $scraper->shouldReceive('inspect')->once()->andReturn([
            'totalRecords' => 21,
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

    public function test_lower_page_count_keeps_a_covering_snapshot(): void
    {
        [$snapshot] = $this->createReadySnapshot(3, 3, 1, now()->subHour());
        $scraper = Mockery::mock(ScraperService::class);
        $scraper->shouldReceive('inspect')->once()->andReturn([
            'totalRecords' => 35,
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
        $this->assertSame(2, $result['snapshot']->last_seen_total_pages);
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
            'source_total_records' => $sourcePages * 20,
            'source_total_pages' => $sourcePages,
            'last_seen_total_records' => $sourcePages * 20,
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
