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
    protected const DKKD_SITE_ERROR_CODE = 'DKKD_SITE_ERROR';
    protected bool $targetChatUnavailable = false;

    /**
     * Click-based scraping and per-file Telegram delivery can take a while.
     */
    public $timeout = 3600;

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
            $files      = $result['files'] ?? [];
            $downloadDir = $result['downloadDir'] ?? $this->jobRecord->download_dir;

            $this->jobRecord->update([
                'downloaded_count' => $downloaded,
                'download_dir'      => $downloadDir,
                'zip_path'         => null,
            ]);

            $sent = $this->deliverFiles($files, $wasStopped);

            $this->jobRecord->update([
                'status' => $wasStopped ? 'stopped' : 'completed',
                'delivered_at' => now(),
            ]);

            $this->notify("✅ Đã gửi {$sent}/{$downloaded} file PDF.");

        } catch (\Throwable $e) {
            $logService->logException($e, null, "RunScraperJob #{$this->jobRecord->id}");

            $partialFiles = $this->collectDownloadedFiles();
            $sent = count($partialFiles) > 0 ? $this->deliverFiles($partialFiles, false) : 0;

            $this->jobRecord->update([
                'status'        => 'failed',
                'downloaded_count' => count($partialFiles),
                'error_message' => $this->truncateForDatabase($e->getMessage()),
                'delivered_at' => $sent > 0 ? now() : null,
            ]);

            $this->notify($sent > 0
                ? "⚠️ Có lỗi xảy ra khi lấy dữ liệu. Bot đã gửi {$sent} file PDF tải được trước khi lỗi."
                : $this->buildFailureMessage($e)
            );
        }
    }

    protected function buildFailureMessage(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, self::DKKD_SITE_ERROR_CODE)) {
            return "❌ Trang tra cứu DKKD đang gặp lỗi (chuyển sang trang báo lỗi). Vui lòng thử lại sau.";
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

    protected function targetChatId(): string
    {
        return $this->jobRecord->target_chat_id ?: $this->jobRecord->chat_id;
    }

    /**
     * Gửi từng file PDF về Telegram.
     */
    protected function deliverFiles(array $files, bool $wasStopped): int
    {
        $sent = 0;
        $targetChatId = $this->targetChatId();
        $fallbackChatId = $this->jobRecord->chat_id;
        $prefix = $wasStopped ? "Đã dừng theo yêu cầu." : "PDF DKKD";

        foreach ($files as $file) {
            if (! is_string($file) || ! file_exists($file)) {
                continue;
            }

            try {
                Telegram::sendDocument([
                    'chat_id'  => $targetChatId,
                    'document' => InputFile::create($file),
                    'caption'  => "{$prefix}\n" . basename($file),
                ]);
                $sent++;
                usleep(250000);
            } catch (\Throwable $e) {
                if ($this->shouldFallbackToSourceChat($e, $targetChatId, $fallbackChatId)) {
                    try {
                        Telegram::sendDocument([
                            'chat_id'  => $fallbackChatId,
                            'document' => InputFile::create($file),
                            'caption'  => "{$prefix}\n" . basename($file),
                        ]);
                        $sent++;
                        usleep(250000);
                        continue;
                    } catch (\Throwable $fallbackError) {
                        Log::warning("RunScraperJob: Failed fallback send PDF {$file} — " . $fallbackError->getMessage());
                    }
                }

                Log::warning("RunScraperJob: Failed to send PDF {$file} — " . $e->getMessage());
            }
        }

        return $sent;
    }

    protected function shouldFallbackToSourceChat(\Throwable $e, string $targetChatId, string $fallbackChatId): bool
    {
        if ($targetChatId === $fallbackChatId) {
            return false;
        }

        $message = mb_strtolower($e->getMessage());
        $isTargetUnavailable = str_contains($message, 'chat not found')
            || str_contains($message, 'bot was blocked by the user')
            || str_contains($message, 'user is deactivated');

        if (! $isTargetUnavailable) {
            return false;
        }

        if (! $this->targetChatUnavailable) {
            Log::warning("RunScraperJob: Target chat '{$targetChatId}' unavailable, fallback to source chat '{$fallbackChatId}'.");
            $this->targetChatUnavailable = true;
        }

        return true;
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
