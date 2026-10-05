<?php

namespace Tests\Feature;

use Tests\TestCase;

class AngularRoutingTest extends TestCase
{
    private string $publicDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deploy-tool-test-'.bin2hex(random_bytes(8));
        mkdir($this->publicDirectory.DIRECTORY_SEPARATOR.'app', 0777, true);
        $this->app->usePublicPath($this->publicDirectory);
    }

    protected function tearDown(): void
    {
        $index = $this->publicDirectory.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'index.html';

        if (is_file($index)) {
            unlink($index);
        }

        rmdir($this->publicDirectory.DIRECTORY_SEPARATOR.'app');
        rmdir($this->publicDirectory);

        parent::tearDown();
    }

    public function test_the_interface_and_nested_routes_serve_the_angular_index(): void
    {
        $html = '<!doctype html><html><head><base href="/app/"></head><body>Deploy Tool</body></html>';
        file_put_contents(public_path('app/index.html'), $html);

        foreach (['/app', '/app/', '/app/projects/123'] as $uri) {
            $response = $this->get($uri);

            $response->assertOk();
            $this->assertSame($html, $response->baseResponse->getFile()->getContent());
        }
    }

    public function test_a_missing_build_returns_an_explicit_service_unavailable_error(): void
    {
        $this->getJson('/app/')
            ->assertStatus(503)
            ->assertJsonPath('message', 'La interfaz no esta compilada. Ejecuta npm run build en frontend.');
    }

    public function test_unknown_api_routes_do_not_return_the_angular_index(): void
    {
        $this->getJson('/api/not-found')->assertNotFound();
    }

    public function test_database_jobs_are_not_retried_before_the_worker_timeout(): void
    {
        $this->assertGreaterThan(3600, config('queue.connections.database.retry_after'));
    }
}
