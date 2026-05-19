<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Quản lý trạng thái hội thoại nhiều bước (multi-step chat) cho từng user
 * Dùng Laravel Cache — TTL 10 phút mỗi bước.
 *
 * States:
 *   await_days_run           → /run: chờ số ngày
 *   await_limit_run          → /run: chờ số bản muốn lấy
 *   await_days_schedule      → /schedule: chờ số ngày
 *   await_limit_schedule     → /schedule: chờ số bản
 *   await_cron_schedule      → /schedule: chờ biểu thức cron
 */
class ConversationService
{
    protected const TTL = 600; // giây

    protected function key(string $chatId): string
    {
        return "tg_session_{$chatId}";
    }

    /* ---- Đọc / Ghi ---- */

    public function get(string $chatId): array
    {
        return Cache::get($this->key($chatId), []);
    }

    public function set(string $chatId, array $data): void
    {
        Cache::put($this->key($chatId), $data, self::TTL);
    }

    public function clear(string $chatId): void
    {
        Cache::forget($this->key($chatId));
    }

    public function getState(string $chatId): ?string
    {
        return $this->get($chatId)['state'] ?? null;
    }

    /**
     * Cập nhật 1 phần dữ liệu session (merge)
     */
    public function merge(string $chatId, array $patch): void
    {
        $current = $this->get($chatId);
        $this->set($chatId, array_merge($current, $patch));
    }

    public function transition(string $chatId, string $state, array $extra = []): void
    {
        $this->merge($chatId, array_merge(['state' => $state], $extra));
    }
}
