@extends('layouts.main')

@section('title', $sessionData['name'] ?? __('Session'))
@section('page-heading', $sessionData['name'] ?? __('Session'))

@php
    $status = (string) ($sessionData['status'] ?? 'unknown');
    $isReady = $status === 'ready';
    $engineLoaded = (bool) ($sessionData['engineLoaded'] ?? false);
@endphp

{{-- 'created', 'initializing', 'authenticating', 'qr_ready', 'connecting' --}}
@if (in_array($status, ['qr_ready', 'initializing', 'authenticating'], true))
    @push('scripts')
    <script>
        window.setTimeout(() => window.location.reload(), 5000);
    </script>
    @endpush
@endif

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <a href="{{ route('whatsapp.session.index') }}" class="small text-decoration-none">
            <i class="fa-solid fa-arrow-left me-1"></i>@lang('All WhatsApp sessions')
        </a>
        <h1 class="h3 fw-bold mt-2 mb-1">{{ $sessionData['name'] ?? __('Session') }}</h1>
        <div class="d-flex flex-wrap align-items-center gap-2 text-body-secondary">
            <span>@lang('Status'):</span>
            <span class="badge {{ $isReady ? 'text-bg-success' : 'text-bg-secondary' }}">
                {{ \Illuminate\Support\Str::headline($status) }}
            </span>
            @if (! empty($sessionData['phone']))
                <span>{{ $sessionData['phone'] }}</span>
            @endif
            @if (! empty($sessionData['pushName']))
                <span>({{ $sessionData['pushName'] }})</span>
            @endif
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        @if ($engineLoaded)
            <form method="POST" action="{{ route('whatsapp.session.stop', $sessionData['id']) }}">
                @csrf
                <x-button variant="secondary" type="submit"><i class="fa-solid fa-stop me-2"></i>@lang('Stop session')</x-button>
            </form>
        @else
            <form method="POST" action="{{ route('whatsapp.session.start', $sessionData['id']) }}">
                @csrf
                <x-button variant="dark" type="submit"><i class="fa-solid fa-play me-2"></i>@lang('Start session')</x-button>
            </form>
        @endif
        @if ($status === 'qr_ready')
            <form method="POST" action="{{ route('whatsapp.session.connect', $sessionData['id']) }}">
                @csrf
                <x-button variant="primary" outline type="submit"><i class="fa-solid fa-qrcode me-2"></i>@lang('Refresh QR')</x-button>
            </form>
        @endif
        @if ($isReady && $engineLoaded)
            <form method="POST" action="{{ route('whatsapp.session.disconnect', $sessionData['id']) }}"
                onsubmit="return confirm('{{ __('Log out this WhatsApp session?') }}')">
                @csrf
                <x-button variant="secondary" outline type="submit">
                    <i class="fa-solid fa-plug-circle-xmark me-2"></i>@lang('Log out')
                </x-button>
            </form>
        @endif
        <a href="{{ route('whatsapp.session.show', $sessionData['id']) }}" class="btn btn-outline-secondary">
            <i class="fa-solid fa-rotate me-2"></i>@lang('Refresh status')
        </a>
        <form method="POST" action="{{ route('whatsapp.session.destroy', $sessionData['id']) }}"
            onsubmit="return confirm('{{ __('Delete this session?') }}')">
            @csrf
            @method('DELETE')
            <x-button variant="danger" outline type="submit"><i class="fa-solid fa-trash-can me-2"></i>@lang('Delete')</x-button>
        </form>
    </div>
</div>

@if (! empty($sessionData['lastError']))
<x-alert variant="warning">
    <strong>@lang('WhatsApp reported a session issue:')</strong> {{ $sessionData['lastError'] }}
</x-alert>
@endif

@if (! empty($sessionData['restriction']))
<x-alert variant="warning">
    @lang('WhatsApp restriction'): {{ \Illuminate\Support\Str::headline((string) ($sessionData['restriction']['kind'] ?? 'unknown')) }}
    ({{ $sessionData['restriction']['code'] ?? __('no code') }})
    @if (! empty($sessionData['restriction']['expiresAt']))
        — @lang('expires') {{ $sessionData['restriction']['expiresAt'] }}
    @endif
</x-alert>
@endif

@if (filled($qrError ?? null))
<x-alert variant="warning">{{ $qrError }}</x-alert>
@endif

@if (is_string($qrCode ?? null) && $qrCode !== '')
    <x-card class="mb-4">
        <div class="text-center">
            <h2 class="h5 fw-bold">@lang('Scan to connect')</h2>
            <p class="text-body-secondary">@lang('Open WhatsApp on your phone, choose Linked devices, and scan this code.')</p>
            <p class="small text-body-secondary">@lang('This page refreshes automatically after scanning.')</p>
            <img src="{{ $qrCode }}" alt="@lang('WhatsApp pairing QR code')" width="280" height="280" class="img-fluid">
        </div>
    </x-card>
@elseif (in_array($status, ['created', 'initializing', 'authenticating'], true))
    <x-alert variant="info">@lang('WhatsApp is starting this session. This page refreshes while it is connecting.')</x-alert>
@elseif ($status === 'qr_ready')
    <x-alert variant="info">@lang('The QR code is not available yet. Use Refresh QR to request a new code.')</x-alert>
@endif

<x-card class="mb-4">
    <h2 class="h5 fw-bold mb-3">@lang('Session details')</h2>
    <dl class="row mb-0">
        <dt class="col-sm-3">@lang('Session ID')</dt>
        <dd class="col-sm-9 text-break">{{ $sessionData['id'] }}</dd>
        <dt class="col-sm-3">@lang('Session process')</dt>
        <dd class="col-sm-9">{{ $engineLoaded ? __('Running') : __('Stopped') }}</dd>
        @if (! empty($sessionData['connectedAt']))
            <dt class="col-sm-3">@lang('Connected at')</dt>
            <dd class="col-sm-9">{{ $sessionData['connectedAt'] }}</dd>
        @endif
        @if (! empty($sessionData['lastActive']))
            <dt class="col-sm-3">@lang('Last active')</dt>
            <dd class="col-sm-9">{{ $sessionData['lastActive'] }}</dd>
        @endif
    </dl>
</x-card>

<x-card class="mb-4">
    <h2 class="h5 fw-bold mb-1">@lang('Chatbot assignment')</h2>
    <p class="text-body-secondary">@lang('Choose how this session responds to incoming messages.')</p>
    <form method="POST" action="{{ route('whatsapp.session.chatbot.update', $sessionData['id']) }}">
        @csrf
        @method('PUT')
        <div class="row g-3 align-items-end">
            <div class="col-md-8">
                <label for="chatbot" class="form-label">@lang('Chatbot')</label>
                <select
                    class="form-select @error('chatbot') is-invalid @enderror"
                    id="chatbot"
                    name="chatbot"
                    aria-describedby="chatbot-help"
                >
                    <option value="" @selected(old('chatbot', $session->chatbot) === null)>@lang('None')</option>
                    <option value="templates" @selected(old('chatbot', $session->chatbot) === 'templates')>@lang('Templates')</option>
                    @if ($aiBots->isNotEmpty())
                        <optgroup label="@lang('Ai Bots')">
                            @foreach ($aiBots as $aiBot)
                                <option value="{{ $aiBot->name }}" @selected(old('chatbot', $session->chatbot) === $aiBot->name)>
                                    {{ $aiBot->name }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
                @error('chatbot')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="col-md-4">
                <x-button variant="dark" type="submit" class="w-100">
                    <i class="fa-solid fa-floppy-disk me-2"></i>@lang('Update assignment')
                </x-button>
            </div>
        </div>
    </form>
</x-card>

@if ($isReady && $engineLoaded)
<div class="row g-4">
    <div class="col-lg-6">
        <x-card>
            <h2 class="h5 fw-bold mb-3">@lang('Send a text message')</h2>
            <form method="POST" action="{{ route('whatsapp.send-message.text', $sessionData['id']) }}">
                @csrf
                <div class="mb-3">
                    <x-input name="chatId" label="Recipient number" placeholder="+15551234567" maxlength="50" required />
                </div>
                <div class="mb-3">
                    <x-textarea name="text" label="Message" rows="4" maxlength="4096" required />
                </div>
                <x-button variant="dark" type="submit"><i class="fa-solid fa-paper-plane me-2"></i>@lang('Send text')</x-button>
            </form>
        </x-card>
    </div>
    <div class="col-lg-6">
        <x-card>
            <h2 class="h5 fw-bold mb-3">@lang('Send a media message')</h2>
            <form method="POST" action="{{ route('whatsapp.send-message.media', $sessionData['id']) }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-3">
                    <x-input name="chatId" label="Recipient number" placeholder="+15551234567" maxlength="50" required />
                </div>
                <div class="mb-3">
                    <label for="mediafile" class="form-label">@lang('Image, video, audio, or document (max 10 MB)')</label>
                    <input class="form-control" id="mediafile" name="mediafile" type="file" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt" required>
                </div>
                <div class="mb-3">
                    <x-input name="caption" label="Caption" maxlength="1024" />
                </div>
                <x-button variant="dark" type="submit"><i class="fa-solid fa-paperclip me-2"></i>@lang('Send media')</x-button>
            </form>
        </x-card>
    </div>
</div>
@endif
@endsection
