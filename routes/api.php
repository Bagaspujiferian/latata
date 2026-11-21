<?php

use App\Http\Controllers\Api\GameNicknameController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/game/verify-nickname', [GameNicknameController::class, 'verify'])->name('api.game.verify-nickname');
Route::post('/webhooks/midtrans', [WebhookController::class, 'midtrans'])->name('api.webhooks.midtrans');
Route::post('/webhooks/digiflazz', [WebhookController::class, 'digiflazz'])->name('api.webhooks.digiflazz');
