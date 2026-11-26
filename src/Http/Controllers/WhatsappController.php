<?php

namespace Arrocy\Whatsapp\Http\Controllers;

use App\Http\Controllers\Controller;
use Arrocy\Whatsapp\Models\Session;
use Arrocy\Whatsapp\Services\ApiClient;
use Arrocy\Whatsapp\Support\ApiEndpointMap;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WhatsappController extends Controller
{
    public function index(): View
    {
        $totalSessions = Session::count();

        return view('whatsapp::index', compact('totalSessions'));
    }

    public function apikeySignUp(Request $request, ApiClient $client)
    {
        $hostname = $request->getHost();
        $response = $client->signup(['name' => $hostname]);
        $accessToken = isset($response['apiKey']) ? $response['apiKey'] : null;

        if ($accessToken) {
            setting(['access_token' => $accessToken]);

            return back()->with('success', __('This Access Token (API Key) is saved in Settings->access_token.'.': '.$accessToken));
        }

        return back()->with($accessToken ? 'success' : 'error', json_encode($response));
    }

    public function apiDocs(): View
    {
        $endpointGroups = [];
        $operationCount = 0;

        foreach (ApiEndpointMap::ROUTES as $uri => $methods) {
            if (Str::startsWith($uri, 'api/auth/api-keys')) continue;
            $segments = explode('/', trim($uri, '/'));
            $area = $segments[1] ?? __('Other');
            $category = Str::headline($area);

            if ($area === 'sessions') {
                $resource = $segments[2] ?? null;

                if ($resource === 'stats') {
                    $category = __('Session statistics');
                } elseif ($resource !== null && str_starts_with($resource, '{')) {
                    $resource = $segments[3] ?? null;
                    $category = $resource === null
                        ? __('Sessions')
                        : __('Sessions').' · '.Str::headline($resource);
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
}
