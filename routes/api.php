<?php

use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\NumberMessageController;
use App\Http\Controllers\Api\V1\ServiceWindowController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['throttle:api', AuthenticateApiToken::class])
    ->group(function () {
        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');

        Route::post('messages', [MessageController::class, 'store'])->name('messages.store');
        Route::get('messages/{message}', [MessageController::class, 'show'])->name('messages.show');

        Route::get('numbers/{phone}/messages', [NumberMessageController::class, 'index'])
            ->where('phone', '[0-9]{10,15}')
            ->name('numbers.messages.index');

        Route::get('numbers/{phone}/window', [ServiceWindowController::class, 'show'])
            ->where('phone', '[0-9]{10,15}')
            ->name('numbers.window.show');
    });
