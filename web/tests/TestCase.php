<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Console pages link the Vite-built theme (C3); tests never need the built assets.
        $this->withoutVite();
    }
}
