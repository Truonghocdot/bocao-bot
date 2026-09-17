<?php

namespace App\Jobs;

use App\Models\ScrapeJob;
use App\Services\ScrapeSnapshotService;
use App\Services\TelegramLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Telegram\Bot\Laravel\Facades\Telegram;

class RunScraperJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 14400;

    public int $tries = 1;

    public function __construct(protected ScrapeJob $jobRecord)
    {
        $this->onQueue('scrape');
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(5);
    }

    public function handle(
        ScrapeSnapshotService $snapshotService,
        TelegramLogService $logService
    ): void {
        $this->jobRecord->refresh();

        if ($this->jobRecord->status === 'stopped') {
            return;
        }

        $this->jobRecord->update(['status' => 'waiting_snapshot']);
        $this->notify("🔎 Đang kiểm tra snapshot dữ liệu DKKD...\nBot sẽ dùng lại file đã có nếu dữ liệu còn mới.");

        try {
            $resolved = $snapshotService->resolve(
                (string) $this->jobRecord->from_date,
                (string) $this->jobRecord->to_date,
                $this->jobRecord->max_records
            );

            $snapshot = $resolved['snapshot'];
            $cacheHit = (bool) $resolved['cache_hit'];

            $this->jobRecord->refresh();
            if ($this->jobRecord->status === 'stopped') {
                Log::info("RunScraperJob #{$this->jobRecord->id}: delivery was stopped while resolving snapshot.");

                return;
            }

            $fileQuery = $snapshot->files();
            if ($this->jobRecord->max_records !== null) {
                $fileQuery->where('page_number', '<=', $this->jobRecord->max_records);
            }
            $fileCount = $fileQuery->count();

            $this->jobRecord->update([
                'scrape_snapshot_id' => $snapshot->id,
                'status' => $fileCount > 0 ? 'delivering' : 'completed',
                'download_key' => $snapshot->download_key,
                'download_dir' => $snapshot->download_dir,
                'downloaded_count' => $fileCount,
                'delivery_cursor' => 0,
                'sent_count' => 0,
                'failed_count' => 0,
                'delivered_at' => $fileCount > 0 ? null : now(),
                'error_message' => null,
                'delivery_error_message' => null,
            ]);

            if ($this->jobRecord->schedule) {
                $this->jobRecord->schedule->update([
                    'last_download_key' => $snapshot->download_key,
                    'last_download_dir' => $snapshot->download_dir,
                ]);
            }

            if ($fileCount === 0) {
                $this->notify('ℹ️ Không có dữ liệu PDF trong khoảng ngày đã chọn.');

                return;
            }

            $source = $cacheHit ? 'snapshot có sẵn' : 'snapshot mới';
            $this->notify("📦 Đã chuẩn bị *{$fileCount}* PDF từ {$source}. Bắt đầu xếp hàng gửi file.");

            DeliverPendingFilesJob::dispatch($this->jobRecord);
        } catch (\Throwable $exception) {
            $logService->logException($exception, null, "RunScraperJob #{$this->jobRecord->id}");
            $this->jobRecord->refresh();

            if ($this->jobRecord->status === 'stopped') {
                return;
            }

            $this->jobRecord->update([
                'status' => 'failed',
                'error_message' => Str::limit($exception->getMessage(), 2000, '...'),
            ]);
            $this->notify($this->buildFailureMessage($exception));

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->jobRecord->refresh();

        if (! in_array($this->jobRecord->status, ['stopped', 'failed'], true)) {
            $this->jobRecord->update([
                'status' => 'failed',
                'error_message' => Str::limit($exception->getMessage(), 2000, '...'),
            ]);
        }
    }

    protected function buildFailureMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'DKKD_EMPTY_RESULT')) {
            return 'ℹ️ Không có dữ liệu được trả về từ trang đăng ký kinh doanh.';
        }

        if (str_contains($message, 'DKKD_SITE_ERROR') || str_contains($message, 'DKKD_AUTH_REDIRECT')) {
            return '❌ Trang tra cứu DKKD đang gặp lỗi hoặc chặn truy cập. Vui lòng thử lại sau.';
        }

        if (str_contains(mb_strtolower($message), 'timeout')) {
            return '❌ Quá thời gian chờ khi kết nối tới trang tra cứu DKKD. Vui lòng thử lại sau.';
        }

        return '❌ Không thể chuẩn bị snapshot PDF đầy đủ. Snapshot cũ vẫn được giữ nguyên.';
    }

    protected function notify(string $text): void
    {
        try {
            Telegram::sendMessage([
                'chat_id' => $this->jobRecord->chat_id,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Throwable $exception) {
            Log::warning('RunScraperJob: failed to notify source chat.', [
                'job_id' => $this->jobRecord->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
