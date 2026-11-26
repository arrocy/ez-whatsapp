<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@lang('WhatsApp API documentation')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<main class="container py-4 py-lg-5">
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">@lang('WhatsApp API documentation')</h1>
        <p class="text-body-secondary mb-0">
            @lang('Browse the API operations proxied by this WhatsApp package.')
        </p>
        <p class="text-body-secondary mb-0">
            @lang('Interactive documentation is available') <a href="{{ config('whatsapp.api_url').'/api/docs' }}" class="link-primary">@lang('here').</a>
        </p>
    </div>
    <span class="badge text-bg-primary rounded-pill px-3 py-2">
        {{ number_format($operationCount) }} @lang('operations')
    </span>
</div>

<x-card class="mb-4">
    <div class="row g-4">
        <div class="col-lg-7">
            <h2 class="h5 fw-bold">@lang('Base URL')</h2>
            <p class="text-body-secondary">@lang('Send requests to this application using the documented paths below.')</p>
            <code class="d-inline-block border rounded bg-body-tertiary px-3 py-2">{{ url('/') }}</code>
        </div>
        <div class="col-lg-5">
            <h2 class="h5 fw-bold">@lang('Authentication')</h2>
            <p class="text-body-secondary mb-2">
                @lang('Protected operations accept your caller credential as an X-API-Key header or a Bearer token.')
            </p>
            <pre class="small border rounded bg-body-tertiary p-3 mb-2"><code>Authorization: Bearer YOUR_API_KEY</code></pre>
            <p class="small text-body-secondary mb-0">
                @lang('The proxy forwards the caller’s credential; it never substitutes the application’s stored API key.')
                @lang('Metrics uses Bearer authentication. Health checks and ingress routes do not require an API key.')
            </p>
        </div>
    </div>
</x-card>

<x-card class="mb-4">
    <h2 class="h5 fw-bold">@lang('Example request')</h2>
    <p class="text-body-secondary">@lang('List sessions using a caller-provided API key:')</p>
    <pre class="small border rounded bg-body-tertiary p-3 mb-0"><code>curl \
  -H "Authorization: Bearer YOUR_API_KEY" \
  "{{ url('/api/sessions') }}"</code></pre>
</x-card>

<div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-end gap-3 mb-3">
    <div>
        <h2 class="h4 fw-bold mb-1">@lang('Available operations')</h2>
        <p class="text-body-secondary mb-0">@lang('Methods and paths are registered from the package API endpoint map.')</p>
    </div>
    <div class="w-100" style="max-width: 24rem;">
        <label for="endpoint-search" class="form-label small fw-semibold">@lang('Filter operations')</label>
        <input
            type="search"
            class="form-control"
            id="endpoint-search"
            placeholder="@lang('Search by method, path, or category')"
            autocomplete="off"
        >
    </div>
</div>

<p class="small text-body-secondary" id="endpoint-search-count" aria-live="polite">
    @lang('Showing :count operations', ['count' => number_format($operationCount)])
</p>

<div id="endpoint-groups">
    @foreach ($endpointGroups as $category => $endpoints)
        <x-card class="mb-3" data-endpoint-group>
            <h3 class="h6 fw-bold mb-3">{{ $category }}</h3>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 7rem;">@lang('Method')</th>
                            <th scope="col">@lang('Path')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($endpoints as $endpoint)
                            <tr
                                data-endpoint-row
                                data-search="{{ strtolower($category.' '.$endpoint['method'].' '.$endpoint['path']) }}"
                            >
                                <td>
                                    <span class="badge {{ in_array($endpoint['method'], ['GET', 'HEAD'], true) ? 'text-bg-primary' : ($endpoint['method'] === 'DELETE' ? 'text-bg-danger' : 'text-bg-secondary') }}">
                                        {{ $endpoint['method'] }}
                                    </span>
                                </td>
                                <td><code class="text-break">{{ $endpoint['path'] }}</code></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endforeach
</div>

<div class="alert alert-info" id="endpoint-search-empty" role="status" hidden>
    @lang('No operations match your search.')
</div>
</main>

@push('scripts')
<script>
    (() => {
        const searchInput = document.getElementById('endpoint-search');
        const endpointRows = [...document.querySelectorAll('[data-endpoint-row]')];
        const endpointGroups = [...document.querySelectorAll('[data-endpoint-group]')];
        const searchCount = document.getElementById('endpoint-search-count');
        const emptyState = document.getElementById('endpoint-search-empty');

        if (!searchInput || !searchCount || !emptyState) {
            return;
        }

        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim().toLowerCase();
            let visibleCount = 0;

            endpointRows.forEach((row) => {
                const isVisible = row.dataset.search.includes(query);
                row.hidden = !isVisible;

                if (isVisible) {
                    visibleCount++;
                }
            });

            endpointGroups.forEach((group) => {
                group.hidden = !group.querySelector('[data-endpoint-row]:not([hidden])');
            });

            searchCount.textContent = `{{ __('Showing :count operations', ['count' => '__COUNT__']) }}`
                .replace('__COUNT__', new Intl.NumberFormat().format(visibleCount));
            emptyState.hidden = visibleCount > 0;
        });
    })();
</script>
@endpush
@stack('scripts')
</body>
</html>
