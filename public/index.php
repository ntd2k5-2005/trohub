<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

// Keep .env values request-local under Apache's threaded PHP runtime.
if (PHP_ZTS && PHP_SAPI === 'apache2handler') {
    \Illuminate\Support\Env::disablePutenv();
}

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
