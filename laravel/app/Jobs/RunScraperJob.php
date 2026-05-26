<?php

namespace App\Jobs;

use App\Jobs\DeliverPendingFilesJob;
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

class RunScraperJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected const MAX_DB_ERROR_MESSAGE_LENGTH = 2000;
    protected const DKKD_SITE_ERROR_CODE = 'DKKD_SITE_ERROR';
    protected const DKKD_AUTH_REDIRECT_CODE = 'DKKD_AUTH_REDIRECT';

    /**
     * Scrape job only waits for the Express scraper. Telegram delivery runs
     * in DeliverPendingFilesJob so scraper timeout is not mixed with send time.
     */
    public $timeout = 14400; // 4 tiếng

    /**
     * Không retry — scraper không idempotent, retry sẽ chạy lại từ đầu
     * và có thể conflict với job đang chạy trên Express.
     */
    public $tries = 1;

    protected ScrapeJob $jobRecord;

    public function __construct(ScrapeJob $jobRecord)
    {
        $this->jobRecord = $jobRecord;
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(5);
    }

    public function handle(
        ScraperService $scraperService,
        TelegramLogService $logService
    ): void
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

            $this->notify("⚙️ Đang cào dữ liệu từ DKKD...\nBot sẽ gửi từng file PDF sau khi tải xong.");

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
            $downloadDir = $result['downloadDir'] ?? $this->jobRecord->download_dir;

            $this->jobRecord->update([
                'status' => $wasStopped ? 'stopped' : 'delivering',
                'downloaded_count' => $downloaded,
                'download_dir'      => $downloadDir,
                'zip_path'         => null,
                'error_message'    => null,
            ]);

            Log::info("RunScraperJob #{$this->jobRecord->id}: scrape completed, dispatching delivery.", [
                'downloaded' => $downloaded,
                'download_dir' => $downloadDir,
                'stopped' => $wasStopped,
            ]);

            DeliverPendingFilesJob::dispatch($this->jobRecord);
        } catch (\Throwable $e) {
            $logService->logException($e, null, "RunScraperJob #{$this->jobRecord->id}");

            $partialFiles = $this->collectDownloadedFiles();
            $partialCount = count($partialFiles);

            $this->jobRecord->update([
                'status'           => 'failed',
                'downloaded_count' => $partialCount,
                'error_message'    => $this->truncateForDatabase($e->getMessage()),
            ]);

            if ($partialCount > 0) {
                Log::info("RunScraperJob #{$this->jobRecord->id}: scraper failed but found partial files, dispatching delivery.", [
                    'partial_files' => $partialCount,
                    'download_dir' => $this->jobRecord->download_dir,
                ]);

                DeliverPendingFilesJob::dispatch($this->jobRecord);
            }

            $this->notify($partialCount > 0
                ? "⚠️ Có lỗi xảy ra khi lấy dữ liệu. Bot tìm thấy {$partialCount} file PDF đã tải và sẽ gửi các file này."
                : $this->buildFailureMessage($e)
            );

            // Re-throw only when there are no files to recover; partial files
            // are handled by DeliverPendingFilesJob above.
            if ($partialCount === 0) {
                throw $e;
            }
        }
    }

    protected function buildFailureMessage(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'SCRAPER_BUSY')) {
            return "⏳ Hệ thống scraper đang bận xử lý một yêu cầu khác. Vui lòng thử lại sau ít phút.";
        }

        if (str_contains($msg, self::DKKD_SITE_ERROR_CODE)) {
            return "❌ Trang tra cứu DKKD đang gặp lỗi (chuyển sang trang báo lỗi). Vui lòng thử lại sau.";
        }

        if (str_contains($msg, self::DKKD_AUTH_REDIRECT_CODE)) {
            return "❌ Trang tra cứu DKKD đang chặn truy cập. Vui lòng thử lại sau.";
        }

        // Playwright timeout — thường do trang DKKD không phản hồi hoặc bị lỗi phía họ
        if (str_contains($msg, 'waitForURL') || str_contains($msg, 'waitForNavigation')
            || str_contains($msg, 'Timeout') && str_contains($msg, 'egazette')) {
            return "❌ Trang tra cứu DKKD không phản hồi (timeout). Trang có thể đang bảo trì hoặc quá tải. Vui lòng thử lại sau ít phút.";
        }

        if (str_contains($msg, 'Timeout') || str_contains($msg, 'timeout')) {
            return "❌ Quá thời gian chờ khi kết nối tới trang tra cứu DKKD. Vui lòng thử lại sau.";
        }

        return "❌ Có lỗi xảy ra khi lấy dữ liệu. Vui lòng thử lại sau.";
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

    protected function collectDownloadedFiles(): array
    {
        $downloadDir = $this->jobRecord->download_dir;

        if (! $downloadDir || ! is_dir($downloadDir)) {
            return [];
        }

        $files = glob(rtrim($downloadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.pdf') ?: [];
        sort($files);

        return $files;
    }

    /* --------------------------------------------------------
     | Laravel gọi failed() khi job bị timeout hoặc exception
     | không được catch — dispatch recovery job để gửi file
     | đã tải được trước khi bị kill.
     * ----------------------------------------------------- */
    public function failed(\Throwable $exception): void
    {
        Log::warning("RunScraperJob #{$this->jobRecord->id} failed: " . $exception->getMessage());

        $this->jobRecord->refresh();

        // Delivery already happened or was queued by handle().
        if ($this->jobRecord->delivered_at !== null) {
            return;
        }

        // Có file trên disk nhưng chưa gửi → dispatch recovery.
        if ($this->jobRecord->download_dir && is_dir($this->jobRecord->download_dir)) {
            $files = glob(rtrim($this->jobRecord->download_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.pdf') ?: [];

            if (count($files) > 0) {
                $status = $this->jobRecord->status === 'stopped' ? 'stopped' : 'failed';

                $this->jobRecord->update([
                    'status' => $status,
                    'downloaded_count' => count($files),
                    'error_message' => $this->truncateForDatabase($exception->getMessage()),
                ]);
                DeliverPendingFilesJob::dispatch($this->jobRecord);
                Log::info("RunScraperJob #{$this->jobRecord->id}: dispatched DeliverPendingFilesJob — " . count($files) . " files found.");
                return;
            }
        }

        // Không có file → chỉ notify lỗi
        $this->jobRecord->update(['status' => 'failed']);
        $this->notify($this->buildFailureMessage($exception));
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
