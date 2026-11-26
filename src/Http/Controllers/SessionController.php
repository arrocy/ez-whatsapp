<?php

namespace Arrocy\Whatsapp\Http\Controllers;

use App\Http\Controllers\Controller;
use Arrocy\AiBot\Models\AiBot;
use Arrocy\Whatsapp\Models\Session;
use Arrocy\Whatsapp\Services\ApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class SessionController extends Controller
{
    public function index(ApiClient $client): View
    {
        $accessToken = setting('access_token');
        $sessions = [];

        if (is_string($accessToken) && $accessToken !== '') {
            try {
                $sessions = $client->getSessions();

                foreach ($sessions as $sessionData) {
                    $this->syncSession($sessionData, auth()->id());
                }
            } catch (ConnectionException|RequestException $exception) {
                Log::warning('Unable to load WhatsApp sessions from the Node API.', [
                    'exception' => $exception::class,
                ]);

                return view('whatsapp::session.index', compact('accessToken', 'sessions'))
                    ->withErrors(['node' => $this->apiErrorMessage($exception)]);
            }
        }

        return view('whatsapp::session.index', compact('accessToken', 'sessions'));
    }

    public function store(Request $request, ApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9-]+$/'],
        ]);

        try {
            $sessionData = $client->createSession($validated);
        } catch (ConnectionException|RequestException $exception) {
            return $this->redirectWithNodeError($exception);
        }

        if (! isset($sessionData['id']) || ! is_string($sessionData['id'])) {
            throw new RuntimeException('The Node API did not return a session ID.');
        }

        $this->syncSession($sessionData, auth()->id());

        return to_route('whatsapp.session.show', $sessionData['id'])
            ->with('success', __('Session created. Scan its QR code to connect WhatsApp.'));
    }

    public function show(string $sessionId, ApiClient $client): View|RedirectResponse
    {
        try {
            $sessionData = $client->session($sessionId);
            $session = $this->syncSession($sessionData, auth()->id());
        } catch (ConnectionException|RequestException $exception) {
            return $this->redirectWithNodeError($exception);
        }

        $qrCode = ($sessionData['status'] ?? null) === 'qr_ready' ? session('session_qr_code') : null;
        $qrError = null;

        if (($sessionData['status'] ?? null) === 'qr_ready' && (! is_string($qrCode) || $qrCode === '')) {
            try {
                $qrResponse = $client->connectSession($sessionId);
                $qrCode = $qrResponse['qrCode'] ?? null;

                if (! is_string($qrCode) || $qrCode === '') {
                    throw new RuntimeException('The Node API did not return a QR code.');
                }
            } catch (ConnectionException|RequestException $exception) {
                Log::warning('Unable to load the WhatsApp session QR code.', [
                    'session_id' => $sessionId,
                    'exception' => $exception::class,
                ]);
                $qrError = $this->apiErrorMessage($exception);
            }
        }

        $aiBots = AiBot::query()
            ->where('user_id', auth()->id())
            ->orderBy('name')
            ->get(['name'])
            ->reject(static fn (AiBot $aiBot): bool => $aiBot->name === 'templates');

        return view('whatsapp::session.show', compact('sessionData', 'qrCode', 'qrError', 'session', 'aiBots'));
    }

    public function updateChatbot(Request $request, string $sessionId): RedirectResponse
    {
        $session = Session::query()
            ->where('user_id', auth()->id())
            ->findOrFail($sessionId);
        $aiNames = AiBot::query()
            ->where('user_id', auth()->id())
            ->pluck('name')
            ->reject(static fn (string $name): bool => $name === 'templates')
            ->all();

        $validated = $request->validate([
            'chatbot' => ['present', 'nullable', 'string', Rule::in(array_merge(['templates'], $aiNames))],
        ]);

        $session->update($validated);

        return to_route('whatsapp.session.show', $sessionId)
            ->with('success', __('Chatbot assignment updated.'));
    }

    public function connect(string $sessionId, ApiClient $client): RedirectResponse
    {
        try {
            $qrResponse = $client->connectSession($sessionId);
        } catch (ConnectionException|RequestException $exception) {
            return $this->redirectWithNodeError($exception);
        }

        $qrCode = $qrResponse['qrCode'] ?? null;

        if (! is_string($qrCode) || $qrCode === '') {
            throw new RuntimeException('The Node API did not return a QR code.');
        }

        return to_route('whatsapp.session.show', $sessionId)
            ->with('session_qr_code', $qrCode)
            ->with('success', __('QR code refreshed.'));
    }

    public function start(string $sessionId, ApiClient $client): RedirectResponse
    {
        try {
            $sessionData = $client->startSession($sessionId);
            $this->syncSession($sessionData, auth()->id());
        } catch (ConnectionException|RequestException $exception) {
            return $this->redirectWithNodeError($exception);
        }

        return to_route('whatsapp.session.show', $sessionId)->with('success', __('Session started.'));
    }

    public function stop(string $sessionId, ApiClient $client): RedirectResponse
    {
        try {
            $sessionData = $client->stopSession($sessionId);
            $this->syncSession($sessionData, auth()->id());
        } catch (ConnectionException|RequestException $exception) {
            return $this->redirectWithNodeError($exception);
        }

        return to_route('whatsapp.session.show', $sessionId)->with('success', __('Session stopped.'));
    }

    public function disconnect(string $sessionId, ApiClient $client): RedirectResponse
    {
        try {
            $sessionData = $client->disconnectSession($sessionId);
            $this->syncSession($sessionData, auth()->id());
        } catch (ConnectionException|RequestException $exception) {
            return $this->redirectWithNodeError($exception);
        }

        return to_route('whatsapp.session.show', $sessionId)->with('success', __('WhatsApp session logged out.'));
    }

    public function destroy(string $sessionId, ApiClient $client): RedirectResponse
    {
        try {
            $client->deleteSession($sessionId);
        } catch (ConnectionException|RequestException $exception) {
            return $this->redirectWithNodeError($exception);
        }

        Session::query()->whereKey($sessionId)->delete();

        return to_route('whatsapp.session.index')->with('success', __('Session deleted.'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncSession(array $data, int|string|null $userId = null): Session
    {
        $sessionId = $data['id'] ?? null;

        if (! is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('The Node API did not return a session ID.');
        }

        $existingSession = Session::query()->find($sessionId);
        $attributes = [];

        if ($existingSession === null) {
            if ($userId === null) {
                throw new RuntimeException('A user is required to persist a new WhatsApp session.');
            }

            $attributes['user_id'] = $userId;
        }

        return Session::query()->updateOrCreate(['id' => $sessionId], $attributes);
    }

    private function redirectWithNodeError(ConnectionException|RequestException $exception): RedirectResponse
    {
        Log::warning('Node API request failed.', [
            'status' => $exception instanceof RequestException ? $exception->response->status() : null,
            'exception' => $exception::class,
        ]);

        return back()->withErrors(['node' => $this->apiErrorMessage($exception)]);
    }

    private function apiErrorMessage(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof ConnectionException) {
            return __('Unable to connect to the Node API.');
        }

        $message = $exception->response->json('error') ?? $exception->response->json('message');

        return is_string($message) && $message !== ''
            ? $message
            : __('The Node API rejected the request.');
    }
}
