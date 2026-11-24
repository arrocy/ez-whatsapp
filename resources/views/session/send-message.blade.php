@extends('layouts.main')

@section('title', __('Send message'))
@section('page-heading', __('Send message'))

@section('content')
<div class="mb-4">
    <h1 class="h3 fw-bold mb-1">@lang('Send a message')</h1>
    <p class="text-body-secondary mb-0">@lang('Choose a WhatsApp session and recipients, then compose your message.')</p>
</div>

@if (! is_string($accessToken ?? null) || $accessToken === '')
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
@endif

@if ($errors->has('node'))
<x-alert variant="warning">{{ $errors->first('node') }}</x-alert>
@endif

<x-card>
    <form method="POST" action="{{ route('whatsapp.send-message') }}">
        @csrf

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="session-id" class="form-label">@lang('WhatsApp session')</label>
                <select class="form-select @error('session_id') is-invalid @enderror" id="session-id" name="session_id" required>
                    @forelse ($sessions as $session)
                    <option value="{{ $session['id'] }}" @selected(old('session_id') === ($session['id'] ?? null))>
                        {{ $session['name'] ?? $session['id'] }}
                    </option>
                    @empty
                    <option disabled>@lang('No sessions available')</option>
                    @endforelse
                </select>
                @error('session_id')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                @if ($sessions === [])
                <div class="form-text">@lang('No sessions are currently available.')</div>
                @endif
            </div>

            <div class="col-md-6 mb-4">
                <label for="media-url" class="form-label">@lang('Media URL') (@lang('Optional'))</label>
                <input
                    class="form-control @error('mediaurl') is-invalid @enderror"
                    id="media-url"
                    name="mediaurl"
                    type="url"
                    value="{{ old('mediaurl') }}"
                    placeholder="https://example.com/image.jpg"
                >
                @error('mediaurl')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            @if (class_exists(\Arrocy\Phonebook\PhonebookServiceProvider::class))
            <div class="col-md-6 mb-3">
                <label for="phonebook-id" class="form-label">@lang('Phonebook') (@lang('Optional'))</label>
                <select
                    class="form-select @error('phonebook_id') is-invalid @enderror"
                    id="phonebook-id"
                    name="phonebook_id"
                    aria-describedby="phonebook-help"
                >
                    <option value="">@lang('Please select a phonebook')</option>
                    @forelse (($phonebooks ?? []) as $phonebook)
                    <option value="{{ $phonebook->id }}" @selected(in_array((string) $phonebook->id, old('phonebook_id', []), true))>
                        {{ $phonebook->name }} ({{ count($phonebook->data ?? []) }} @lang('contacts'))
                    </option>
                    @empty
                    <option disabled>@lang('No phonebooks available')</option>
                    @endforelse
                </select>
                <div class="form-text" id="phonebook-help">@lang('Select one phonebook.')</div>
                @error('phonebook_id')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            @endif

            @if (class_exists(\Arrocy\Template\TemplateServiceProvider::class))
            <div class="col-md-6 mb-3">
                <label for="template-id" class="form-label">@lang('Template') (@lang('Optional'))</label>
                <select class="form-select" id="template-id" name="template_id">
                    <option value="">@lang('Write a message without a template')</option>
                    @forelse (($templates ?? []) as $template)
                    <option
                        value="{{ $template->id }}"
                        data-message="{{ $template->text }}"
                        @selected((string) old('template_id') === (string) $template->id)
                    >
                        {{ $template->name }}
                    </option>
                    @empty
                    <option disabled>@lang('No templates available')</option>
                    @endforelse
                </select>
            </div>
            @endif

            <div class="col-md-6 mb-3">
                <label for="receivers" class="form-label">@lang('Receiver numbers')</label>
                <textarea
                    class="form-control @error('receivers') is-invalid @enderror"
                    id="receivers"
                    name="receivers"
                    rows="5"
                    placeholder="15551234567&#10;15557654321"
                    aria-describedby="receivers-help"
                >{{ old('receivers') }}</textarea>
                <div class="form-text" id="receivers-help">@lang('Enter one phone number per row, including the country code.')</div>
                @error('receivers')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-md-6 mb-3">
                <label for="text" class="form-label">@lang('Text message')</label>
                <textarea
                    class="form-control @error('text') is-invalid @enderror"
                    id="text"
                    name="text"
                    rows="5"
                    maxlength="4096"
                    required
                >{{ old('text') }}</textarea>
                @error('text')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="d-flex justify-content-end">
            <x-button variant="dark" type="submit" :disabled="$sessions === []">
                <i class="fa-solid fa-paper-plane me-2"></i>@lang('Send message')
            </x-button>
        </div>
    </form>
</x-card>
@endsection

@push('scripts')
<script>
    document.getElementById('template-id')?.addEventListener('change', function () {
        const selectedTemplate = this.options[this.selectedIndex];

        if (selectedTemplate.value !== '') {
            document.getElementById('text').value = selectedTemplate.dataset.message ?? '';
        }
    });
</script>
@endpush