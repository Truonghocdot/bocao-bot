<?php

namespace App\Services;

use App\Models\ScrapeJob;
use App\Models\ScrapeSchedule;
use App\Models\TelegramUser;
use App\Jobs\RunScraperJob;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramCommandService
{
    protected TelegramLogService $logService;
    protected ConversationService $conversation;

    public function __construct(
        TelegramLogService $logService,
        ConversationService $conversation
    ) {
        $this->logService   = $logService;
        $this->conversation = $conversation;
    }

    /* ===================================================================
     | ENTRY POINT
     * ================================================================= */
    public function handleCommand(string $text, string $chatId): void
    {
        try {
            $state = $this->conversation->getState($chatId);

            // Người dùng đang trong luồng hội thoại và nhập text thường (không phải lệnh)
            if ($state && !str_starts_with($text, '/')) {
                $this->handleConversationInput($chatId, $state, $text);
                return;
            }

            // Lệnh /cancel thoát mọi luồng
            if (str_starts_with($text, '/cancel')) {
                $this->conversation->clear($chatId);
                $this->send($chatId, "↩️ Đã huỷ. Gõ /start để xem các lệnh.");
                return;
            }

            match (true) {
                str_starts_with($text, '/start')    => $this->handleStart($chatId),
                str_starts_with($text, '/run')      => $this->handleRun($chatId),
                str_starts_with($text, '/stop')     => $this->handleStop($chatId),
                str_starts_with($text, '/status')   => $this->handleStatus($chatId),
                str_starts_with($text, '/schedule') => $this->handleSchedule($chatId),
                default                             => $this->handleUnknown($chatId, $text),
            };
        } catch (\Throwable $e) {
            $this->logService->logException($e, $chatId, 'Command Handler');
        }
    }

    /* ===================================================================
     | ĐIỀU HƯỚNG INPUT THEO TRẠNG THÁI
     * ================================================================= */
    protected function handleConversationInput(string $chatId, string $state, string $input): void
    {
        match ($state) {
            'await_date_run'       => $this->onRunDateInput($chatId, $input),
            'await_pages_run'      => $this->onRunPagesInput($chatId, $input),
            'await_target_run'     => $this->onRunTargetInput($chatId, $input),
            'await_time_schedule'  => $this->onScheduleTimeInput($chatId, $input),
            'await_pages_schedule' => $this->onSchedulePagesInput($chatId, $input),
            'await_target_schedule' => $this->onScheduleTargetInput($chatId, $input),
            default                => $this->conversation->clear($chatId),
        };
    }

    /* ===================================================================
     | /start
     * ================================================================= */
    protected function handleStart(string $chatId): void
    {
        $this->conversation->clear($chatId);
        $this->send($chatId, <<<TXT
        👋 *Bot DKKD Scraper* đã sẵn sàng!

        📌 *Các lệnh:*
        /run — Chạy cào dữ liệu ngay
        /schedule — Lên lịch chạy tự động
        /status — Xem trạng thái hệ thống
        /stop — Dừng tiến trình đang chạy
        /cancel — Huỷ thao tác đang nhập
        TXT);
    }

    /* ===================================================================
     | /run — Bước 1: Hỏi khoảng thời gian
     * ================================================================= */
    protected function handleRun(string $chatId): void
    {
        // Check global — scraper chỉ xử lý được 1 job tại một thời điểm
        $myJob = ScrapeJob::where('chat_id', $chatId)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($myJob) {
            $this->send($chatId, "⚠️ Bạn đang có tiến trình chạy. Dùng /stop để dừng trước, hoặc /status để kiểm tra.");
            return;
        }

        $otherJob = ScrapeJob::where('chat_id', '!=', $chatId)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($otherJob) {
            $this->send($chatId, "⏳ Hệ thống đang xử lý một yêu cầu khác. Vui lòng thử lại sau ít phút.");
            return;
        }

        $this->conversation->transition($chatId, 'await_date_run');

        $today     = now()->format('d/m/Y');
        $yesterday = now()->subDay()->format('d/m/Y');

        $this->send($chatId, <<<TXT
        📅 *Bạn muốn lấy dữ liệu trong khoảng thời gian nào?*

        Nhập theo định dạng: `dd/mm/yyyy - dd/mm/yyyy`
        Ví dụ: `{$yesterday} - {$today}`

        _Hoặc /cancel để huỷ._
        TXT);
    }

    /** /run — Nhận khoảng ngày → hỏi số trang */
    protected function onRunDateInput(string $chatId, string $input): void
    {
        $parsed = $this->parseDateRange($input);

        if (!$parsed) {
            $today     = now()->format('d/m/Y');
            $yesterday = now()->subDay()->format('d/m/Y');
            $this->send($chatId, <<<TXT
            ❌ Định dạng không hợp lệ.
            Vui lòng nhập theo dạng: `dd/mm/yyyy - dd/mm/yyyy`
            Ví dụ: `{$yesterday} - {$today}`
            TXT);
            return;
        }

        [$fromDate, $toDate] = $parsed;

        $this->conversation->transition($chatId, 'await_pages_run', [
            'from_date' => $fromDate,
            'to_date'   => $toDate,
        ]);

        $this->send($chatId, <<<TXT
        ✅ Khoảng thời gian: `{$fromDate}` → `{$toDate}`

        📄 *Bạn muốn lấy tối đa bao nhiêu trang kết quả?*
        Nhập số trang (VD: `3`, `10`) hoặc `tất cả` để lấy toàn bộ.

        _Hoặc /cancel để huỷ._
        TXT);
    }

    /** /run — Nhận số trang → hỏi chat nhận file */
    protected function onRunPagesInput(string $chatId, string $input): void
    {
        [$limit, $limitLabel] = $this->parsePages($input);
        $session = $this->conversation->get($chatId);

        $this->conversation->transition($chatId, 'await_target_run', [
            'from_date' => $session['from_date'],
            'to_date' => $session['to_date'],
            'max_records' => $limit,
            'limit_label' => $limitLabel,
        ]);

        $this->send($chatId, <<<TXT
        📤 *Bạn muốn gửi file PDF cho @username nào?*

        Nhập đúng định dạng `@username`.
        _(Tài khoản đó phải đã từng nhắn tin với bot này.)_

        _Hoặc /cancel để huỷ._
        TXT);
    }

    /** /run — Nhận chat nhận file → dispatch job */
    protected function onRunTargetInput(string $chatId, string $input): void
    {
        $session = $this->conversation->get($chatId);
        $targetChatId = $this->parseTargetChatId($input, $chatId);

        if ($targetChatId === null) {
            $this->send($chatId, "❌ Không tìm thấy tài khoản `{$input}`.\nVui lòng kiểm tra lại @username và đảm bảo tài khoản đó đã từng nhắn tin với bot này.");
            return;
        }

        $this->conversation->clear($chatId);

        $job = ScrapeJob::create([
            'chat_id'     => $chatId,
            'target_chat_id' => $targetChatId,
            'status'      => 'pending',
            'from_date'   => $session['from_date'],
            'to_date'     => $session['to_date'],
            'max_records' => $session['max_records'],
        ]);

        $job->update([
            'download_key' => $this->makeDownloadKey($job),
        ]);

        RunScraperJob::dispatch($job);

        $this->send($chatId, <<<TXT
        🚀 *Đã đưa vào hàng đợi!*
        📅 {$session['from_date']} → {$session['to_date']}
        📄 Tối đa: *{$session['limit_label']}*
        📤 Gửi PDF tới: `{$targetChatId}`

        Bot sẽ gửi từng file PDF sau khi tải xong.
        TXT);
    }

    /* ===================================================================
     | /schedule — Bước 1: Hỏi giờ chạy
     * ================================================================= */
    protected function handleSchedule(string $chatId): void
    {
        $schedule = ScrapeSchedule::where('chat_id', $chatId)->first();

        if ($schedule) {
            $limitLabel   = $schedule->max_records ? $schedule->max_records . ' trang' : 'Tất cả';
            $targetChatId = $schedule->target_chat_id ?: $chatId;
            $icon         = $schedule->is_active ? '✅' : '❌';
            $status       = $schedule->is_active ? 'BẬT' : 'TẮT';

            $this->send($chatId, <<<TXT
            🗓 *Lịch tự động hiện tại* — {$icon} {$status}
            ⏰ Giờ chạy: `{$schedule->cron_expression}`
            📄 Số trang tối đa: *{$limitLabel}*
            📤 Gửi file tới: `{$targetChatId}`

            Mỗi ngày bot sẽ tự động cào dữ liệu DKKD của *ngày hôm trước* và gửi file PDF về tài khoản trên.

            ─────────────────────
            Bạn muốn làm gì?
            • Nhập giờ mới (VD: `07:30`) để *cài đặt lại lịch*
            • Gõ /cancel để *giữ nguyên*
            TXT);

            $this->conversation->transition($chatId, 'await_time_schedule');
            return;
        }

        // Chưa có lịch → bắt đầu thiết lập
        $this->conversation->transition($chatId, 'await_time_schedule');
        $this->send($chatId, <<<TXT
        🗓 *Cài đặt lịch chạy tự động*

        Bot sẽ tự động cào dữ liệu DKKD mỗi ngày và gửi file PDF về tài khoản bạn chỉ định — không cần thao tác thủ công.

        ─────────────────────
        ⏰ *Bước 1/3 — Giờ chạy*
        Bạn muốn bot chạy vào lúc mấy giờ mỗi ngày?

        Nhập theo định dạng `HH:mm`
        Ví dụ: `07:00` → chạy lúc 7h sáng, lấy dữ liệu của ngày hôm trước

        _Hoặc /cancel để huỷ._
        TXT);
    }

    /** /schedule — Nhận giờ chạy → hỏi số trang */
    protected function onScheduleTimeInput(string $chatId, string $input): void
    {
        // Nếu user chỉ gõ /schedule để toggle (không nhập gì mới)
        // Cho phép input "toggle" hoặc "bật"/"tắt"
        $lower = mb_strtolower(trim($input));
        if (in_array($lower, ['toggle', 'bật', 'tắt', 'on', 'off'])) {
            $schedule = ScrapeSchedule::where('chat_id', $chatId)->first();
            if ($schedule) {
                $schedule->update(['is_active' => !$schedule->is_active]);
                $status = $schedule->is_active ? '✅ BẬT' : '❌ TẮT';
                $this->conversation->clear($chatId);
                $this->send($chatId, "Lịch tự động: *{$status}*");
            }
            return;
        }

        // Parse HH:mm
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($input), $matches)) {
            $this->send($chatId, "❌ Định dạng không hợp lệ. Vui lòng nhập theo dạng `HH:mm`, ví dụ: `07:00`");
            return;
        }

        $hour   = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            $this->send($chatId, "❌ Giờ không hợp lệ. Vui lòng nhập lại, ví dụ: `07:00`");
            return;
        }

        $cron = "{$minute} {$hour} * * *";

        $this->conversation->transition($chatId, 'await_pages_schedule', ['cron' => $cron, 'time' => trim($input)]);

        $this->send($chatId, <<<TXT
        ✅ Giờ chạy: *{$input}* mỗi ngày.

        ─────────────────────
        📄 *Bước 2/3 — Số trang*
        Mỗi lần chạy, bot sẽ lấy tối đa bao nhiêu trang kết quả từ DKKD?

        Nhập số trang (VD: `3`, `10`) hoặc `tất cả` để lấy toàn bộ.
        _(Lưu ý: càng nhiều trang thì thời gian chạy càng lâu.)_

        _Hoặc /cancel để huỷ._
        TXT);
    }

    /** /schedule — Nhận số trang → hỏi chat nhận file */
    protected function onSchedulePagesInput(string $chatId, string $input): void
    {
        [$limit, $limitLabel] = $this->parsePages($input);
        $session = $this->conversation->get($chatId);

        $this->conversation->transition($chatId, 'await_target_schedule', [
            'cron' => $session['cron'],
            'time' => $session['time'],
            'max_records' => $limit,
            'limit_label' => $limitLabel,
        ]);

        $this->send($chatId, <<<TXT
        ✅ Số trang tối đa: *{$limitLabel}*.

        ─────────────────────
        📤 *Bước 3/3 — Tài khoản nhận file*
        File PDF sau khi tải xong sẽ được gửi tới tài khoản nào?

        Nhập đúng định dạng `@username`.
        _(Tài khoản đó phải đã từng nhắn tin với bot này ít nhất một lần.)_

        _Hoặc /cancel để huỷ._
        TXT);
    }

    /** /schedule — Nhận chat nhận file → lưu lịch */
    protected function onScheduleTargetInput(string $chatId, string $input): void
    {
        $targetChatId = $this->parseTargetChatId($input, $chatId);

        if ($targetChatId === null) {
            $this->send($chatId, "❌ Không tìm thấy tài khoản `{$input}`.\nVui lòng kiểm tra lại @username và đảm bảo tài khoản đó đã từng nhắn tin với bot này.");
            return;
        }

        $session = $this->conversation->get($chatId);
        $this->conversation->clear($chatId);

        ScrapeSchedule::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'target_chat_id'  => $targetChatId,
                'cron_expression' => $session['cron'],
                'days_back'       => 1,
                'max_records'     => $session['max_records'],
                'is_active'       => true,
            ]
        );

        $this->send($chatId, <<<TXT
        ✅ *Lịch tự động đã được lưu!*

        ⏰ Giờ chạy: *{$session['time']}* mỗi ngày
        📅 Dữ liệu: ngày hôm trước tính từ lúc chạy
        📄 Số trang tối đa: *{$session['limit_label']}*
        📤 Gửi file tới: `{$targetChatId}`

        Bot sẽ tự động chạy theo lịch trên mà không cần thao tác thêm.
        Dùng /status để kiểm tra, /schedule để chỉnh sửa.
        TXT);
    }

    /* ===================================================================
     | /status
     * ================================================================= */
    protected function handleStatus(string $chatId): void
    {
        $schedule = ScrapeSchedule::where('chat_id', $chatId)->first();

        if ($schedule) {
            $limitLabel  = $schedule->max_records ? $schedule->max_records . ' trang' : 'Tất cả';
            $icon        = $schedule->is_active ? '✅' : '❌';
            $status      = $schedule->is_active ? 'BẬT' : 'TẮT';
            $targetChatId = $schedule->target_chat_id ?: $chatId;
            $scheduleText = "{$icon} *{$status}* — `{$schedule->cron_expression}` · {$limitLabel} · gửi `{$targetChatId}`";
        } else {
            $scheduleText = "_Chưa cấu hình — dùng /schedule_";
        }

        $jobs = ScrapeJob::where('chat_id', $chatId)->latest()->take(5)->get();

        $emoji = [
            'pending'    => '🕐',
            'processing' => '⚙️',
            'completed'  => '✅',
            'failed'     => '❌',
            'stopped'    => '🛑',
        ];

        $historyText = $jobs->isEmpty()
            ? "_Chưa có lần chạy nào._"
            : $jobs->map(function ($job) use ($emoji) {
                $icon  = $emoji[$job->status] ?? '❓';
                $time  = $job->created_at->format('d/m H:i');
                $range = $job->from_date ? " `{$job->from_date}→{$job->to_date}`" : '';
                $extra = $job->status === 'completed' ? " · {$job->downloaded_count} bản" : '';
                return "{$icon} {$time}{$range}{$extra}";
            })->implode("\n");

        $this->send($chatId, <<<TXT
        📊 *Trạng thái hệ thống*

        🗓 Lịch tự động: {$scheduleText}

        📋 *5 lần chạy gần nhất:*
        {$historyText}
        TXT);
    }

    /* ===================================================================
     | /stop
     * ================================================================= */
    protected function handleStop(string $chatId): void
    {
        $activeJob = ScrapeJob::where('chat_id', $chatId)
            ->whereIn('status', ['pending', 'processing'])
            ->latest()->first();

        if (!$activeJob) {
            $this->send($chatId, "ℹ️ Không có tiến trình nào đang chạy.");
            return;
        }

        $activeJob->update(['status' => 'stopped']);
        $this->send($chatId, "🛑 *Đã yêu cầu dừng.*\nBot sẽ gửi các file PDF đã tải được.");
    }

    /* ===================================================================
     | Lệnh không hợp lệ
     * ================================================================= */
    protected function handleUnknown(string $chatId, string $text): void
    {
        if (empty($text)) return;
        $this->send($chatId, "❓ Lệnh không được nhận dạng. Gõ /start để xem danh sách lệnh.");
    }

    /* ===================================================================
     | HELPERS
     * ================================================================= */

    /**
     * Parse "dd/mm/yyyy - dd/mm/yyyy" → ['dd/mm/yyyy', 'dd/mm/yyyy'] hoặc null
     */
    protected function parseDateRange(string $input): ?array
    {
        // Hỗ trợ các dấu phân cách: " - ", " to ", " → "
        $parts = preg_split('/\s*(-|to|→)\s*/u', trim($input), 2);

        if (count($parts) !== 2) return null;

        [$from, $to] = array_map('trim', $parts);

        if (!$this->isValidDate($from) || !$this->isValidDate($to)) return null;

        return [$from, $to];
    }

    protected function isValidDate(string $date): bool
    {
        return (bool) preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date);
    }

    /**
     * Parse số trang: "0" / "tất cả" / "all" → null; số dương → int
     * @return array{0: int|null, 1: string}
     */
    protected function parsePages(string $input): array
    {
        $lower = mb_strtolower(trim($input));

        if (in_array($lower, ['0', 'tất cả', 'tat ca', 'all'])) {
            return [null, 'Tất cả'];
        }

        $n = (int) $input;
        return [$n > 0 ? $n : null, $n > 0 ? "{$n} trang" : 'Tất cả'];
    }

    /**
     * Resolve @username → numeric chat_id bằng cách tra cứu trong bảng telegram_users.
     * Bảng này được populate mỗi khi user nhắn tin với bot.
     * Trả về chat_id dạng string số nếu tìm thấy, null nếu không hợp lệ hoặc chưa có trong DB.
     */
    protected function parseTargetChatId(string $input, string $currentChatId): ?string
    {
        $trimmed = trim($input);

        if (!preg_match('/^@[A-Za-z0-9_]{5,32}$/', $trimmed)) {
            return null;
        }

        $user = TelegramUser::findByUsername($trimmed);

        if (!$user) {
            Log::warning("parseTargetChatId: username '{$trimmed}' not found in telegram_users table.");
            return null;
        }

        return $user->chat_id;
    }

    protected function makeDownloadKey(ScrapeJob $job): string
    {
        return now()->format('Ymd-His') . "-job-{$job->id}";
    }

    /**
     * Gửi Markdown — xóa indent heredoc
     */
    protected function send(string $chatId, string $text): void
    {
        $cleaned = preg_replace('/^[ \t]+/m', '', $text);

        try {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $cleaned,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram markdown parse failed, fallback to plain text', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
                'text' => $cleaned,
            ]);

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $cleaned,
            ]);
        }
    }
}
