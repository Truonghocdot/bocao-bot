<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Telegram\Bot\Laravel\Facades\Telegram;
use Tests\TestCase;

class TelegramDeliveryWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_webhook_stores_group_and_replies_with_chat_id(): void
    {
        config(['telegram.delivery_bot' => 'delivery']);

        $bot = Mockery::mock();
        $bot->shouldReceive('sendMessage')->once()->with(Mockery::on(function (array $params) {
            return $params['chat_id'] === '-1001234567890'
                && str_contains($params['text'], 'Chat ID nhận file: <code>-1001234567890</code>')
                && $params['parse_mode'] === 'HTML';
        }));

        $manager = Mockery::mock();
        $manager->shouldReceive('bot')->once()->with('delivery')->andReturn($bot);
        Telegram::swap($manager);

        $this->postJson('/api/telegram/delivery-webhook', [
            'message' => [
                'message_id' => 1,
                'chat' => [
                    'id' => -1001234567890,
                    'type' => 'supergroup',
                    'title' => 'Group nhận PDF',
                    'username' => 'group_nhan_pdf',
                ],
                'text' => '/start',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('telegram_chats', [
            'chat_id' => '-1001234567890',
            'type' => 'supergroup',
            'title' => 'Group nhận PDF',
            'username' => 'group_nhan_pdf',
            'is_bot_member' => true,
        ]);
    }

    public function test_delivery_webhook_private_chat_replies_with_setup_instruction(): void
    {
        config(['telegram.delivery_bot' => 'delivery']);

        $bot = Mockery::mock();
        $bot->shouldReceive('sendMessage')->once()->with(Mockery::on(function (array $params) {
            return $params['chat_id'] === '123456'
                && str_contains($params['text'], 'Hãy thêm bot gửi file này vào group');
        }));

        $manager = Mockery::mock();
        $manager->shouldReceive('bot')->once()->with('delivery')->andReturn($bot);
        Telegram::swap($manager);

        $this->postJson('/api/telegram/delivery-webhook', [
            'message' => [
                'message_id' => 1,
                'chat' => [
                    'id' => 123456,
                    'type' => 'private',
                    'username' => 'receiver_user',
                ],
                'text' => '/start',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('telegram_chats', [
            'chat_id' => '123456',
            'type' => 'private',
            'username' => 'receiver_user',
            'is_bot_member' => true,
        ]);
    }
}
