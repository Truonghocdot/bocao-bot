<?php

namespace Tests\Unit;

use App\Jobs\DeliverPendingFilesJob;
use App\Models\ScrapeJob;
use App\Services\TelegramCommandService;
use App\Services\TelegramDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
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
}
