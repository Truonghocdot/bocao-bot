<?php

namespace Tests\Feature;

use App\Exceptions\TelegramDocumentTransportException;
use App\Services\RotatingTelegramProxyService;
use App\Services\TelegramDocumentTransport;
use Illuminate\Http\Client\ConnectionException;
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

    public function test_provider_cooldown_keeps_working_proxy_until_rotation_is_allowed(): void
    {
        $this->travelTo('2026-09-18 20:00:00');
        Cache::flush();
        config([
            'services.telegram_proxy.enabled' => true,
            'services.telegram_proxy.provider_url' => 'https://proxy.test/api',
            'services.telegram_proxy.api_key' => 'secret-key',
            'services.telegram_proxy.ttl_seconds' => 60,
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['status' => 100, 'proxyhttp' => '192.0.2.1:8080::'])
            ->push(['status' => 101, 'message' => 'Con 33s moi co the doi proxy'])
            ->push(['status' => 100, 'proxyhttp' => '192.0.2.2:8080::']);

        $service = app(RotatingTelegramProxyService::class);
        $this->assertSame('http://192.0.2.1:8080', $service->currentProxy()['server']);

        $this->travel(61)->seconds();
        $this->assertSame('http://192.0.2.1:8080', $service->currentProxy()['server']);
        Http::assertSentCount(2);

        $this->travel(33)->seconds();
        $this->assertSame('http://192.0.2.1:8080', $service->currentProxy()['server']);
        Http::assertSentCount(2);

        $this->travel(1)->seconds();
        $this->assertSame('http://192.0.2.2:8080', $service->currentProxy()['server']);
        Http::assertSentCount(3);
    }

    public function test_broken_proxy_is_not_reused_during_provider_cooldown(): void
    {
        $this->travelTo('2026-09-18 20:00:00');
        Cache::flush();
        config([
            'services.telegram_proxy.enabled' => true,
            'services.telegram_proxy.provider_url' => 'https://proxy.test/api',
            'services.telegram_proxy.api_key' => 'secret-key',
            'services.telegram_proxy.ttl_seconds' => 60,
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['status' => 100, 'proxyhttp' => '192.0.2.1:8080::'])
            ->push(['status' => 101, 'message' => 'Con 33s moi co the doi proxy'])
            ->push(['status' => 100, 'proxyhttp' => '192.0.2.2:8080::']);

        $service = app(RotatingTelegramProxyService::class);
        $service->currentProxy();
        $this->travel(61)->seconds();
        $service->currentProxy();

        $service->forget();
        $this->assertNull($service->currentProxy());
        Http::assertSentCount(2);

        $this->travel(34)->seconds();
        $this->assertSame('http://192.0.2.2:8080', $service->currentProxy()['server']);
        Http::assertSentCount(3);
    }

    public function test_provider_connection_error_does_not_expose_api_key(): void
    {
        Cache::flush();
        config([
            'services.telegram_proxy.enabled' => true,
            'services.telegram_proxy.provider_url' => 'https://proxy.test/api',
            'services.telegram_proxy.api_key' => 'secret-key',
        ]);
        Http::preventStrayRequests();
        $requests = 0;
        Http::fake(function () use (&$requests): never {
            $requests++;
            throw new ConnectionException('Request failed: https://proxy.test/api?key=secret-key');
        });

        try {
            app(RotatingTelegramProxyService::class)->currentProxy();
            $this->fail('Expected provider connection to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('secret-key', $exception->getMessage());
        }

        $this->assertNull(app(RotatingTelegramProxyService::class)->currentProxy());
        $this->assertSame(1, $requests);
    }

    public function test_provider_connection_error_reuses_the_last_working_proxy(): void
    {
        $this->travelTo('2026-09-18 20:00:00');
        Cache::flush();
        config([
            'services.telegram_proxy.enabled' => true,
            'services.telegram_proxy.provider_url' => 'https://proxy.test/api',
            'services.telegram_proxy.api_key' => 'secret-key',
            'services.telegram_proxy.ttl_seconds' => 60,
        ]);
        Http::preventStrayRequests();
        $requests = 0;
        Http::fake(function () use (&$requests) {
            $requests++;
            if ($requests === 1) {
                return Http::response(['status' => 100, 'proxyhttp' => '192.0.2.1:8080::']);
            }

            throw new ConnectionException('Request failed: https://proxy.test/api?key=secret-key');
        });

        $service = app(RotatingTelegramProxyService::class);
        $this->assertSame('http://192.0.2.1:8080', $service->currentProxy()['server']);

        $this->travel(61)->seconds();
        $this->assertSame('http://192.0.2.1:8080', $service->currentProxy()['server']);
        $this->assertSame('http://192.0.2.1:8080', $service->currentProxy()['server']);
        $this->assertSame(2, $requests);
    }

    public function test_provider_cooldown_without_prior_proxy_does_not_retry_every_document(): void
    {
        $this->travelTo('2026-09-18 20:00:00');
        Cache::flush();
        config([
            'services.telegram_proxy.enabled' => true,
            'services.telegram_proxy.provider_url' => 'https://proxy.test/api',
            'services.telegram_proxy.api_key' => 'secret-key',
        ]);
        Http::preventStrayRequests();
        Http::fakeSequence()->push([
            'status' => 101,
            'message' => 'Con 33s moi co the doi proxy',
        ]);

        $service = app(RotatingTelegramProxyService::class);
        $this->assertNull($service->currentProxy());
        $this->assertNull($service->currentProxy());
        Http::assertSentCount(1);
    }

    public function test_upload_connection_error_redacts_telegram_token_and_provider_key(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'telegram-pdf-');
        file_put_contents($file, '%PDF-test');

        try {
            config(['services.telegram_proxy.api_key' => 'secret-key']);
            $proxyService = Mockery::mock(RotatingTelegramProxyService::class);
            $proxyService->shouldReceive('currentProxy')->once()->andReturnNull();
            Http::fake(function (): never {
                throw new ConnectionException(
                    'Request failed: https://api.telegram.org/bot123:token/sendDocument?key=secret-key'
                );
            });

            try {
                (new TelegramDocumentTransport($proxyService))->send('123:token', '456', $file, 'PDF DKKD');
                $this->fail('Expected Telegram connection to fail.');
            } catch (TelegramDocumentTransportException $exception) {
                $this->assertStringNotContainsString('123:token', $exception->getMessage());
                $this->assertStringNotContainsString('secret-key', $exception->getMessage());
            }
        } finally {
            @unlink($file);
        }
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
