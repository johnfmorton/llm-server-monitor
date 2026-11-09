<?php

namespace Tests;

use App\Monitoring\Review\Reviewer;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\FakeReviewer;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests never call the real model. A test that cares about the review
     * binds its own FakeReviewer.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Reviewer::class, FakeReviewer::ok());
    }
}
