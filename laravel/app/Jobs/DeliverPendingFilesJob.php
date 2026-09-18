<?php

namespace App\Jobs;

use App\Models\ScrapeJob;
use App\Models\ScrapeSnapshotFile;
use App\Services\TelegramDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Telegram\Bot\Laravel\Facades\Telegram;

class DeliverPendingFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 3;

    public function __construct(protected ScrapeJob $jobRecord)
    {
        $this->onQueue('telegram-delivery');
    }

    public function handle(TelegramDeliveryService $deliveryService): void
    {
        $this->jobRecord->refresh();

        if ($this->jobRecord->delivered_at !== null || $this->jobRecord->status === 'stopped') {
            return;
        }

        $files = $this->filesForJob();
        $targets = $this->targetChatIds();

        if ($files === [] || $targets === []) {
            $this->markDeliveryUnavailable('Không có file hoặc nơi nhận hợp lệ.');

            return;
        }

        $totalAttempts = count($files) * count($targets);
        $cursor = min((int) $this->jobRecord->delivery_cursor, $totalAttempts);
        $chunkSize = max(1, (int) config('services.telegram_delivery.chunk_size', 5));
        $chunkEnd = min($cursor + $chunkSize, $totalAttempts);

        if ($cursor === 0) {
            $this->notifySource(
                '📤 Bắt đầu gửi *'.count($files).'* PDF tới *'.count($targets).'* nơi nhận.'
            );
        }

        for ($position = $cursor; $position < $chunkEnd; $position++) {
            $this->jobRecord->refresh();
            if ($this->jobRecord->status === 'stopped') {
                return;
            }

            $fileIndex = intdiv($position, count($targets));
            $targetIndex = $position % count($targets);
            $file = $files[$fileIndex];
            $target = $targets[$targetIndex];

            try {
                $this->deliverOne($file, $target, $targets, $deliveryService);
                $this->jobRecord->increment('sent_count');
                $deliveryService->pauseBetweenDocumentSends();
            } catch (\Throwable $exception) {
                $this->jobRecord->increment('failed_count');
                $this->jobRecord->update([
                    'delivery_error_message' => Str::limit($exception->getMessage(), 2000, '...'),
                ]);
                Log::warning('Telegram document delivery failed.', [
                    'job_id' => $this->jobRecord->id,
                    'target_chat_id' => $target,
                    'filename' => basename($file),
                    'error' => $exception->getMessage(),
                ]);
            } finally {
                $this->jobRecord->update(['delivery_cursor' => $position + 1]);
            }
        }

        $this->jobRecord->refresh();
        if ((int) $this->jobRecord->delivery_cursor < $totalAttempts) {
            self::dispatch($this->jobRecord)->delay(now()->addSecond());

            return;
        }

        $hasFailures = (int) $this->jobRecord->failed_count > 0;
        $this->jobRecord->update([
            'status' => $hasFailures ? 'completed_with_errors' : 'completed',
            'delivered_at' => now(),
        ]);

        $this->notifySource(
            $hasFailures
                ? "⚠️ Đã gửi *{$this->jobRecord->sent_count}/{$totalAttempts}* lượt PDF; *{$this->jobRecord->failed_count}* lượt lỗi."
                : "✅ Đã gửi đủ *{$this->jobRecord->sent_count}/{$totalAttempts}* lượt PDF."
        );
    }

    protected function deliverOne(
        string $file,
        string $targetChatId,
        array $targetChatIds,
        TelegramDeliveryService $deliveryService
    ): void {
        try {
            $deliveryService->sendDocumentToTarget([
                'chat_id' => $targetChatId,
                'document_path' => $file,
                'caption' => "PDF DKKD\n".basename($file),
            ]);
        } catch (\Throwable $exception) {
            $message = mb_strtolower($exception->getMessage());
            $targetUnavailable = str_contains($message, 'chat not found')
                || str_contains($message, 'bot was blocked by the user')
                || str_contains($message, 'user is deactivated');
            $sourceChatId = (string) $this->jobRecord->chat_id;

            if (
                ! $targetUnavailable
                || $targetChatId === $sourceChatId
                || in_array($sourceChatId, $targetChatIds, true)
            ) {
                throw $exception;
            }

            $deliveryService->sendDocumentToSource([
                'chat_id' => $sourceChatId,
                'document_path' => $file,
                'caption' => "PDF DKKD\n".basename($file),
            ]);
        }
    }

    /**
     * Kept as a focused delivery primitive for tests and legacy callers.
     */
    protected function deliverFiles(array $files, array $targetChatIds, TelegramDeliveryService $deliveryService): int
    {
        $sent = 0;

        foreach ($files as $file) {
            foreach ($targetChatIds as $targetChatId) {
                $this->deliverOne($file, $targetChatId, $targetChatIds, $deliveryService);
                $sent++;
                $deliveryService->pauseBetweenDocumentSends();
            }
        }

        return $sent;
    }

    /**
     * @return string[]
     */
    protected function filesForJob(): array
    {
        if ($snapshot = $this->jobRecord->snapshot) {
            $query = $snapshot->files();
            if ($this->jobRecord->max_records !== null) {
                $query->where('page_number', '<=', $this->jobRecord->max_records);
            }

            $downloadDir = rtrim((string) $snapshot->download_dir, DIRECTORY_SEPARATOR);

            return $query->get()
                ->filter(fn (ScrapeSnapshotFile $file): bool => ScrapeSnapshotFile::isValidPdfPath(
                    $downloadDir.DIRECTORY_SEPARATOR.$file->relative_path
                ))
                ->map(fn (ScrapeSnapshotFile $file): string => $downloadDir.DIRECTORY_SEPARATOR.$file->relative_path)
                ->values()
                ->all();
        }

        $downloadDir = $this->jobRecord->download_dir;
        if (! $downloadDir || ! is_dir($downloadDir)) {
            return [];
        }

        $files = glob(rtrim($downloadDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.pdf') ?: [];
        sort($files);

        return $files;
    }

    protected function targetChatIds(): array
    {
        $targetChatIds = $this->jobRecord->target_chat_ids ?? [];

        if ($targetChatIds === []) {
            $targetChatIds = [$this->jobRecord->target_chat_id ?: $this->jobRecord->chat_id];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($chatId): string => trim((string) $chatId), $targetChatIds),
            static fn (string $chatId): bool => $chatId !== ''
        )));
    }

    protected function notifySource(string $text): void
    {
        try {
            Telegram::sendMessage([
                'chat_id' => $this->jobRecord->chat_id,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Throwable $exception) {
            Log::warning('DeliverPendingFilesJob: source notification failed.', [
                'job_id' => $this->jobRecord->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function markDeliveryUnavailable(string $message): void
    {
        $this->jobRecord->update([
            'status' => 'completed_with_errors',
            'delivered_at' => now(),
            'delivery_error_message' => $message,
        ]);
        $this->notifySource('⚠️ Không tìm thấy file PDF hoặc nơi nhận hợp lệ để giao.');
    }
}
