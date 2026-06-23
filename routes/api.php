<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\MeliWebhookController;
use App\Http\Controllers\MeliChatWebhookController;

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);

    //MeliWebhook
    Route::post('/meli/webhook', [MeliWebhookController::class, 'handle']);
	
    //Mensajeria
	Route::post('/webhooks/mercadolibre/chat-menu', MeliChatWebhookController::class);

