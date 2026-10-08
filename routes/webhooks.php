<?php

use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Middleware\VerifyWhatsAppSignature;
use Illuminate\Support\Facades\Route;

Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.verify');

Route::post('whatsapp', [WhatsAppWebhookController::class, 'store'])
    ->middleware(VerifyWhatsAppSignature::class)
    ->name('whatsapp.store');
