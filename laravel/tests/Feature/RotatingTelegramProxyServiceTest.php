<?php

namespace Tests\Feature;

use App\Exceptions\TelegramDocumentTransportException;
use App\Services\RotatingTelegramProxyService;
use App\Services\TelegramDocumentTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class RotatingTelegramProxyServiceTest extends TestCase
{
    public function test_proxy_is_parsed_and_cached_for_reuse(): void
    {
        Cache::flush();
        config([
            'services.telegram_proxy.enabled' => true,
            'services.telegram_proxy.provider_url' => 'https://proxy.test/api',
            'services.telegram_proxy.api_key' => 'secret-key',
            'services.telegram_proxy.carrier' => 'random',
            'services.telegram_proxy.province' => '0',
            'services.telegram_proxy.whitelist' => '',
            'services.telegram_proxy.ttl_seconds' => 60,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://proxy.test/api*' => Http::response([
                'status' => 100,
                'message' => 'proxy nay se die sau 1777s',
                'proxyhttp' => '42.117.243.215:10836:proxy-user:proxy-pass',
            ]),
        ]);

        $service = app(RotatingTelegramProxyService::class);

        $this->assertSame([
            'server' => 'http://42.117.243.215:10836',
            'username' => 'proxy-user',
            'password' => 'proxy-pass',
        ], $service->currentProxy());
        $this->assertSame('http://42.117.243.215:10836', $service->currentProxy()['server']);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['key'] === 'secret-key');
    }

    public function test_document_upload_rotates_once_then_falls_back_to_direct(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'telegram-pdf-');
        file_put_contents($file, '%PDF-test');

        try {
            $proxy = ['server' => 'http://42.117.243.215:10836'];
            $proxyService = Mockery::mock(RotatingTelegramProxyService::class);
            $proxyService->shouldReceive('currentProxy')->twice()->andReturn($proxy);
            $proxyService->shouldReceive('forget')->once();

            Http::fakeSequence()
                ->push(['ok' => false, 'description' => 'proxy failed'], 502)
                ->push(['ok' => false, 'description' => 'proxy failed again'], 502)
                ->push(['ok' => true, 'result' => ['message_id' => 123]], 200);

            $result = (new TelegramDocumentTransport($proxyService))->send(
                '123:token',
                '456',
                $file,
                'PDF DKKD'
            );

            $this->assertSame(123, $result['message_id']);
            Http::assertSentCount(3);
        } finally {
            @unlink($file);
        }
    }

    public function test_telegram_business_error_does_not_fallback_direct(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'telegram-pdf-');
        file_put_contents($file, '%PDF-test');

        try {
            $proxyService = Mockery::mock(RotatingTelegramProxyService::class);
            $proxyService->shouldReceive('currentProxy')->once()->andReturn([
                'server' => 'http://42.117.243.215:10836',
            ]);
            $proxyService->shouldNotReceive('forget');
            Http::fake([
                '*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400),
            ]);

            try {
                (new TelegramDocumentTransport($proxyService))->send(
                    '123:token',
                    '456',
                    $file,
                    'PDF DKKD'
                );
                $this->fail('Expected Telegram business error was not thrown.');
            } catch (TelegramDocumentTransportException $exception) {
                $this->assertFalse($exception->transportFailure);
                $this->assertStringContainsString('chat not found', $exception->getMessage());
            }

            Http::assertSentCount(1);
        } finally {
            @unlink($file);
        }
    }
}
