<?php

use Arrocy\Whatsapp\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api'])->prefix('api/whatsapp')->name('whatsapp.api.')->group(function (): void {
    Route::post('/', [ApiController::class, 'handle'])->name('handle');
});