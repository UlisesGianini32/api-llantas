<?php

use App\Http\Controllers\MeliChatWebhookController;
use App\Http\Controllers\MeliWebhookController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);

// MeliWebhook
Route::post('/meli/webhook', [MeliWebhookController::class, 'handle']);

// Mensajeria
Route::post('/webhooks/mercadolibre/chat-menu', MeliChatWebhookController::class);

// Shopify Webhook
Route::post('/shopify/webhook', [ShopifyWebhookController::class, 'handle']);
