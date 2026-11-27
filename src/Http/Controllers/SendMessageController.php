<?php

namespace Arrocy\Whatsapp\Http\Controllers;

use App\Http\Controllers\Controller;
use Arrocy\Phonebook\Models\Phonebook;
use Arrocy\Template\Models\Template;
use Arrocy\Whatsapp\Models\Session;
use Arrocy\Whatsapp\Services\ApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;

class SendMessageController extends Controller
{
    public function index(ApiClient $client): View
    {
        $accessToken = setting('access_token');
        $sessions = [];

        $phonebooks = Phonebook::where('user_id', auth()->id())->get();
        $templates = Template::where('user_id', auth()->id())->get();

        try {
            if (is_string($accessToken) && $accessToken !== '') {
                $sessions = $client->getSessions();
                // filter out sessions that are not in the database for the current user
                $dbSessions = Session::query()->where('user_id', auth()->id())->pluck('id')->toArray();
                $sessions = array_filter($sessions, static fn (array $session): bool => in_array($session['id'] ?? null, $dbSessions, true));
            }
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Unable to load WhatsApp sessions from the Node API.', [
                'exception' => $exception::class,
            ]);

            return view('whatsapp::session.send-message', compact('accessToken', 'phonebooks', 'templates', 'sessions'))
                ->withErrors(['node' => $this->apiErrorMessage($exception)]);
        }

        return view('whatsapp::session.send-message', compact('accessToken', 'sessions', 'phonebooks', 'templates'));
    }

    public function sendMessage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
            'receivers' => ['sometimes', 'string'],
            'phonebook_id' => ['nullable', 'string', 'exists:phonebooks,id'],
            'text' => ['sometimes', 'string', 'max:4096'],
            'mediaurl' => ['nullable', 'url'],
        ]);

        $sessionId = $validated['session_id'];
        $receiversArray = explode("\r\n", $validated['receivers'] ?? '');
        $receiversArray = explode("\n", $validated['receivers'] ?? '');
        $contacts = [];
        foreach ($receiversArray as $receiver) {
            $receiverArray = explode(',', $receiver);
            $contacts[] = [
                'name' => trim($receiverArray[1] ?? ''),
                'phone' => trim($receiverArray[0] ?? ''),
                'street' => trim($receiverArray[2] ?? ''),
                'city' => trim($receiverArray[3] ?? ''),
                'state' => trim($receiverArray[4] ?? ''),
                'zip' => trim($receiverArray[5] ?? ''),
                'country' => trim($receiverArray[6] ?? ''),
            ];
        }

        $phonebookData = $validated['phonebook_id'] ? Phonebook::find($validated['phonebook_id'])?->data : [];

        // Merge receivers from both direct input and selected phonebooks
        $contacts = array_merge($contacts, $phonebookData);

        if ($validated['mediaurl'] ?? false) {
            foreach ($contacts as $contact) {
                $caption = str_replace(
                    ['{name}', '{street}', '{city}', '{state}', '{zip}', '{country}'],
                    [$contact['name'], $contact['street'], $contact['city'], $contact['state'], $contact['zip'], $contact['country']],
                    $validated['text'] ?? ''
                );
                $request->merge(['chatId' => $contact['phone'], 'mediaurl' => $validated['mediaurl'], 'caption' => $caption]);
                $this->sendMedia($request, $sessionId, app(ApiClient::class));
            }
        } else {
            foreach ($contacts as $contact) {
                $text = str_replace(
                    ['{name}', '{street}', '{city}', '{state}', '{zip}', '{country}'],
                    [$contact['name'], $contact['street'], $contact['city'], $contact['state'], $contact['zip'], $contact['country']],
                    $validated['text'] ?? ''
                );
                $request->merge(['chatId' => $contact['phone'], 'text' => $text]);
                $this->sendText($request, $sessionId, app(ApiClient::class));
            }
        }

        return to_route('whatsapp.send-message', ['session_id' => $sessionId])->with('success', __('Message sent.'));
    }

    public function sendText(Request $request, string $sessionId, ApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'chatId' => ['required', 'string', 'max:50'],
            'text' => ['required', 'string', 'max:4096'],
        ]);

        try {
            $client->sendText($sessionId, [
                'chatId' => $this->chatId($validated['chatId']),
                'text' => $validated['text'],
            ]);
        } catch (RequestException $exception) {
            return $this->redirectWithNodeError($exception);
        } catch (ConnectionException $exception) {
            Log::warning('Message delivery could not be confirmed by the Node API.', [
                'session_id' => $sessionId,
                'exception' => $exception::class,
            ]);

            return back()->withErrors([
                'node' => __('The Node API connection ended before delivery could be confirmed. Check the recipient before retrying.'),
            ]);
        }

        return to_route('whatsapp.session.show', $sessionId)->with('success', __('Test message sent.'));
    }

    public function sendMedia(Request $request, string $sessionId, ApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'chatId' => ['required', 'string', 'max:50'],
            'caption' => ['nullable', 'string', 'max:1024'],
            // 1. File picker field
            'mediafile' => [
                'required_without:mediaurl', // Required if URL is empty
                'prohibits:mediaurl',        // Fails if both are sent
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,gif,webp,mp4,3gp,mp3,ogg,wav,pdf,doc,docx,xls,xlsx,ppt,pptx,txt',
            ],

            // 2. URL string field
            'mediaurl' => [
                'required_without:mediafile', // Required if File is empty
                'prohibits:mediafile',        // Fails if both are sent
                'string',
                'url',
            ],
        ]);

        $payload = ['chatId' => $this->chatId($validated['chatId'])];

        // Determine which one was uploaded
        if ($request->hasFile('mediafile')) {
            $file = $request->file('mediafile');
            $contents = file_get_contents($file->getRealPath());

            if ($contents === false) {
                throw new RuntimeException('The uploaded media could not be read.');
            }

            $payload['base64'] = base64_encode($contents);
            $payload['filename'] = $file->getClientOriginalName();
            $payload['mimetype'] = $file->getMimeType();
        } else {
            $payload['url'] = $validated['mediaurl'];
            $payload['filename'] = basename(parse_url($validated['mediaurl'], PHP_URL_PATH));
            $extension = pathinfo($payload['filename'], PATHINFO_EXTENSION);
            $mimeTypes = new MimeTypes;
            $guessedTypes = $mimeTypes->getMimeTypes($extension);
            $payload['mimetype'] = $guessedTypes[0] ?? 'application/octet-stream';
        }

        $type = match (true) {
            str_starts_with((string) $payload['mimetype'], 'image/') => 'image',
            str_starts_with((string) $payload['mimetype'], 'video/') => 'video',
            str_starts_with((string) $payload['mimetype'], 'audio/') => 'audio',
            default => 'document',
        };

        if (in_array($type, ['image', 'video', 'document'], true) && filled($validated['caption'] ?? null)) {
            $payload['caption'] = $validated['caption'];
        }

        try {
            if ($type === 'image') {
                $client->sendImage($sessionId, $payload);
            }
            if ($type === 'video') {
                $client->sendVideo($sessionId, $payload);
            }
            if ($type === 'audio') {
                $payload['ptt'] = true;
                $client->sendAudio($sessionId, $payload);
            }
            if ($type === 'document') {
                $client->sendDocument($sessionId, $payload);
            }
        } catch (RequestException $exception) {
            return $this->redirectWithNodeError($exception);
        } catch (ConnectionException $exception) {
            Log::warning('Media delivery could not be confirmed by the Node API.', [
                'session_id' => $sessionId,
                'exception' => $exception::class,
            ]);

            return back()->withErrors([
                'node' => __('The Node API connection ended before delivery could be confirmed. Check the recipient before retrying.'),
            ]);
        }

        return to_route('whatsapp.session.show', $sessionId)->with('success', __('Media message sent.'));
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

    private function chatId(string $chatId): string
    {
        $chatId = trim($chatId);

        if (str_contains($chatId, '@')) {
            return $chatId;
        }

        return (preg_replace('/\D+/', '', $chatId) ?? '').'@s.whatsapp.net';
    }
}
