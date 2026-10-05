<?php

namespace App\Services;

use App\Exceptions\FtpConnectionException;
use App\Models\Server;
use Illuminate\Contracts\Encryption\DecryptException;

class FtpConnection
{
    public function open(Server $server, NativeFtpClient $client): void
    {
        if (! extension_loaded('ftp')) {
            throw new FtpConnectionException('La extension FTP de PHP no esta habilitada.');
        }
        if ($server->use_ftps && ! function_exists('ftp_ssl_connect')) {
            throw new FtpConnectionException('Esta instalacion de PHP no soporta FTPS.');
        }
        try {
            $password = $server->password;
        } catch (DecryptException) {
            throw new FtpConnectionException('No se pudo descifrar la contrasena. Restaura la APP_KEY original o guarda una nueva contrasena.');
        }
        if (! $client->connect($server->host, $server->port, $server->use_ftps)) {
            throw new FtpConnectionException('No se pudo conectar. Verifica host, puerto y protocolo FTP/FTPS.');
        }

        try {
            if (! $client->login($server->username, $password)) {
                throw new FtpConnectionException('El servidor rechazo el inicio de sesion. Verifica usuario y contrasena.');
            }
            if ($server->passive && ! $client->ignorePassiveAddress()) {
                throw new FtpConnectionException('No se pudo configurar la direccion del modo pasivo.');
            }
            if (! $client->passive($server->passive)) {
                throw new FtpConnectionException('No se pudo configurar el modo de transferencia.');
            }
        } catch (FtpConnectionException $exception) {
            if (! $client->close()) {
                throw new FtpConnectionException('No se pudo cerrar correctamente la conexion FTP.');
            }
            throw $exception;
        }
    }
}
