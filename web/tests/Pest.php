<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests boot the Laravel app and get a fresh in-memory schema per test.
uses(TestCase::class, RefreshDatabase::class)->in('Feature');

// Unit tests are plain and fast (pure classes); they do not boot the framework.
