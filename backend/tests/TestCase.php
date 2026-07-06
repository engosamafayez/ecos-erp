<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Env;
use ReflectionProperty;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Override all three env sources BEFORE parent::setUp() creates the Laravel app.
        // Docker env_file bakes DB_DATABASE=ecos_erp into OS env at container start.
        // PHPUnit's force="true" only calls putenv(); Laravel's immutable Dotenv reads
        // $_ENV first (the Docker value) so putenv() alone has no effect.
        putenv('DB_DATABASE=ecos_erp_test');
        $_ENV['DB_DATABASE'] = 'ecos_erp_test';
        $_SERVER['DB_DATABASE'] = 'ecos_erp_test';

        // Reset the static Env repository singleton so it re-reads from the updated vars
        // when the next app instance reads env('DB_DATABASE').
        $prop = new ReflectionProperty(Env::class, 'repository');
        $prop->setValue(null, null);

        parent::setUp();
    }
}
