<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests assert server responses, not compiled frontend artifacts.
        $this->withoutVite();
        config(['inertia.testing.ensure_pages_exist' => false]);
    }
}
