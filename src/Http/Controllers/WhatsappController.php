<?php

namespace Arrocy\Whatsapp\Http\Controllers;

use App\Http\Controllers\Controller;
use Arrocy\Whatsapp\Models\Session;
use Arrocy\Whatsapp\Services\ApiClient;
use Illuminate\Http\Request;
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
}
