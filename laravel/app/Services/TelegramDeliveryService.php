<?php

namespace App\Services;

use App\Models\TelegramChat;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramDeliveryService
{
    protected const DEFAULT_SEND_DELAY_US = 350000;

    protected const MAX_RATE_LIMIT_RETRIES = 5;

    public function __construct(private readonly TelegramDocumentTransport $documentTransport) {}

    public function sendDocumentToTarget(array $params): mixed
    {
        $chatId = (string) ($params['chat_id'] ?? '');

        if (isset($params['document_path'])) {
            return $this->sendPdfWithTransport($params, $chatId);
        }

        return $this->sendWithRateLimitRetry(
            fn () => $this->telegramBotForChat($chatId)->sendDocument($params)
        );
    }

    public function sendDocumentToSource(array $params): mixed
    {
        if (isset($params['document_path'])) {
            return $this->sendPdfWithTransport($params, (string) ($params['chat_id'] ?? ''), true);
        }

        return $this->sendWithRateLimitRetry(
            fn () => Telegram::sendDocument($params)
        );
    }

    public function pauseBetweenDocumentSends(): void
    {
        usleep(self::DEFAULT_SEND_DELAY_US);
    }

    protected function deliveryBotName(): string
    {
        $botName = (string) config('telegram.delivery_bot', 'delivery');

        if (! config("telegram.bots.{$botName}")) {
            Log::warning("TelegramDeliveryService: Bot [{$botName}] is not configured in config/telegram.php. Falling back to 'delivery'.");

            return 'delivery';
        }

        return $botName;
    }

    protected function telegramBotForChat(string $chatId): mixed
    {
        if ($this->shouldUsePrimaryBot($chatId)) {
            return Telegram::bot(config('telegram.default', 'mybot'));
        }

        return Telegram::bot($this->deliveryBotName());
    }

    protected function sendPdfWithTransport(array $params, string $chatId, bool $forcePrimary = false): array
    {
        $botName = $forcePrimary || $this->shouldUsePrimaryBot($chatId)
            ? (string) config('telegram.default', 'mybot')
            : $this->deliveryBotName();
        $token = (string) config("telegram.bots.{$botName}.token");

        if ($token === '' || $token === 'YOUR-BOT-TOKEN') {
            throw new \RuntimeException("Telegram bot token [{$botName}] is not configured.");
        }

        return $this->documentTransport->send(
            $token,
            $chatId,
            (string) $params['document_path'],
            (string) ($params['caption'] ?? '')
        );
    }

    protected function shouldUsePrimaryBot(string $chatId): bool
    {
        if ($chatId === '') {
            return true;
        }

        if (str_starts_with($chatId, '@')) {
            return true;
        }

        $chat = TelegramChat::where('chat_id', $chatId)->first();
        if ($chat) {
            return ! $chat->isGroupLike();
        }

        $user = TelegramUser::where('chat_id', $chatId)->first();
        if ($user) {
            return true;
        }

        return ! str_starts_with($chatId, '-');
    }

    protected function sendWithRateLimitRetry(callable $send): mixed
    {
        for ($attempt = 1; $attempt <= self::MAX_RATE_LIMIT_RETRIES; $attempt++) {
            try {
                return $send();
            } catch (TelegramResponseException $e) {
                $retryAfter = $this->extractRetryAfterSeconds($e);

                if ($retryAfter === null || $attempt === self::MAX_RATE_LIMIT_RETRIES) {
                    throw $e;
                }

                Log::warning("TelegramDeliveryService: rate limited, retrying after {$retryAfter}s (attempt {$attempt}/".self::MAX_RATE_LIMIT_RETRIES.').');

                sleep($retryAfter);
            }
        }

        return $send();
    }

    protected function extractRetryAfterSeconds(TelegramResponseException $e): ?int
    {
        $responseData = method_exists($e, 'getResponseData')
            ? $e->getResponseData()
            : [];

        $retryAfter = data_get($responseData, 'parameters.retry_after');

        if (is_numeric($retryAfter)) {
            return max(1, (int) $retryAfter);
        }

        if (preg_match('/retry after (\d+)/i', $e->getMessage(), $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return null;
    }
}
