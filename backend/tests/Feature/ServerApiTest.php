<?php

namespace Tests\Feature;

use App\Exceptions\FtpConnectionException;
use App\Models\Server;
use App\Services\FtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ServerApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'name' => 'Produccion',
            'host' => 'ftp.example.test',
            'port' => 21,
            'username' => 'deploy-user',
            'password' => 'private-password',
            'use_ftps' => true,
            'passive' => true,
            'remote_path' => '/public_html',
        ];
    }

    public function test_crud_encrypts_and_never_returns_the_password(): void
    {
        $created = $this->postJson('/api/servers', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.use_ftps', true)
            ->assertJsonMissingPath('data.password');
        $id = $created->json('data.id');
        $ciphertext = DB::table('servers')->where('id', $id)->value('password');

        $this->assertNotSame($this->payload()['password'], $ciphertext);
        $this->assertSame($this->payload()['password'], Server::findOrFail($id)->password);
        $this->getJson('/api/servers')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.password');
        $this->getJson("/api/servers/$id")->assertOk()->assertJsonMissingPath('data.password');

        $this->putJson("/api/servers/$id", [...$this->payload(), 'name' => 'Editado', 'password' => ''])
            ->assertOk()->assertJsonPath('data.name', 'Editado')->assertJsonMissingPath('data.password');
        $this->assertSame($ciphertext, DB::table('servers')->where('id', $id)->value('password'));

        $this->patchJson("/api/servers/$id", ['password' => 'new-password'])->assertOk()->assertJsonMissingPath('data.password');
        $this->assertSame('new-password', Server::findOrFail($id)->password);
        $this->patchJson("/api/servers/$id", ['name' => 'Sin cambiar clave'])->assertOk();
        $this->patchJson("/api/servers/$id", ['password' => null])->assertOk();
        $this->assertSame('new-password', Server::findOrFail($id)->password);

        $this->deleteJson("/api/servers/$id")->assertNoContent();
        $this->getJson("/api/servers/$id")->assertNotFound();
        $this->postJson("/api/servers/$id/test")->assertNotFound();
        $this->assertDatabaseMissing('servers', ['id' => $id]);
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->postJson('/api/servers', [
            ...$this->payload(),
            'name' => '',
            'host' => 'ftp://example.test/folder',
            'port' => 65536,
            'username' => "user\r\ninvalid",
            'password' => '',
            'use_ftps' => 'yes',
            'passive' => 'yes',
            'remote_path' => 'C:\\folder',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'name', 'host', 'port', 'username', 'password', 'use_ftps', 'passive', 'remote_path',
        ]);

        $this->assertDatabaseCount('servers', 0);
        $this->postJson('/api/servers', [...$this->payload(), 'host' => '::1'])->assertCreated();
    }

    public function test_the_connection_endpoint_returns_a_safe_result(): void
    {
        $server = Server::create($this->payload());
        $this->mock(FtpService::class, function ($mock) use ($server): void {
            $mock->shouldReceive('test')->once()
                ->withArgs(fn (Server $argument) => $argument->id === $server->id)
                ->andReturn(['message' => 'OK', 'protocol' => 'FTPS', 'remote_path' => '/public_html', 'entries' => ['index.html']]);
        });

        $this->postJson("/api/servers/$server->id/test")->assertOk()
            ->assertJsonPath('data.entries.0', 'index.html')
            ->assertJsonMissingPath('data.password');
    }

    public function test_connection_errors_are_explicit_and_logs_contain_no_credentials(): void
    {
        $server = Server::create($this->payload());
        Log::spy();
        $this->mock(FtpService::class, function ($mock): void {
            $mock->shouldReceive('test')->once()->andThrow(new FtpConnectionException('No se pudo conectar.'));
        });

        $this->postJson("/api/servers/$server->id/test")->assertStatus(502)
            ->assertExactJson(['message' => 'No se pudo conectar.']);
        Log::shouldHaveReceived('warning')->once()->with('Prueba FTP fallida.', [
            'server_id' => $server->id, 'reason' => 'No se pudo conectar.',
        ]);
    }
}
