<?php

namespace App\Services;

use App\Exceptions\DeployException;
use App\Models\Deploy;
use App\Models\Server;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JsonException;

class VersionFileService
{
    public function __construct(private FtpTransferService $ftp) {}

    /**
     * @throws DeployException|JsonException
     */
    public function upload(Deploy $deploy, Server $server): string
    {
        $directory = rtrim($deploy->remote_path, '/').'/_deploys';
        $this->ftp->ensureDirectory($server, $directory);
        $accessFile = $directory.'/.htaccess';
        if (! $this->ftp->fileExists($server, $accessFile)) {
            $accessPath = tempnam(storage_path('app'), 'deploy-access-');
            if ($accessPath === false) {
                throw new DeployException('No se pudo preparar la proteccion de la carpeta de versiones.');
            }
            try {
                File::put($accessPath, "Require all denied\n");
                $this->ftp->upload($server, $accessPath, $accessFile);
            } finally {
                File::delete($accessPath);
            }
        }

        $stemVersion = substr(preg_replace('/[^A-Za-z0-9.+-]/', '_', $deploy->version) ?: 'version', 0, 100);
        $stem = 'deploy_v'.$stemVersion.'_'.now()->format('Ymd_His');
        $name = $stem.'.json';
        for ($suffix = 2; $this->ftp->fileExists($server, $directory.'/'.$name); $suffix++) {
            if ($suffix > 1000) {
                throw new DeployException('No se encontro un nombre libre para el archivo de version.');
            }
            $name = $stem.'_'.$suffix.'.json';
        }

        $commit = $this->gitCommit($deploy->source_path);
        $deploy->update(['git_commit' => $commit]);
        $files = $deploy->files()->get();
        $data = [
            'schema_version' => 1,
            'project' => ['id' => $deploy->project_id, 'name' => $deploy->project_name,
                'type' => $deploy->project_type, 'version' => $deploy->version],
            'deployed_at' => now()->toIso8601String(),
            'server' => ['id' => $deploy->server_id, 'name' => $deploy->server_name],
            'destinations' => ['backend' => $deploy->remote_path, 'public' => $deploy->public_remote_path],
            'changes' => $deploy->changes,
            'build_id' => $deploy->build_id,
            'git_commit' => $commit,
            'cleanup_obsolete_requested' => $deploy->delete_obsolete,
            'version_file' => $name,
            'files_uploaded' => $this->fileList($files->where('status', 'uploaded')),
            'files_deleted' => $this->fileList($files->where('status', 'deleted')),
            'files_already_absent' => $this->fileList($files->where('status', 'absent')),
            'files_preserved' => $files->where('status', 'skipped')->map(fn ($file) => [
                'path' => $file->relative_path, 'remote_path' => $file->remote_path,
                'reason' => $file->error,
            ])->values()->all(),
        ];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $localPath = tempnam(storage_path('app'), 'deploy-version-');
        if ($localPath === false) {
            throw new DeployException('No se pudo preparar el archivo de version.');
        }
        try {
            File::put($localPath, $json);
            $this->ftp->upload($server, $localPath, $directory.'/'.$name);
        } finally {
            File::delete($localPath);
        }

        return $name;
    }

    private function gitCommit(string $sourcePath): ?string
    {
        if (! file_exists($sourcePath.DIRECTORY_SEPARATOR.'.git')) {
            return null;
        }
        $result = Process::path($sourcePath)->timeout(5)->run(['git', 'rev-parse', '--short', 'HEAD']);
        if (! $result->successful()) {
            return null;
        }
        $commit = trim($result->output());

        return preg_match('/\A[0-9a-f]{7,40}\z/i', $commit) ? $commit : null;
    }

    private function fileList(iterable $files): array
    {
        return collect($files)->map(fn ($file) => [
            'path' => $file->relative_path,
            'remote_path' => $file->remote_path,
            'sha1' => $file->hash,
            'size' => $file->size,
        ])->values()->all();
    }
}
