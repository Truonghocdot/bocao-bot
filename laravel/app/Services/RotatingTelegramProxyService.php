<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
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

        $state = $this->cachedState();
        if (now()->timestamp < ($state['rotate_after'] ?? 0)) {
            return $this->proxyFromState($state);
        }

        return Cache::lock(self::CACHE_KEY.':lock', 30)->block(10, function (): ?array {
            $state = $this->cachedState();
            if (now()->timestamp < ($state['rotate_after'] ?? 0)) {
                return $this->proxyFromState($state);
            }

            return $this->rotate($state);
        });
    }

    public function forget(): void
    {
        $state = $this->cachedState();
        unset($state['server'], $state['username'], $state['password']);
        Cache::forever(self::CACHE_KEY, $state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{server: string, username?: string, password?: string}|null
     */
    private function rotate(array $state): ?array
    {
        $apiKey = trim((string) config('services.telegram_proxy.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('Telegram proxy API key is missing.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->get((string) config('services.telegram_proxy.provider_url'), [
                    'key' => $apiKey,
                    'nhamang' => (string) config('services.telegram_proxy.carrier', 'random'),
                    'tinhthanh' => (string) config('services.telegram_proxy.province', '0'),
                    'whitelist' => (string) config('services.telegram_proxy.whitelist', ''),
                ]);
        } catch (ConnectionException) {
            $this->keepStateUntil($state, $this->rotationInterval());

            if ($proxy = $this->proxyFromState($state)) {
                return $proxy;
            }

            throw new RuntimeException('Could not connect to the proxy provider; will retry later.');
        }

        if (! $response->successful()) {
            $this->keepStateUntil($state, $this->rotationInterval());

            if ($proxy = $this->proxyFromState($state)) {
                return $proxy;
            }

            throw new RuntimeException("Proxy provider returned HTTP {$response->status()}.");
        }

        $payload = $response->json();
        $status = is_array($payload) ? (int) ($payload['status'] ?? 0) : 0;
        if ($status === 101) {
            $message = (string) ($payload['message'] ?? '');
            $this->keepStateUntil($state, $this->cooldownSeconds($message));

            return $this->proxyFromState($state);
        }

        if ($status !== 100) {
            $this->keepStateUntil($state, $this->rotationInterval());

            if ($proxy = $this->proxyFromState($state)) {
                return $proxy;
            }

            $message = is_array($payload) ? (string) ($payload['message'] ?? 'request rejected') : 'invalid JSON';
            throw new RuntimeException("Proxy provider status {$status}: ".str_replace($apiKey, '[redacted]', $message));
        }

        try {
            $proxy = $this->parseProxy((string) ($payload['proxyhttp'] ?? ''));
        } catch (RuntimeException $exception) {
            $this->keepStateUntil($state, $this->rotationInterval());

            if ($previousProxy = $this->proxyFromState($state)) {
                return $previousProxy;
            }

            throw $exception;
        }

        Cache::forever(self::CACHE_KEY, [
            ...$proxy,
            'rotate_after' => now()->timestamp + $this->rotationInterval(),
        ]);

        return $proxy;
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedState(): array
    {
        $state = Cache::get(self::CACHE_KEY);

        return is_array($state) ? $state : [];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{server: string, username?: string, password?: string}|null
     */
    private function proxyFromState(array $state): ?array
    {
        if (! filled($state['server'] ?? null)) {
            return null;
        }

        $proxy = ['server' => (string) $state['server']];
        if (filled($state['username'] ?? null)) {
            $proxy['username'] = (string) $state['username'];
        }
        if (filled($state['password'] ?? null)) {
            $proxy['password'] = (string) $state['password'];
        }

        return $proxy;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function keepStateUntil(array $state, int $seconds): void
    {
        Cache::forever(self::CACHE_KEY, [
            ...$state,
            'rotate_after' => now()->timestamp + max(1, $seconds),
        ]);
    }

    private function rotationInterval(): int
    {
        return max(5, (int) config('services.telegram_proxy.ttl_seconds', 60));
    }

    private function cooldownSeconds(string $message): int
    {
        if (preg_match('/(\d+)\s*s(?:ec(?:onds?)?)?\b/i', $message, $matches) === 1) {
            return max(1, (int) $matches[1] + 1);
        }

        return $this->rotationInterval();
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
