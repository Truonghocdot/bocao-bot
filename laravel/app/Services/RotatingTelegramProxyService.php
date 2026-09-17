<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RotatingTelegramProxyService
{
    private const CACHE_KEY = 'telegram_delivery_proxy';

    /**
     * @return array{server: string, username?: string, password?: string}|null
     */
    public function currentProxy(): ?array
    {
        if (! (bool) config('services.telegram_proxy.enabled', false)) {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && filled($cached['server'] ?? null)) {
            return $cached;
        }

        return Cache::lock(self::CACHE_KEY.':lock', 30)->block(10, function (): array {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && filled($cached['server'] ?? null)) {
                return $cached;
            }

            return $this->rotate();
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{server: string, username?: string, password?: string}
     */
    private function rotate(): array
    {
        $apiKey = trim((string) config('services.telegram_proxy.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('Telegram proxy API key is missing.');
        }

        $response = Http::acceptJson()
            ->timeout(20)
            ->get((string) config('services.telegram_proxy.provider_url'), [
                'key' => $apiKey,
                'nhamang' => (string) config('services.telegram_proxy.carrier', 'random'),
                'tinhthanh' => (string) config('services.telegram_proxy.province', '0'),
                'whitelist' => (string) config('services.telegram_proxy.whitelist', ''),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Proxy provider returned HTTP {$response->status()}.");
        }

        $payload = $response->json();
        if (! is_array($payload) || (int) ($payload['status'] ?? 0) !== 100) {
            $status = is_array($payload) ? (string) ($payload['status'] ?? 'unknown') : 'invalid-json';
            $message = is_array($payload) ? (string) ($payload['message'] ?? 'request rejected') : 'invalid JSON';

            throw new RuntimeException("Proxy provider status {$status}: {$message}");
        }

        $proxy = $this->parseProxy((string) ($payload['proxyhttp'] ?? ''));
        $ttl = max(5, (int) config('services.telegram_proxy.ttl_seconds', 60));
        Cache::put(self::CACHE_KEY, $proxy, now()->addSeconds($ttl));

        return $proxy;
    }

    /**
     * @return array{server: string, username?: string, password?: string}
     */
    private function parseProxy(string $value): array
    {
        $parts = explode(':', trim($value), 4);
        $host = trim($parts[0] ?? '');
        $port = filter_var($parts[1] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host) === 1;

        if (! $validHost || $port === false) {
            throw new RuntimeException('Proxy provider returned an invalid HTTP proxy endpoint.');
        }

        $proxy = ['server' => "http://{$host}:{$port}"];
        $username = trim($parts[2] ?? '');
        $password = trim($parts[3] ?? '');

        if ($username !== '') {
            $proxy['username'] = $username;
        }
        if ($password !== '') {
            $proxy['password'] = $password;
        }

        return $proxy;
    }
}
