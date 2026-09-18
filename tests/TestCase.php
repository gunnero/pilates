<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if ($app->configurationIsCached()
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'gt_test'
            || config('database.connections.pgsql.username') !== 'gt_test'
            || ! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost'], true)
            || config('database.connections.pgsql.url')) {
            throw new RuntimeException('Tests require uncached configuration and isolated loopback gt_test database/role.');
        }

        return $app;
    }
}
