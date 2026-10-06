<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => new RedirectResponse('/app/'));

Route::get('/app/{asset}', fn () => response(
    'Archivo de la interfaz no encontrado. Recarga la pagina para cargar la compilacion actual.',
    404,
    ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'],
))->where('asset', '.*\.(?:js|mjs|css|map|ico|png|jpg|jpeg|gif|svg|webp|woff|woff2|ttf|json|txt)');

Route::get('/app/{any?}', function () {
    $index = public_path('app/index.html');

    abort_unless(is_file($index), 503, 'La interfaz no esta compilada. Ejecuta npm run build en frontend.');

    return response()->file($index, ['Cache-Control' => 'no-store, no-cache, must-revalidate']);
})->where('any', '.*');
