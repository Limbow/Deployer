<?php

namespace App\Services;

use App\Exceptions\FtpConnectionException;
use App\Models\Server;

class FtpService
{
    private FtpConnection $connection;

    public function __construct(private NativeFtpClient $client, ?FtpConnection $connection = null)
    {
        $this->connection = $connection ?? new FtpConnection;
    }

    public function test(Server $server): array
    {
        $this->connection->open($server, $this->client);
        try {
            if (! $this->client->changeDirectory($server->remote_path)) {
                throw new FtpConnectionException('No se pudo acceder a la carpeta remota. Verifica la ruta y los permisos.');
            }

            $entries = $this->client->listFiles();

            if ($entries === false) {
                throw new FtpConnectionException('No se pudo listar la carpeta remota. Verifica permisos, modo pasivo y firewall.');
            }

            sort($entries, SORT_STRING);

            return [
                'message' => 'Conexion y listado de la carpeta remota correctos.',
                'protocol' => $server->use_ftps ? 'FTPS' : 'FTP',
                'remote_path' => $server->remote_path,
                'entries' => $entries,
            ];
        } finally {
            if (! $this->client->close()) {
                throw new FtpConnectionException('No se pudo cerrar correctamente la conexion FTP.');
            }
        }
    }
}
