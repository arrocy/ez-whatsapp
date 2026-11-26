<?php

namespace Arrocy\Whatsapp\Http\Controllers;

use App\Http\Controllers\Controller;
use Arrocy\AiBot\Models\AiBot;
use Arrocy\Template\Models\Template;
use Arrocy\Whatsapp\Models\Session;
use Arrocy\Whatsapp\Services\ApiClient;
use Arrocy\Whatsapp\Support\ApiEndpointMap;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ApiController extends Controller
{
    public function __construct(private readonly ApiClient $client) {}

    public function docs(Request $request)
    {
        $endpointGroups = [];
        $operationCount = 0;

        foreach (\Arrocy\Whatsapp\Support\ApiEndpointMap::ROUTES as $uri => $methods) {
            if (Str::startsWith($uri, 'api/auth/api-keys')) continue;
            $segments = explode('/', trim($uri, '/'));
            $area = $segments[1] ?? __('Other');
            $category = \Illuminate\Support\Str::headline($area);

            if ($area === 'sessions') {
                $resource = $segments[2] ?? null;

                if ($resource === 'stats') {
                    $category = __('Session statistics');
                } elseif ($resource !== null && str_starts_with($resource, '{')) {
                    $resource = $segments[3] ?? null;
                    $category = $resource === null
                        ? __('Sessions')
                        : __('Sessions').' · '.\Illuminate\Support\Str::headline($resource);
                }
            }

            foreach ($methods as $method) {
                $endpointGroups[$category][] = [
                    'method' => $method,
                    'path' => '/'.$uri,
                ];
                $operationCount++;
            }
        }

        ksort($endpointGroups);

        return view('whatsapp::api-docs.index', compact('endpointGroups', 'operationCount'));
    }

    private const HOP_BY_HOP_HEADERS = [
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    private const RESPONSE_HEADERS = [
        'cache-control',
        'content-disposition',
        'content-type',
        'etag',
        'expires',
        'last-modified',
        'location',
        'retry-after',
        'vary',
        'www-authenticate',
        'x-ratelimit-limit',
        'x-ratelimit-remaining',
        'x-ratelimit-reset',
        'x-request-id',
    ];

    public function forward(Request $request): Response
    {
        $route = $request->route();
        $routeUri = $route->uri();
        $bearerToken = null;
        $apiKey = null;

        if ($routeUri === ApiEndpointMap::METRICS_ROUTE) {
            $bearerToken = $request->bearerToken();
        } elseif (! in_array($routeUri, ApiEndpointMap::NO_API_KEY, true)) {
            $apiKey = $request->header('X-API-Key') ?? $request->bearerToken();
        }

        if (is_string($apiKey)) {
            $apiKey = trim($apiKey);
            $apiKey = $apiKey === '' ? null : $apiKey;
        }

        if (is_string($bearerToken)) {
            $bearerToken = trim($bearerToken);
            $bearerToken = $bearerToken === '' ? null : $bearerToken;
        }

        $isIngress = str_starts_with($routeUri, 'api/ingress/');
        $upload = $request->file('file');

        if (! $upload instanceof UploadedFile) {
            $upload = null;
        } elseif (! $upload->isValid()) {
            return response()->json(['message' => 'The uploaded file is invalid.'], 422);
        }

        try {
            $upstreamResponse = $this->client->forward(
                $request->method(),
                $request->getPathInfo(),
                $bearerToken,
                $apiKey,
                $request->getQueryString(),
                $request->getContent(),
                $request->header('Content-Type'),
                $this->requestHeaders($request, $isIngress),
                $upload,
            );
        } catch (ConnectionException $exception) {
            Log::warning('The ArrocyWA API could not be reached.', [
                'method' => $request->method(),
                'route' => $routeUri,
                'exception' => $exception::class,
            ]);

            return response()->json(['message' => 'The upstream API is unavailable.'], 502);
        }

        return response()->stream(
            static function () use ($upstreamResponse): void {
                fpassthru($upstreamResponse->resource());
            },
            $upstreamResponse->status(),
            $this->responseHeaders($upstreamResponse->headers(), $isIngress),
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function requestHeaders(Request $request, bool $isIngress): array
    {
        $headers = [];
        $allowedHeaders = ['accept', 'content-encoding', 'idempotency-key', 'x-request-id'];

        foreach ($request->headers->all() as $name => $values) {
            $lowerName = Str::lower($name);

            if (in_array($lowerName, self::HOP_BY_HOP_HEADERS, true)
                || in_array($lowerName, ['host', 'content-length', 'cookie', 'forwarded'], true)
                || str_starts_with($lowerName, 'x-forwarded-')) {
                continue;
            }

            if (! $isIngress && ! in_array($lowerName, $allowedHeaders, true)) {
                continue;
            }

            $normalizedName = match ($lowerName) {
                'accept' => 'Accept',
                'authorization' => 'Authorization',
                'content-encoding' => 'Content-Encoding',
                'idempotency-key' => 'Idempotency-Key',
                'x-api-key' => 'X-API-Key',
                'x-request-id' => 'X-Request-Id',
                default => implode('-', array_map('ucfirst', explode('-', $lowerName))),
            };
            $headers[$normalizedName] = $values;
        }

        return $headers;
    }

    /**
     * @param  array<string, array<int, string>>  $upstreamHeaders
     * @return array<string, array<int, string>>
     */
    private function responseHeaders(array $upstreamHeaders, bool $isIngress): array
    {
        $headers = [];
        $blockedHeaders = [
            ...self::HOP_BY_HOP_HEADERS,
            'content-length',
            'set-cookie',
        ];

        foreach ($upstreamHeaders as $name => $values) {
            $lowerName = Str::lower($name);

            if (in_array($lowerName, $blockedHeaders, true)) {
                continue;
            }

            if (! $isIngress && ! in_array($lowerName, self::RESPONSE_HEADERS, true)) {
                continue;
            }

            $headers[$name] = $values;
        }

        return $headers;
    }

    public function handle(Request $request): JsonResponse
    {
        // \Helper::prependLog($request->json()->all());
        // result of $request->json()->all() is below:
        // {
        //     "event": "message.received",
        //     "timestamp": "2026-11-21T09:38:03.498Z",
        //     "sessionId": "5bb3bda9-36f0-48cb-a6ed-3e9972283bf4",
        //     "idempotencyKey": "msg_5bb3bda9-36f0-48cb-a6ed-3e9972283bf4_3EB09882B7A04A4128A802_8b0caf89-a83c-4919-a55a-ab2d376ca745",
        //     "deliveryId": "dlv_b57eb2ac-daa8-4afc-a231-e97cded7c674",
        //     "data": {
        //         "id": "3EB09882B7A04A4128A802",
        //         "from": "268453088866517@lid",
        //         "to": "6281282667969@c.us",
        //         "chatId": "268453088866517@lid",
        //         "body": "test 3",
        //         "type": "text",
        //         "timestamp": 1795253883,
        //         "fromMe": false,
        //         "isGroup": false,
        //         "kind": "individual",
        //         "isStatusBroadcast": false,
        //         "isLidSender": true,
        //         "contact": {
        //             "pushName": "Arrocy"
        //         }
        //     },
        //     "type": "array"
        // }

        // $expectedToken = setting('access_token');
        // $providedToken = $request->bearerToken();
        // $webhookSecret = setting('webhook_secret');
        // $signatureSecret = is_string($webhookSecret) && $webhookSecret !== ''
        //     ? $webhookSecret
        //     : $expectedToken;
        // $signature = $request->header('X-ArrocyWA-Signature');
        // $signatureIsValid = is_string($signatureSecret)
        //     && $signatureSecret !== ''
        //     && is_string($signature)
        //     && hash_equals(
        //         'sha256='.hash_hmac('sha256', $request->getContent(), $signatureSecret),
        //         $signature,
        //     );
        // $tokenIsValid = is_string($expectedToken)
        //     && $expectedToken !== ''
        //     && is_string($providedToken)
        //     && hash_equals($expectedToken, $providedToken);

        // if (! $tokenIsValid && ! $signatureIsValid) {
        //     return response()->json(['message' => 'Unauthorized.'], 401);
        // }

        $requestJsonAll = $request->json()->all();
        $validated = Validator::make($requestJsonAll, [
            'event' => ['required', 'string', 'max:80'],
            'sessionId' => ['required', 'string', 'uuid'],
            'data' => ['sometimes', 'array'],
        ])->validate();

        if (! in_array($validated['event'], ['connection.update', 'message.received'], true)) {
            return response()->json(['handled' => false]);
        }

        $session = Session::query()->find($validated['sessionId']);

        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        if ($validated['event'] === 'connection.update') {
            return response()->json(['handled' => true]);
        }

        $data = $validated['data'] ?? [];

        if (Arr::get($data, 'fromMe') === true || Arr::get($data, 'isStatusBroadcast') === true) {
            return response()->json(['handled' => false]);
        }

        try {
            $handled = $this->aiBotReply($session, $data);

            if (! $handled) {
                $handled = $this->autoreply($session, $data);
            }
        } catch (ConnectionException|RequestException|RuntimeException|InvalidArgumentException $exception) {
            Log::warning('Node Kit could not send a webhook reply.', [
                'session_id' => $session->getKey(),
                'event' => $validated['event'],
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Unable to send the webhook reply.'], 502);
        }

        return response()->json(['handled' => $handled]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function aiBotReply(Session $session, array $data): bool
    {
        if (! $session->chatbot || $session->chatbot === 'templates') {
            return false;
        }

        $aiBot = AiBot::query()
            ->where('user_id', $session->user_id)
            ->where('name', $session->chatbot)
            ->where('enabled', true)
            ->orderBy('id')
            ->first();

        $inputText = $this->messageText($data);
        $receiver = $this->messageReceiver($data);

        if (! $aiBot || ! is_string($aiBot->apikey) || trim($aiBot->apikey) === ''
            || ! is_string($aiBot->model) || trim($aiBot->model) === ''
            || $inputText === ''
            || ! is_string($receiver)
            || trim($receiver) === '') {
            return false;
        }

        $botname = trim((string) $aiBot->botname);
        $botNumber = $this->botNumber($data);
        $mentioned = $this->isMentioned($data, $inputText);

        if ($botname !== ''
            && ! Str::contains(Str::lower($inputText), Str::lower($botname))
            && ! $mentioned) {
            return false;
        }

        $remove = array_values(array_filter(
            [$botname, $botNumber === null ? null : '@'.$botNumber],
            static fn (?string $value): bool => $value !== null && $value !== '',
        ));
        $prompt = trim(str_ireplace($remove, '', $inputText));

        if ($prompt === '') {
            return false;
        }

        try {
            $reply = $this->requestAiReply($aiBot, $prompt);
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('AI provider request failed for a WhatsApp webhook.', [
                'session_id' => $session->getKey(),
                'provider' => $aiBot->provider,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        if (! is_string($reply) || trim($reply) === '') {
            Log::warning('AI provider returned no reply for a WhatsApp webhook.', [
                'session_id' => $session->getKey(),
                'provider' => $aiBot->provider,
            ]);

            return false;
        }

        $this->sendTextResponse($session, $receiver, trim($reply));

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function messageText(array $data): string
    {
        $text = Arr::get($data, 'body', Arr::get($data, 'messageText', ''));

        return is_string($text) ? trim($text) : '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function messageReceiver(array $data): ?string
    {
        $receiver = Arr::get($data, 'chatId')
            ?? Arr::get($data, 'key.senderPn')
            ?? Arr::get($data, 'key.remoteJid')
            ?? Arr::get($data, 'from');

        return is_string($receiver) && trim($receiver) !== '' ? trim($receiver) : null;
    }

    private function requestAiReply(AiBot $aiBot, string $prompt): ?string
    {
        $provider = strtolower((string) $aiBot->provider);
        $model = (string) $aiBot->model;

        if ($provider === 'gemini') {
            $body = [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $prompt]]],
                ],
            ];

            if (is_string($aiBot->system_prompt) && trim($aiBot->system_prompt) !== '') {
                $body['systemInstruction'] = [
                    'parts' => [['text' => $aiBot->system_prompt]],
                ];
            }

            $completion = Http::acceptJson()
                ->withHeader('x-goog-api-key', $aiBot->apikey)
                ->connectTimeout(3)
                ->timeout(45)
                ->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent',
                    $body,
                )
                ->throw()
                ->json();

            $text = Arr::get($completion, 'candidates.0.content.parts.0.text');

            return is_string($text) ? $text : null;
        }

        $endpoint = match ($provider) {
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
            'deepseek' => 'https://api.deepseek.com/chat/completions',
            default => null,
        };

        if ($endpoint === null) {
            Log::warning('Unsupported AI provider selected for a WhatsApp session.', [
                'ai_bot_id' => $aiBot->getKey(),
                'provider' => $aiBot->provider,
            ]);

            return null;
        }

        $messages = [];

        if (is_string($aiBot->system_prompt) && trim($aiBot->system_prompt) !== '') {
            $messages[] = ['role' => 'system', 'content' => $aiBot->system_prompt];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $completion = Http::acceptJson()
            ->withToken($aiBot->apikey)
            ->connectTimeout(3)
            ->timeout(45)
            ->post($endpoint, [
                'model' => $model,
                'messages' => $messages,
            ])
            ->throw()
            ->json();

        $text = Arr::get($completion, 'choices.0.message.content');

        return is_string($text) ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function botNumber(array $data): ?string
    {
        $recipient = Arr::get($data, 'to');

        if (! is_string($recipient)) {
            return null;
        }

        $number = preg_replace('/\D/', '', $recipient) ?? '';

        return $number === '' ? null : $number;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isMentioned(array $data, string $inputText): bool
    {
        $botNumber = $this->botNumber($data);

        if ($botNumber === null) {
            return false;
        }

        if (Str::contains($inputText, '@'.$botNumber)) {
            return true;
        }

        $mentions = Arr::get($data, 'mentionedIds', Arr::get(
            $data,
            'message.extendedTextMessage.contextInfo.mentionedJid',
            Arr::get($data, 'mentions', []),
        ));

        if (! is_array($mentions)) {
            return false;
        }

        foreach ($mentions as $mention) {
            if (is_string($mention) && preg_replace('/\D/', '', $mention) === $botNumber) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function autoreply(Session $session, array $data): bool
    {
        if ($session->chatbot !== 'templates') {
            return false;
        }

        $inputText = Str::lower($this->messageText($data));
        $receiver = $this->messageReceiver($data);

        if ($inputText === '' || $receiver === null) {
            return false;
        }

        $cacheKey = "menuchat_{$session->getKey()}_{$receiver}";
        $stored = Cache::get($cacheKey);
        $isNumericInput = ctype_digit($inputText);

        if ($isNumericInput) {
            $inputText = match ($inputText) {
                '0' => '0',
                '9' => $stored ? (string) intdiv((int) $stored, 10) : '0',
                default => strlen($inputText) === 1 && is_string($stored)
                    ? $stored.$inputText
                    : $inputText,
            };
        }

        $templates = Template::query()
            ->where('user_id', $session->user_id)
            ->where('enabled', true)
            ->orderBy('id')
            ->get(['id', 'name', 'text', 'avatar']);

        foreach ($templates as $template) {
            $keywords = array_map(
                static fn (string $keyword): string => Str::lower(trim($keyword)),
                explode(',', (string) $template->name),
            );
            $numericKeywords = array_values(array_filter($keywords, 'ctype_digit'));
            $numericKeyword = $numericKeywords[0] ?? null;

            if ($isNumericInput) {
                if ($numericKeyword === null || $inputText !== $numericKeyword) {
                    continue;
                }

                Cache::put($cacheKey, $numericKeyword, now()->addHour());

                return $this->sendAutoResponse(
                    $this->replacePlaceholders((string) $template->text, $session, $data, $receiver),
                    $session,
                    $receiver,
                    $template->avatar,
                );
            }

            foreach ($keywords as $keyword) {
                if ($keyword === '' || ctype_digit($keyword)) {
                    continue;
                }

                $cleanKeyword = preg_replace('/[^\pL\pN]+/u', ' ', $keyword) ?? '';
                $cleanInput = preg_replace('/[^\pL\pN]+/u', ' ', $inputText) ?? '';
                $cleanKeyword = trim($cleanKeyword);
                $cleanInput = trim($cleanInput);

                if ($cleanKeyword === '' || preg_match('/(^|\s)'.preg_quote($cleanKeyword, '/').'(\s|$)/u', $cleanInput) !== 1) {
                    continue;
                }

                if ($numericKeyword !== null) {
                    Cache::put($cacheKey, $numericKeyword, now()->addHour());
                }

                return $this->sendAutoResponse(
                    $this->replacePlaceholders((string) $template->text, $session, $data, $receiver),
                    $session,
                    $receiver,
                    $template->avatar,
                );
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replacePlaceholders(
        string $response,
        Session $session,
        array $data,
        string $receiver,
    ): string {
        return str_ireplace(
            ['{sessionId}', '{deviceId}', '{receiver}', '{name}', '{recnumber}', '{devtype}'],
            [
                (string) $session->getKey(),
                (string) $session->getKey(),
                $receiver,
                (string) (Arr::get($data, 'contact.pushName') ?? Arr::get($data, 'pushName') ?? ''),
                (string) (Arr::get($data, 'author') ?? Arr::get($data, 'senderPhone') ?? Arr::get($data, 'from') ?? ''),
                (string) (Arr::get($data, 'device') ?? Arr::get($data, 'source') ?? ''),
            ],
            $response,
        );
    }

    private function sendAutoResponse(
        string $autoResponse,
        Session $session,
        string $receiver,
        ?string $mediaUrl,
    ): bool {
        if (str_contains($autoResponse, 'msgtype')) {
            $data = json_decode($autoResponse, true);

            if (! is_array($data)) {
                $this->sendTextResponse(
                    $session,
                    $receiver,
                    'Response text is not valid JSON. Please correct the response text.',
                );

                return true;
            }

            $messageType = $data['msgtype'] ?? null;
            $content = $data['content'] ?? (is_string($messageType) ? ($data[$messageType] ?? null) : null);

            if (! is_string($messageType)
                || ! is_array($content)
                || (isset($data['options']) && ! is_array($data['options']))) {
                return false;
            }

            if ($messageType === 'textMessage' && is_string($content['text'] ?? null)) {
                $this->sendTextResponse($session, $receiver, $content['text']);

                return true;
            }

            $type = match ($messageType) {
                'imageMessage' => 'image',
                'videoMessage' => 'video',
                'audioMessage' => 'audio',
                'documentMessage' => 'document',
                'mediaMessage' => (string) ($content['mediatype'] ?? ''),
                default => '',
            };
            $url = $content['url'] ?? $content['media'] ?? null;

            if (! is_string($url) || trim($url) === '') {
                return false;
            }

            return $this->sendMediaResponse(
                $session,
                $receiver,
                $url,
                is_string($content['caption'] ?? null) ? $content['caption'] : '',
                $type,
                is_string($content['mimetype'] ?? null) ? $content['mimetype'] : null,
                is_string($content['filename'] ?? $content['fileName'] ?? null)
                    ? ($content['filename'] ?? $content['fileName'])
                    : null,
            );
        }

        if (is_string($mediaUrl) && trim($mediaUrl) !== '') {
            return $this->sendMediaResponse(
                $session,
                $receiver,
                $mediaUrl,
                $autoResponse,
            );
        }

        $this->sendTextResponse($session, $receiver, $autoResponse);

        return true;
    }

    private function sendTextResponse(Session $session, string $receiver, string $text): void
    {
        $this->client->sendText((string) $session->getKey(), [
            'chatId' => $receiver,
            'text' => $text,
        ]);
    }

    private function sendMediaResponse(
        Session $session,
        string $receiver,
        string $mediaUrl,
        string $caption = '',
        string $mediaType = '',
        ?string $mimeType = null,
        ?string $filename = null,
    ): bool {
        $path = parse_url($mediaUrl, PHP_URL_PATH);
        $extension = strtolower(pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION));
        $mediaType = $mediaType !== '' ? $mediaType : match ($extension) {
            'jpg', 'jpeg', 'png', 'gif', 'webp' => 'image',
            'mp4', '3gp', 'mov' => 'video',
            'mp3', 'ogg', 'wav', 'm4a' => 'audio',
            default => 'document',
        };

        $mimeType ??= match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            '3gp' => 'video/3gpp',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            default => 'application/octet-stream',
        };
        $payload = ['chatId' => $receiver, 'url' => $mediaUrl, 'mimetype' => $mimeType];

        if ($mediaType === 'image' || $mediaType === 'video') {
            $payload['caption'] = $caption;
        }

        if ($mediaType === 'audio') {
            $payload['ptt'] = true;
        }

        if ($mediaType === 'document') {
            $payload['filename'] = $filename ?? (basename(is_string($path) ? $path : '') ?: 'attachment');
            if ($caption !== '') {
                $payload['caption'] = $caption;
            }
        }

        match ($mediaType) {
            'image' => $this->client->sendImage((string) $session->getKey(), $payload),
            'video' => $this->client->sendVideo((string) $session->getKey(), $payload),
            'audio' => $this->client->sendAudio((string) $session->getKey(), $payload),
            'document' => $this->client->sendDocument((string) $session->getKey(), $payload),
            default => throw new InvalidArgumentException('Unsupported media type: '.$mediaType),
        };

        return true;
    }
}
