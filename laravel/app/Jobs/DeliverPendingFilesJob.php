<?php

namespace App\Jobs;

use App\Models\ScrapeJob;
use App\Services\TelegramDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Laravel\Facades\Telegram;

/**
 * Scan download_dir of a ScrapeJob and deliver all downloaded PDFs to Telegram.
 * Used both for the normal post-scrape delivery flow and failure recovery.
 */
class DeliverPendingFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Large Telegram batches can hit rate limits; keep this separate from scrape timeout.
    public $timeout = 14400;

    public $tries = 1;

    protected ScrapeJob $jobRecord;

    public function __construct(ScrapeJob $jobRecord)
    {
        $this->jobRecord = $jobRecord;
    }

    public function handle(TelegramDeliveryService $deliveryService): void
    {
        $this->jobRecord->refresh();

        if ($this->jobRecord->delivered_at !== null) {
            Log::info("DeliverPendingFilesJob #{$this->jobRecord->id}: already delivered, skipping.");
            return;
        }

        $downloadDir = $this->jobRecord->download_dir;

        if (! $downloadDir || ! is_dir($downloadDir)) {
            Log::warning("DeliverPendingFilesJob #{$this->jobRecord->id}: download_dir không tồn tại — {$downloadDir}");
            $this->markDeliveryUnavailable('download_dir không tồn tại');
            return;
        }

        $files = glob(rtrim($downloadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.pdf') ?: [];
        sort($files);

        if (empty($files)) {
            Log::info("DeliverPendingFilesJob #{$this->jobRecord->id}: không có file PDF nào để gửi.");
            $this->markDeliveryUnavailable('không có file PDF để gửi');
            $this->notifySource("ℹ️ Không tìm thấy file PDF nào đã tải để gửi.");
            return;
        }

        $targetChatIds = $this->targetChatIds();

        Log::info("DeliverPendingFilesJob #{$this->jobRecord->id}: delivery started.", [
            'files' => count($files),
            'targets' => count($targetChatIds),
            'download_dir' => $downloadDir,
            'status' => $this->jobRecord->status,
        ]);

        $this->notifySource("📦 Tìm thấy *" . count($files) . "* file PDF đã tải. Đang gửi tới *" . count($targetChatIds) . "* nơi...");

        $sent = $this->deliverFiles($files, $targetChatIds, $deliveryService);
        $expectedSends = count($files) * count($targetChatIds);

        $updates = [
            'downloaded_count' => count($files),
            'delivered_at'     => now(),
        ];

        if (! in_array($this->jobRecord->status, ['failed', 'stopped'], true)) {
            $updates['status'] = 'completed';
        }

        $this->jobRecord->update($updates);

        Log::info("DeliverPendingFilesJob #{$this->jobRecord->id}: delivery completed.", [
            'sent' => $sent,
            'files' => count($files),
            'targets' => count($targetChatIds),
        ]);

        $this->notifySource("✅ Đã gửi *{$sent}/{$expectedSends}* lượt gửi PDF.");
    }

    protected function deliverFiles(array $files, array $targetChatIds, TelegramDeliveryService $deliveryService): int
    {
        $sent = 0;
        $fallbackChatId = $this->jobRecord->chat_id;

        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $usedFallback = false;

            foreach ($targetChatIds as $targetChatId) {
                try {
                    $deliveryService->sendDocumentToTarget([
                        'chat_id'  => $targetChatId,
                        'document' => InputFile::create($file),
                        'caption'  => "PDF DKKD\n" . basename($file),
                    ]);
                    $sent++;
                    $deliveryService->pauseBetweenDocumentSends();
                } catch (\Throwable $e) {
                    $msg = mb_strtolower($e->getMessage());
                    $targetUnavailable = str_contains($msg, 'chat not found')
                        || str_contains($msg, 'bot was blocked by the user')
                        || str_contains($msg, 'user is deactivated');

                    if ($targetUnavailable
                        && ! $usedFallback
                        && $targetChatId !== $fallbackChatId
                        && ! in_array($fallbackChatId, $targetChatIds, true)) {
                        try {
                            $deliveryService->sendDocumentToSource([
                                'chat_id'  => $fallbackChatId,
                                'document' => InputFile::create($file),
                                'caption'  => "PDF DKKD\n" . basename($file),
                            ]);
                            $sent++;
                            $usedFallback = true;
                            $deliveryService->pauseBetweenDocumentSends();
                            continue;
                        } catch (\Throwable $fallbackErr) {
                            Log::warning("DeliverPendingFilesJob: fallback send failed — " . $fallbackErr->getMessage());
                        }
                    }

                    Log::warning("DeliverPendingFilesJob: failed to send {$file} to {$targetChatId} — " . $e->getMessage());
                }
            }
        }

        return $sent;
    }

    protected function targetChatIds(): array
    {
        $targetChatIds = $this->jobRecord->target_chat_ids ?? [];

        if (empty($targetChatIds)) {
            $targetChatIds = [$this->jobRecord->target_chat_id ?: $this->jobRecord->chat_id];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($chatId) => trim((string) $chatId), $targetChatIds),
            static fn (string $chatId) => $chatId !== ''
        )));
    }

    protected function notifySource(string $text): void
    {
        try {
            Telegram::sendMessage([
                'chat_id'    => $this->jobRecord->chat_id,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Throwable $e) {
            Log::warning("DeliverPendingFilesJob: notify failed — " . $e->getMessage());
        }
    }

    protected function markDeliveryUnavailable(string $message): void
    {
        $updates = [
            'downloaded_count' => 0,
            'delivered_at' => now(),
        ];

        if (! in_array($this->jobRecord->status, ['failed', 'stopped'], true)) {
            $updates['status'] = 'completed';
        }

        $this->jobRecord->update($updates);

        Log::info("DeliverPendingFilesJob #{$this->jobRecord->id}: {$message}.");
    }
}
