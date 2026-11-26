<?php

namespace Arrocy\Whatsapp;

use Illuminate\Support\ServiceProvider;

class WhatsappServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/whatsapp.php', 'whatsapp');
    }

    public function boot(): void
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'whatsapp');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->injectSidebarMenu();
    }

    protected function injectSidebarMenu(): void
    {
        $items = config('menu.sidebar.workspace.items');

        if (isset($items)) {
            $sessions = ['label' => 'Sessions', 'icon' => 'fa-solid fa-mobile-screen', 'route' => 'whatsapp.session.index'];
            $sendMessage = ['label' => 'Send message', 'icon' => 'fa-solid fa-paper-plane', 'route' => 'whatsapp.send-message'];
            $apiDocs = ['label' => 'API Docs', 'icon' => 'fa-solid fa-book', 'route' => 'whatsapp.api-docs', 'target' => '__blank'];
            $items[] = ['label' => 'Whatsapp', 'icon' => 'fa-brands fa-whatsapp', 'route' => 'whatsapp.*', 'children' => [$sessions, $sendMessage, $apiDocs]];
            config(['menu.sidebar.workspace.items' => $items]);
        }
    }
}
