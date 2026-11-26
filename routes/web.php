<?php

use Arrocy\Whatsapp\Http\Controllers\SendMessageController;
use Arrocy\Whatsapp\Http\Controllers\SessionController;
use Arrocy\Whatsapp\Http\Controllers\WhatsappController;
use Illuminate\Support\Facades\Route;

Route::get('whatsapp/api-docs', [WhatsappController::class, 'apiDocs'])->name('whatsapp.api-docs');

Route::middleware(['web', 'auth', 'verified'])->prefix('whatsapp')->name('whatsapp.')->group(function (): void {
    Route::get('/', [WhatsappController::class, 'index'])->name('index');
    Route::get('/apikey-signup', [WhatsappController::class, 'apikeySignUp'])->name('apikey-signup');

    Route::get('session', [SessionController::class, 'index'])->name('session.index');
    Route::post('session', [SessionController::class, 'store'])->name('session.store');
    Route::get('session/{sessionId}', [SessionController::class, 'show'])->name('session.show');
    Route::put('session/{sessionId}/chatbot', [SessionController::class, 'updateChatbot'])->name('session.chatbot.update');
    Route::post('session/{sessionId}/connect', [SessionController::class, 'connect'])->name('session.connect');
    Route::post('session/{sessionId}/start', [SessionController::class, 'start'])->name('session.start');
    Route::post('session/{sessionId}/stop', [SessionController::class, 'stop'])->name('session.stop');
    Route::post('session/{sessionId}/disconnect', [SessionController::class, 'disconnect'])->name('session.disconnect');
    Route::delete('session/{sessionId}', [SessionController::class, 'destroy'])->name('session.destroy');

    Route::get('/send-message', [SendMessageController::class, 'index'])->name('send-message');
    Route::post('/send-message', [SendMessageController::class, 'sendMessage'])->middleware('throttle:30,1')->name('send-message.all');
    Route::post('/send-text/{sessionId}', [SendMessageController::class, 'sendText'])->middleware('throttle:30,1')->name('send-message.text');
    Route::post('/send-media/{sessionId}', [SendMessageController::class, 'sendMedia'])->middleware('throttle:30,1')->name('send-message.media');
});
