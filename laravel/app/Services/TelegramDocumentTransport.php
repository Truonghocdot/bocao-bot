<?php

namespace App\Services;

use App\Exceptions\TelegramDocumentTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramDocumentTransport
{
    public function __construct(private readonly RotatingTelegramProxyService $proxyService) {}

    public function send(string $token, string $chatId, string $filePath, string $caption): array
    {
        if (! is_file($filePath)) {
            throw new TelegramDocumentTransportException('PDF file does not exist.', false);
        }

        try {
            $proxy = $this->proxyService->currentProxy();
        } catch (\Throwable $exception) {
            Log::warning('Unable to resolve Telegram proxy; falling back to direct upload.', [
                'error' => $exception->getMessage(),
            ]);

            return $this->sendRequest($token, $chatId, $filePath, $caption, null);
        }

        if ($proxy === null) {
            return $this->sendRequest($token, $chatId, $filePath, $caption, null);
        }

        try {
            return $this->sendRequest($token, $chatId, $filePath, $caption, $proxy);
        } catch (TelegramDocumentTransportException $exception) {
            if (! $exception->transportFailure) {
                throw $exception;
            }

            Log::warning('Telegram proxy upload failed; rotating proxy once.', [
                'error' => $exception->getMessage(),
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Telegram proxy connection failed; rotating proxy once.', [
                'error' => $exception->getMessage(),
            ]);
        }

        $this->proxyService->forget();

        try {
            $rotatedProxy = $this->proxyService->currentProxy();
            if ($rotatedProxy !== null) {
                return $this->sendRequest($token, $chatId, $filePath, $caption, $rotatedProxy);
            }
        } catch (TelegramDocumentTransportException $exception) {
            if (! $exception->transportFailure) {
                throw $exception;
            }

            Log::warning('Rotated Telegram proxy failed; falling back to direct upload.', [
                'error' => $exception->getMessage(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Could not rotate Telegram proxy; falling back to direct upload.', [
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->sendRequest($token, $chatId, $filePath, $caption, null);
    }

    /**
     * @param  array{server: string, username?: string, password?: string}|null  $proxy
     * @return array<string, mixed>
     */
    private function sendRequest(
        string $token,
        string $chatId,
        string $filePath,
        string $caption,
        ?array $proxy
    ): array {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $stream = fopen($filePath, 'rb');
            if ($stream === false) {
                throw new TelegramDocumentTransportException('Unable to open PDF file.', false);
            }

            try {
                $request = Http::acceptJson()
                    ->connectTimeout((int) config('services.telegram_proxy.connect_timeout', 15))
                    ->timeout((int) config('services.telegram_proxy.timeout', 180));

                if ($proxy !== null) {
                    $request = $request->withOptions(['proxy' => $this->proxyUrl($proxy)]);
                }

                $response = $this->attachDocument($request, $stream, basename($filePath))
                    ->post("https://api.telegram.org/bot{$token}/sendDocument", [
                        'chat_id' => $chatId,
                        'caption' => $caption,
                    ]);
            } finally {
                fclose($stream);
            }

            if ($response->successful() && $response->json('ok')) {
                return (array) $response->json('result', []);
            }

            if ($response->status() === 429 && $attempt < 5) {
                $retryAfter = max(1, (int) $response->json('parameters.retry_after', 1));
                sleep($retryAfter);

                continue;
            }

            $description = (string) $response->json('description', "Telegram HTTP {$response->status()}");

            throw new TelegramDocumentTransportException(
                $description,
                $proxy !== null && in_array($response->status(), [407, 502, 503, 504], true)
            );
        }

        throw new TelegramDocumentTransportException('Telegram rate limit retry exhausted.', false);
    }

    /**
     * @param  resource  $stream
     */
    private function attachDocument(PendingRequest $request, $stream, string $filename): PendingRequest
    {
        return $request->attach('document', $stream, $filename);
    }

    /**
     * @param  array{server: string, username?: string, password?: string}  $proxy
     */
    private function proxyUrl(array $proxy): string
    {
        $parts = parse_url($proxy['server']);
        if (! is_array($parts) || empty($parts['host']) || empty($parts['port'])) {
            throw new TelegramDocumentTransportException('Invalid cached proxy endpoint.', true);
        }

        $credentials = '';
        if (filled($proxy['username'] ?? null)) {
            $credentials = rawurlencode((string) $proxy['username'])
                .':'.rawurlencode((string) ($proxy['password'] ?? '')).'@';
        }

        return ($parts['scheme'] ?? 'http').'://'.$credentials.$parts['host'].':'.$parts['port'];
    }
}
