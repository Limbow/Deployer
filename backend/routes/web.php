<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => new RedirectResponse('/app/'));

Route::get('/app/{any?}', function () {
    $index = public_path('app/index.html');

    abort_unless(is_file($index), 503, 'La interfaz no esta compilada. Ejecuta npm run build en frontend.');

    return response()->file($index);
})->where('any', '.*');
