<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'sync.token' => \App\Http\Middleware\EnsureApiToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->command('ay:sync-categories')->dailyAt('03:15');
    })
    ->create();

/*
 * Laravel merges framework default config files from vendor on boot. On PHP 8.5+ that loads
 * vendor/laravel/framework/config/database.php, which references deprecated PDO::MYSQL_ATTR_SSL_CA.
 * The app already ships a full config set (plus the copies below); skipping the merge avoids
 * parsing the framework database stub while keeping behavior equivalent for a standard app.
 */
$app->dontMergeFrameworkConfiguration();

return $app;
