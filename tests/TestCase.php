<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pages are tested without a built front end (no public/build/manifest.json needed).
        $this->withoutVite();
    }
}
