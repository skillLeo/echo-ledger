<?php

namespace Tests;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Console pages link the Vite-built theme (C3); tests never need the built assets.
        $this->withoutVite();
        // A test that runs as production goes through TrustHosts, which sets this on Symfony's Request for the whole
        // process: the next test in the same worker must not inherit it.
        Request::setTrustedHosts([]);
    }

    /**
     * Each request serves one panel, as in production (since v1.6 the app at /app and the admin panel at /admin): the
     * panel a request made current does not stay current for the rest of the test.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $panel = Filament::getCurrentPanel();
        $this->app->forgetInstance(NavigationManager::class);
        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            Filament::setCurrentPanel($panel);
            $this->app->forgetInstance(NavigationManager::class);
        }
    }
}
