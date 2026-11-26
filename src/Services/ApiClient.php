<?php

namespace Arrocy\Whatsapp\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApiClient
{
    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $response->throw();
        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('The Node API returned an invalid JSON response.');
        }

        return $data;
    }

    private function request(?string $accessToken = null): PendingRequest
    {
        $apiUrl = rtrim((string) config('whatsapp.api_url'), '/');

        if ($apiUrl === '') {
            throw new RuntimeException('The Node API URL is not configured.');
        }

        $request = Http::acceptJson()
            ->baseUrl($apiUrl)
            ->connectTimeout(3)
            ->timeout(20);

        if ($accessToken === null) {
            return $request;
        }

        return $request->withToken($accessToken);

        // $headers = ['X-API-Key' => $accessToken];
        // return $request->withHeaders($headers);
    }

    /**
     * @param  array<string, array<int, string>|string>  $headers
     */
    public function forward(
        string $method,
        string $path,
        ?string $bearerToken,
        ?string $apiKey,
        ?string $queryString,
        string $body,
        ?string $contentType,
        array $headers,
        ?UploadedFile $upload = null,
    ): Response {
        $url = $path.($queryString === null || $queryString === '' ? '' : '?'.$queryString);
        $request = $this->request($bearerToken)
            ->withOptions(['stream' => true])
            ->replaceHeaders($headers);

        if ($apiKey !== null && $apiKey !== '') {
            $request = $request->replaceHeaders(['X-API-Key' => $apiKey]);
        }

        if ($upload !== null) {
            $filePath = $upload->getRealPath();

            if (! is_string($filePath)) {
                throw new RuntimeException('The uploaded plugin archive could not be read.');
            }

            $fileStream = fopen($filePath, 'rb');

            if (! is_resource($fileStream)) {
                throw new RuntimeException('The uploaded plugin archive could not be opened.');
            }

            try {
                $uploadHeaders = array_filter([
                    'Content-Type' => $upload->getMimeType(),
                ]);

                return $request
                    ->attach('file', $fileStream, $upload->getClientOriginalName(), $uploadHeaders)
                    ->send($method, $url, ['multipart' => []]);
            } finally {
                fclose($fileStream);
            }
        }

        if ($body !== '') {
            $request = $request->withBody($body, $contentType ?? 'application/octet-stream');
        }

        return $request->send($method, $url);
    }

    /**
     * @param  array{name: string}  $data
     * @return array{id: string, name: string, apiKey: string}
     */
    public function signUp(array $data): array
    {
        $accessToken = config('whatsapp.access_token');

        return $this->json($this->request($accessToken)->post('/api/auth/api-keys', $data));
    }

    /**
     * @return array{id: string, name: string, keyPrefix: string}
     */
    public function signIn(string $id): array
    {
        $accessToken = setting('access_token');

        return $this->json($this->request($accessToken)->get('/api/auth/api-keys/'.$id));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSessions(): array
    {
        $accessToken = setting('access_token');

        return $this->json($this->request($accessToken)->get('/api/sessions'));
    }

    /**
     * @param  array{name?: string}  $data
     * @return array<string, mixed>
     */
    public function createSession(array $data): array
    {
        $accessToken = setting('access_token');

        $payload = [
            'name' => $data['name'] ?? 'Bot-'.uniqid(),
            'config' => [
                'autoRejectCalls' => false,
                'maxReconnectAttempts' => 5,
                'reconnectBaseDelay' => 5000,
            ],
        ];

        return $this->json($this->request($accessToken)->post('/api/sessions', $payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function session(string $sessionId): array
    {
        $accessToken = setting('access_token');

        return $this->json($this->request($accessToken)->get('/api/sessions/'.rawurlencode($sessionId)));
    }

    /**
     * @return array<string, mixed>
     */
    public function connectSession(string $sessionId): array
    {
        $accessToken = setting('access_token');

        return $this->json($this->request($accessToken)->get('/api/sessions/'.rawurlencode($sessionId).'/qr'));
    }

    /**
     * @return array<string, mixed>
     */
    public function startSession(string $sessionId): array
    {
        $accessToken = setting('access_token');

        return $this->json($this->request($accessToken)->post('/api/sessions/'.rawurlencode($sessionId).'/start'));
    }

    /**
     * @return array<string, mixed>
     */
    public function stopSession(string $sessionId): array
    {
        $accessToken = setting('access_token');

        return $this->json($this->request($accessToken)->post('/api/sessions/'.rawurlencode($sessionId).'/stop'));
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnectSession(string $sessionId): array
    {
        $accessToken = setting('access_token');

        return $this->json($this->request($accessToken)->post('/api/sessions/'.rawurlencode($sessionId).'/logout'));
    }

    /**
     * The ArrocyWA delete endpoint returns 204 with no response body.
     */
    public function deleteSession(string $sessionId): void
    {
        $accessToken = setting('access_token');

        $this->request($accessToken)->delete('/api/sessions/'.rawurlencode($sessionId))->throw();
    }

    /**
     * @param  array{chatId: string, text: string}  $payload
     * @return array<string, mixed>
     */
    public function sendText(string $sessionId, array $payload): array
    {
        $accessToken = setting('access_token');

        return $this->json($this->request($accessToken)->post('/api/sessions/'.rawurlencode($sessionId).'/messages/send-text', $payload));
    }

    /**
     * @param  array{chatId: string, base64?: string, url?: string, mimetype: string, caption?: string}  $payload
     * @return array<string, mixed>
     */
    public function sendImage(string $sessionId, array $payload): array
    {
        $accessToken = setting('access_token');

        return $this->json($this->request($accessToken)->post('/api/sessions/'.rawurlencode($sessionId).'/messages/send-image', $payload));
    }

    /**
     * @param  array{chatId: string, base64?: string, url?: string, mimetype: string}  $payload
     * @return array<string, mixed>
     */
    public function sendVideo(string $sessionId, array $payload): array
    {
        $accessToken = setting('access_token');

        return $this->json($this->request($accessToken)->post('/api/sessions/'.rawurlencode($sessionId).'/messages/send-video', $payload));
    }

    /**
     * @param  array{chatId: string, base64?: string, url?: string, mimetype: string, ptt?: bool}  $payload
     * @return array<string, mixed>
     */
    public function sendAudio(string $sessionId, array $payload): array
    {
        $accessToken = setting('access_token');
        if (empty($payload['ptt'])) {
            $payload['ptt'] = true;
        }

        return $this->json($this->request($accessToken)->post('/api/sessions/'.rawurlencode($sessionId).'/messages/send-audio', $payload));
    }

    /**
     * @param  array{chatId: string, base64?: string, url?: string, mimetype: string, filename?: string, caption?: string}  $payload
     * @return array<string, mixed>
     */
    public function sendDocument(string $sessionId, array $payload): array
    {
        $accessToken = setting('access_token');

        return $this->json($this->request($accessToken)->post('/api/sessions/'.rawurlencode($sessionId).'/messages/send-document', $payload));
    }

    /**
     * @param  array{name?: string, email?: string, currentPassword?: string, newPassword?: string}  $data
     * @return array<string, mixed>
     */
    public function updateProfile(string $sessionId, array $data): array
    {
        $accessToken = setting('access_token');
        $profileName = $data['profile_name'] ?? null;
        $profileStatus = $data['profile_status'] ?? null;
        $profilePictureUrl = $data['profile_picture_url'] ?? null;
        $profilePictureBase64 = $data['profile_picture_base64'] ?? null;
        $updated = [];

        if ($profileName) {
            $updated[] = $this->json($this->request($accessToken)->put('/api/sessions/'.rawurlencode($sessionId).'/profile/name', ['name' => $profileName]));
        }
        if ($profileStatus) {
            $updated[] = $this->json($this->request($accessToken)->put('/api/sessions/'.rawurlencode($sessionId).'/profile/status', ['status' => $profileStatus]));
        }
        if ($profilePictureUrl) {
            $updated[] = $this->json($this->request($accessToken)->put('/api/sessions/'.rawurlencode($sessionId).'/profile/picture', [
                'url' => $profilePictureUrl,
                'mimetype' => 'image/jpeg',
            ]));
        } elseif ($profilePictureBase64) {
            $updated[] = $this->json($this->request($accessToken)->put('/api/sessions/'.rawurlencode($sessionId).'/profile/picture', [
                'base64' => $profilePictureBase64,
                'mimetype' => 'image/jpeg',
            ]));
        }

        return $updated;
    }
}
