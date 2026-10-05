@echo off
setlocal
cd /d "%~dp0backend"

where php >nul 2>nul
if errorlevel 1 (
    echo ERROR: PHP no esta disponible en el PATH.
    goto :error
)
if not exist ".env" (
    echo ERROR: Falta backend\.env. Consulta README.md.
    goto :error
)
if not exist "vendor\autoload.php" (
    echo ERROR: Ejecuta composer install en backend.
    goto :error
)
if not exist "public\app\index.html" (
    echo ERROR: Ejecuta npm run build en frontend antes de iniciar.
    goto :error
)

php -r "foreach (['ftp', 'openssl', 'pdo_mysql', 'fileinfo', 'mbstring'] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, 'ERROR: Falta la extension PHP '.$ext.PHP_EOL); exit(1); } }"
if errorlevel 1 goto :error

php artisan migrate:status
if errorlevel 1 (
    echo ERROR: Verifica MySQL y ejecuta php artisan migrate en backend.
    goto :error
)

start "deploy-tool server" php artisan serve --host=127.0.0.1 --port=8000 --tries=1
start "deploy-tool queue" php artisan queue:work --timeout=3660 --tries=1
powershell -NoProfile -Command "for ($i = 0; $i -lt 15; $i++) { try { $r = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8000/up' -TimeoutSec 2; if ($r.StatusCode -eq 200) { exit 0 } } catch { if ($i -eq 14) { Write-Error $_; exit 1 } }; Start-Sleep -Seconds 1 }; exit 1"
if errorlevel 1 (
    echo ERROR: Laravel no responde. Revisa la ventana del servidor y el puerto 8000.
    goto :error
)
start "" http://127.0.0.1:8000/app/
exit /b 0

:error
pause
exit /b 1
