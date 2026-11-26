<?php

use Arrocy\Whatsapp\Http\Controllers\ApiController;
use Arrocy\Whatsapp\Support\ApiEndpointMap;
use Illuminate\Support\Facades\Route;

Route::middleware(['api'])->prefix('api/whatsapp')->name('whatsapp.api.')->group(function (): void {
    Route::post('/', [ApiController::class, 'handle'])->name('handle');
});

Route::middleware(['api'])->group(function (): void {
    $legacyRouteNames = [
        'POST api/sessions' => 'sessions.api.create-session',
        'GET api/sessions' => 'sessions.api.get-sessions',
        'GET api/sessions/{sessionId}' => 'sessions.api.get-session',
        'DELETE api/sessions/{sessionId}' => 'sessions.api.delete-session',
        'GET api/sessions/{sessionId}/config' => 'sessions.api.get-config',
        'PATCH api/sessions/{sessionId}/config' => 'sessions.api.update-config',
        'GET api/sessions/{sessionId}/proxy' => 'sessions.api.get-proxy',
        'POST api/sessions/{sessionId}/start' => 'sessions.api.start-session',
        'POST api/sessions/{sessionId}/stop' => 'sessions.api.stop-session',
        'POST api/sessions/{sessionId}/logout' => 'sessions.api.logout-session',
        'POST api/sessions/{sessionId}/force-kill' => 'sessions.api.force-kill-session',
        'GET api/sessions/{sessionId}/qr' => 'sessions.api.get-qr',
        'POST api/sessions/{sessionId}/pairing-code' => 'sessions.api.pairing-code-session',
        'GET api/sessions/{sessionId}/groups' => 'sessions.api.get-groups',
        'GET api/sessions/{sessionId}/chats' => 'sessions.api.get-chats',
        'GET api/sessions/{sessionId}/messages' => 'sessions.api.get-messages',
        'POST api/sessions/{sessionId}/messages/send-text' => 'sessions.api.send-text',
        'POST api/sessions/{sessionId}/messages/send-template' => 'sessions.api.send-template',
        'POST api/sessions/{sessionId}/messages/send-image' => 'sessions.api.send-image',
        'POST api/sessions/{sessionId}/messages/send-video' => 'sessions.api.send-video',
        'POST api/sessions/{sessionId}/messages/send-audio' => 'sessions.api.send-audio',
        'POST api/sessions/{sessionId}/messages/send-document' => 'sessions.api.send-document',
        'POST api/sessions/{sessionId}/messages/send-location' => 'sessions.api.send-location',
        'POST api/sessions/{sessionId}/messages/send-contact' => 'sessions.api.send-contact',
        'POST api/sessions/{sessionId}/messages/send-sticker' => 'sessions.api.send-sticker',
        'POST api/sessions/{sessionId}/messages/send-poll' => 'sessions.api.send-poll',
        'POST api/sessions/{sessionId}/messages/reply' => 'sessions.api.reply',
        'POST api/sessions/{sessionId}/messages/click-button' => 'sessions.api.click-button',
        'POST api/sessions/{sessionId}/messages/forward' => 'sessions.api.forward',
        'POST api/sessions/{sessionId}/messages/react' => 'sessions.api.react',
        'PUT api/sessions/{sessionId}/profile/name' => 'sessions.api.update-profile-name',
        'PUT api/sessions/{sessionId}/profile/status' => 'sessions.api.update-profile-status',
        'PUT api/sessions/{sessionId}/profile/picture' => 'sessions.api.update-profile-picture',
        'DELETE api/sessions/{sessionId}/profile/picture' => 'sessions.api.delete-profile-picture',
    ];

    foreach (ApiEndpointMap::ROUTES as $uri => $methods) {
        foreach ($methods as $method) {
            $route = Route::match([$method], $uri, [ApiController::class, 'forward']);
            $routeName = $legacyRouteNames[$method.' '.$uri] ?? null;

            if ($routeName !== null) {
                $route->name($routeName);
            }
        }
    }
});