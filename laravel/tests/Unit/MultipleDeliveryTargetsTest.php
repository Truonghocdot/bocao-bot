<?php

namespace Tests\Unit;

use App\Jobs\DeliverPendingFilesJob;
use App\Models\ScrapeJob;
use App\Models\ScrapeSnapshot;
use App\Services\TelegramCommandService;
use App\Services\TelegramDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Mockery;
use ReflectionMethod;
use Telegram\Bot\Laravel\Facades\Telegram;
use Tests\TestCase;

class MultipleDeliveryTargetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_list_accepts_multiple_values_and_removes_duplicates(): void
    {
        $service = app(TelegramCommandService::class);
        $method = new ReflectionMethod($service, 'resolveTargetChats');

        $result = $method->invoke(
            $service,
            "-1001234567890, -1009876543210\n-1001234567890",
            '123456'
        );

        $this->assertSame([], $result['invalid']);
        $this->assertSame([
            '-1001234567890',
            '-1009876543210',
        ], array_column($result['targets'], 'chat_id'));
    }

    public function test_delivery_sends_each_file_to_every_target(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bocao-pdf-');
        file_put_contents($file, 'test pdf');

        try {
            $jobRecord = new ScrapeJob([
                'chat_id' => '123456',
                'target_chat_ids' => ['-1001234567890', '-1009876543210'],
            ]);
            $job = new DeliverPendingFilesJob($jobRecord);
            $deliveryService = Mockery::mock(TelegramDeliveryService::class);

            $deliveryService->shouldReceive('sendDocumentToTarget')
                ->twice()
                ->with(Mockery::on(fn (array $params) => in_array(
                    $params['chat_id'],
                    ['-1001234567890', '-1009876543210'],
                    true
                )))
                ->andReturnNull();
            $deliveryService->shouldReceive('pauseBetweenDocumentSends')->twice();

            $method = new ReflectionMethod($job, 'deliverFiles');
            $sent = $method->invoke(
                $job,
                [$file],
                $jobRecord->target_chat_ids,
                $deliveryService
            );

            $this->assertSame(2, $sent);
        } finally {
            @unlink($file);
        }
    }

    public function test_delivery_stops_after_a_chunk_and_requeues_at_the_tail(): void
    {
        Queue::fake();
        config(['services.telegram_delivery.chunk_size' => 1]);

        $directory = base_path('../scraper/downloads/test-delivery-'.uniqid());
        File::ensureDirectoryExists($directory);
        $file = $directory.DIRECTORY_SEPARATOR.'0001_Test.pdf';
        file_put_contents($file, '%PDF-test');

        try {
            $snapshot = ScrapeSnapshot::create([
                'from_date' => '17/09/2026',
                'to_date' => '17/09/2026',
                'status' => 'ready',
                'source_total_records' => 1,
                'source_total_pages' => 1,
                'last_seen_total_records' => 1,
                'last_seen_total_pages' => 1,
                'scraped_pages' => 1,
                'expected_files' => 1,
                'downloaded_files' => 1,
                'download_dir' => $directory,
                'checked_at' => now(),
                'completed_at' => now(),
                'last_used_at' => now(),
            ]);
            $snapshot->files()->create([
                'page_number' => 1,
                'global_index' => 0,
                'company_name' => 'Test',
                'filename' => basename($file),
                'relative_path' => basename($file),
                'size_bytes' => filesize($file),
            ]);
            $jobRecord = ScrapeJob::create([
                'chat_id' => '123456',
                'target_chat_id' => '-1001234567890',
                'target_chat_ids' => ['-1001234567890', '-1009876543210'],
                'scrape_snapshot_id' => $snapshot->id,
                'status' => 'delivering',
                'downloaded_count' => 1,
            ]);

            $deliveryService = Mockery::mock(TelegramDeliveryService::class);
            $deliveryService->shouldReceive('sendDocumentToTarget')->once()->andReturn([]);
            $deliveryService->shouldReceive('pauseBetweenDocumentSends')->once();
            $telegramManager = Mockery::mock();
            $telegramManager->shouldReceive('sendMessage')->once();
            Telegram::swap($telegramManager);

            (new DeliverPendingFilesJob($jobRecord))->handle($deliveryService);

            $this->assertSame(1, $jobRecord->refresh()->delivery_cursor);
            $this->assertSame('delivering', $jobRecord->status);
            Queue::assertPushed(DeliverPendingFilesJob::class);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
