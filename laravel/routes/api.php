<?php
use App\Http\Controllers\TelegramDeliveryWebhookController;
use App\Http\Controllers\TelegramWebhookController;

Route::post('/telegram/webhook', TelegramWebhookController::class);
Route::post('/telegram/delivery-webhook', TelegramDeliveryWebhookController::class);
