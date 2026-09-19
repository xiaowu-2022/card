<?php

use Illuminate\Http\Request;

// Invoked only by the dedicated acceptance PHP server, never by application routes.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(dirname(__DIR__, 2).'/public'.$path)) {
    return false;
}
require __DIR__.'/bootstrap.php';
$app->handleRequest(Request::capture());
