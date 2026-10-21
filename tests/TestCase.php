<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $sqlitePath = dirname(__DIR__) . '/database/testing.sqlite';
        if (! file_exists($sqlitePath)) {
            touch($sqlitePath);
        }

        // Force SQLite testing database so local PostgreSQL is NEVER touched
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=' . $sqlitePath);
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $sqlitePath;
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = $sqlitePath;

        $app = parent::createApplication();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', $sqlitePath);

        return $app;
    }
}
