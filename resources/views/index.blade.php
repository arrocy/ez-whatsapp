@extends('layouts.main')

@section('title', __('WhatsApp'))
@section('page-heading', __('WhatsApp overview'))

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">@lang('Hello :name', ['name' => auth()->user()->name])</h1>
        <p class="text-body-secondary mb-0">@lang('WhatsApp platform on the go. Manage WhatsApp sessions.')</p>
    </div>
    <x-badge variant="primary" pill class="px-3 py-2"><i class="fa-solid fa-circle me-1 small"></i>@lang('WhatsApp Platform overview')</x-badge>
</div>

@can('admin')
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <x-card class="h-100">
            <div class="d-flex align-items-center justify-content-between">
                <div><div class="text-body-secondary small mb-2">@lang('Total WhatsApp sessions')</div><div class="display-6 fw-bold">{{ number_format($totalSessions) }}</div></div>
                <span class="stat-icon"><i class="fa-solid fa-tag"></i></span>
            </div>
            <a href="{{ route('whatsapp.session.index') }}" class="small">@lang('Manage WhatsApp sessions') <i class="fa-solid fa-arrow-right ms-1"></i></a>
        </x-card>
    </div>
</div>
@endcan

@can('admin')
<x-card>
    <h2 class="h5 fw-bold">@lang('Grow your whatsapp platform')</h2>
    <p class="text-body-secondary mb-3">@lang('Manage accounts and customer workspaces from the navigation. Your platform overview updates as your business grows.')</p>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('user.index') }}" class="btn btn-outline-primary"><i class="fa-solid fa-users me-2"></i>@lang('Manage users')</a>
    </div>
</x-card>
@endcan
@endsection