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
 * Recovery job: scan download_dir của một ScrapeJob đã failed/timeout
 * và gửi toàn bộ file PDF đã tải được về Telegram.
 *
 * Trigger tự động qua RunScraperJob::failed() hoặc thủ công qua /status.
 */
class DeliverPendingFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Gửi 1730 file × 0.25s delay + overhead Telegram = ~40 phút
    public $timeout = 3600;

    protected ScrapeJob $jobRecord;

    public function __construct(ScrapeJob $jobRecord)
    {
        $this->jobRecord = $jobRecord;
    }

    public function handle(TelegramDeliveryService $deliveryService): void
    {
        $this->jobRecord->refresh();

        $downloadDir = $this->jobRecord->download_dir;

        if (! $downloadDir || ! is_dir($downloadDir)) {
            Log::warning("DeliverPendingFilesJob #{$this->jobRecord->id}: download_dir không tồn tại — {$downloadDir}");
            return;
        }

        $files = glob(rtrim($downloadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.pdf') ?: [];
        sort($files);

        if (empty($files)) {
            Log::info("DeliverPendingFilesJob #{$this->jobRecord->id}: không có file PDF nào để gửi.");
            $this->notifySource("ℹ️ Không tìm thấy file PDF nào đã tải để gửi lại.");
            return;
        }

        Log::info("DeliverPendingFilesJob #{$this->jobRecord->id}: gửi {$this->jobRecord->id} file từ {$downloadDir}");

        $this->notifySource("📦 Tìm thấy *" . count($files) . "* file PDF đã tải. Đang gửi...");

        $sent = $this->deliverFiles($files, $deliveryService);

        $this->jobRecord->update([
            'downloaded_count' => count($files),
            'delivered_at'     => now(),
        ]);

        $this->notifySource("✅ Đã gửi lại *{$sent}/" . count($files) . "* file PDF.");
    }

    protected function deliverFiles(array $files, TelegramDeliveryService $deliveryService): int
    {
        $sent           = 0;
        $targetChatId   = $this->jobRecord->target_chat_id ?: $this->jobRecord->chat_id;
        $fallbackChatId = $this->jobRecord->chat_id;

        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            try {
                $deliveryService->sendDocumentToTarget([
                    'chat_id'  => $targetChatId,
                    'document' => InputFile::create($file),
                    'caption'  => "PDF DKKD\n" . basename($file),
                ]);
                $sent++;
                usleep(250000);
            } catch (\Throwable $e) {
                $msg = mb_strtolower($e->getMessage());
                $targetUnavailable = str_contains($msg, 'chat not found')
                    || str_contains($msg, 'bot was blocked by the user')
                    || str_contains($msg, 'user is deactivated');

                if ($targetUnavailable && $targetChatId !== $fallbackChatId) {
                    try {
                        $deliveryService->sendDocumentToSource([
                            'chat_id'  => $fallbackChatId,
                            'document' => InputFile::create($file),
                            'caption'  => "PDF DKKD\n" . basename($file),
                        ]);
                        $sent++;
                        usleep(250000);
                        continue;
                    } catch (\Throwable $fallbackErr) {
                        Log::warning("DeliverPendingFilesJob: fallback send failed — " . $fallbackErr->getMessage());
                    }
                }

                Log::warning("DeliverPendingFilesJob: failed to send {$file} — " . $e->getMessage());
            }
        }

        return $sent;
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
}
