@extends('layouts.main')

@section('title', __('WhatsApp sessions'))
@section('page-heading', __('WhatsApp sessions'))

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">@lang('WhatsApp sessions')</h1>
        <p class="text-body-secondary mb-0">@lang('Manage WhatsApp sessions and send text or media messages.')</p>
    </div>
</div>

@if (! is_string($accessToken) || $accessToken === '')
<x-alert variant="warning">
    <p>@lang('This workspace is not connected to the messaging server.')</p>
    <p>@lang('Please input in Settings -> access_token.')</p>
    <p>
        @lang("If you don\'t have access_token, please click here!") 
        <a href="{{ route('whatsapp.apikey-signup') }}" type="button" class="btn btn-sm btn-outline-primary">
            @lang('Create API key')
        </a>
    </p>
</x-alert>
@else
<x-card class="mb-4">
    <form method="POST" action="{{ route('whatsapp.session.store') }}">
        @csrf
        <div class="row g-3 align-items-end">
        <div class="col-md-8">
            <x-input name="name" id="session-name" label="Session name" placeholder="support-phone (Use 3–50 letters, numbers, or hyphens)" minlength="3" maxlength="50" required />
        </div>
        <div class="col-md-4">
            <x-button variant="dark" type="submit" class="w-100">
                <i class="fa-solid fa-plus me-2"></i>@lang('Create device')
            </x-button>
        </div>
        </div>
    </form>
</x-card>

<x-card>
    <h2 class="h5 fw-bold mb-3">@lang('Your WhatsApp sessions')</h2>
    @if ($sessions === [])
        <x-empty-state title="No sessions yet" description="Create a session to start connecting WhatsApp." />
    @else
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>@lang('Name')</th>
                        <th>@lang('Status')</th>
                        <th>@lang('Phone number')</th>
                        <th class="text-end">@lang('Actions')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sessions as $session)
                        @php
                            $status = (string) ($session['status'] ?? 'unknown');
                            $isReady = $status === 'ready';
                            $engineLoaded = (bool) ($session['engineLoaded'] ?? false);
                        @endphp
                        <tr>
                            <td class="fw-semibold">{{ $session['name'] ?? $session['id'] }}</td>
                            <td>
                                <span class="badge {{ $isReady ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ \Illuminate\Support\Str::headline($status) }}
                                </span>
                            </td>
                            <td>{{ $session['phone'] ?? '—' }}</td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('whatsapp.session.show', $session['id']) }}">
                                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>@lang('Open')
                                </a>
                                @if ($engineLoaded)
                                    <form method="POST" action="{{ route('whatsapp.session.stop', $session['id']) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                                            <i class="fa-solid fa-stop me-1"></i>@lang('Stop')
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('whatsapp.session.start', $session['id']) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-success" type="submit">
                                            <i class="fa-solid fa-play me-1"></i>@lang('Start')
                                        </button>
                                    </form>
                                @endif
                                @if ($isReady && $engineLoaded)
                                    <form method="POST" action="{{ route('whatsapp.session.disconnect', $session['id']) }}" class="d-inline"
                                        onsubmit="return confirm('{{ __('Log out this WhatsApp session?') }}')">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-warning" type="submit">
                                            <i class="fa-solid fa-plug-circle-xmark me-1"></i>@lang('Log out')
                                        </button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('whatsapp.session.destroy', $session['id']) }}" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('{{ __('Delete this session?') }}')">
                                        <i class="fa-solid fa-trash-can me-1"></i>@lang('Delete')
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-card>
@endif
@endsection
