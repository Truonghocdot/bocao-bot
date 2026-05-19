<?php

namespace App\Jobs;

use App\Models\ScrapeJob;
use App\Services\ScraperService;
use App\Services\TelegramLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\FileUpload\InputFile;

class RunScraperJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected const MAX_DB_ERROR_MESSAGE_LENGTH = 2000;

    /**
     * Timeout 10 phút — đủ cho Playwright cào xong + nén ZIP
     */
    public $timeout = 600;

    protected ScrapeJob $jobRecord;

    public function __construct(ScrapeJob $jobRecord)
    {
        $this->jobRecord = $jobRecord;
    }

    public function handle(ScraperService $scraperService, TelegramLogService $logService): void
    {
        // Kiểm tra nếu user đã gửi /stop trước khi queue xử lý
        $this->jobRecord->refresh();
        if ($this->jobRecord->status === 'stopped') {
            Log::info("Job #{$this->jobRecord->id} was stopped before processing started.");
            return;
        }

        try {
            $downloadKey = $this->jobRecord->download_key ?: $this->makeDownloadKey();
            $downloadDir = base_path("../scraper/downloads/{$downloadKey}");

            $this->jobRecord->update([
                'status' => 'processing',
                'download_key' => $downloadKey,
                'download_dir' => $downloadDir,
            ]);

            if ($this->jobRecord->schedule) {
                $this->jobRecord->schedule->update([
                    'last_download_key' => $downloadKey,
                    'last_download_dir' => $downloadDir,
                ]);
            }

            $this->notify("⚙️ Đang cào dữ liệu từ DKKD...\nQuá trình này có thể mất vài phút. Vui lòng chờ.");

            // Gọi Express API với params từ job record
            $result = $scraperService->runScrape(
                $this->jobRecord->from_date,
                $this->jobRecord->to_date,
                $this->jobRecord->max_records,
                $downloadKey
            );

            // Reload để kiểm tra user có /stop trong lúc đang chạy không
            $this->jobRecord->refresh();
            $wasStopped = $this->jobRecord->status === 'stopped';

            $downloaded = $result['downloaded'] ?? 0;
            $zipPath    = $result['zip'] ?? null;
            $downloadDir = $result['downloadDir'] ?? $this->jobRecord->download_dir;

            $this->jobRecord->update([
                'downloaded_count' => $downloaded,
                'download_dir'      => $downloadDir,
                'zip_path'         => $zipPath,
            ]);

            $this->deliverZip($zipPath, $downloaded, $wasStopped);

            $this->jobRecord->update([
                'status' => $wasStopped ? 'stopped' : 'completed',
                'delivered_at' => now(),
            ]);

        } catch (\Throwable $e) {
            $logService->logException($e, null, "RunScraperJob #{$this->jobRecord->id}");

            $this->jobRecord->update([
                'status'        => 'failed',
                'error_message' => $this->truncateForDatabase($e->getMessage()),
            ]);

            $this->notify("❌ Có lỗi xảy ra khi lấy dữ liệu. Vui lòng thử lại sau.");
        }
    }

    protected function makeDownloadKey(): string
    {
        return now()->format('Ymd-His') . "-job-{$this->jobRecord->id}";
    }

    protected function truncateForDatabase(string $message): string
    {
        if (strlen($message) <= self::MAX_DB_ERROR_MESSAGE_LENGTH) {
            return $message;
        }

        return substr($message, 0, self::MAX_DB_ERROR_MESSAGE_LENGTH) . '...';
    }

    /* --------------------------------------------------------
     | Gửi file ZIP về Telegram khi hoàn thành (hoặc bị stop)
     * ----------------------------------------------------- */
    protected function deliverZip(?string $zipPath, int $downloaded, bool $wasStopped): void
    {
        $prefix = $wasStopped
            ? "🛑 Đã dừng theo yêu cầu."
            : "✅ Hoàn thành!";

        if ($zipPath && file_exists($zipPath)) {
            Telegram::sendDocument([
                'chat_id'  => $this->jobRecord->chat_id,
                'document' => InputFile::create($zipPath),
                'caption'  => "{$prefix}\n📄 Đã tải: *{$downloaded}* bản công bố.\n📦 File ZIP đính kèm.",
                'parse_mode' => 'Markdown',
            ]);
        } else {
            $this->notify($downloaded > 0
                ? "{$prefix}\n📄 Đã tải {$downloaded} bản công bố nhưng *không tìm thấy file ZIP*."
                : "{$prefix}\n📭 Không tìm thấy bản công bố nào trong khoảng thời gian này."
            );
        }
    }

    /* --------------------------------------------------------
     | Helper gửi tin nhắn Markdown
     * ----------------------------------------------------- */
    protected function notify(string $text): void
    {
        try {
            Telegram::sendMessage([
                'chat_id'    => $this->jobRecord->chat_id,
                'text'       => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Throwable $e) {
            Log::warning("RunScraperJob: Failed to send Telegram message — " . $e->getMessage());
        }
    }
}
