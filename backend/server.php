<?php

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

// Only normalize dynamic requests: PHP must retain its script metadata to serve static files.
if ($uri === '/' || ! file_exists(__DIR__.'/public'.$uri)) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__.'/public/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
}

return require __DIR__.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
