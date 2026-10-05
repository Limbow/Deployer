<?php

namespace Tests\Feature;

use App\Models\Build;
use App\Models\Deploy;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\CreatesAngularWorkspaces;
use Tests\TestCase;

class ProjectVersionTest extends TestCase
{
    use CreatesAngularWorkspaces, RefreshDatabase;

    private string $angularPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaceRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deploy-version-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->workspaceRoot);
        $this->angularPath = $this->createWorkspace('angular', ['site' => $this->application()]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspaceRoot);
        parent::tearDown();
    }

    private function angularProject(): Project
    {
        return Project::create([
            'name' => 'Angular site', 'type' => 'angular', 'target_platform' => 'web',
            'local_path' => $this->angularPath, 'angular_project' => 'site',
            'build_output_path' => $this->angularPath.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'site',
            'build_command' => 'npx ng build site', 'ignore_patterns' => [], 'current_version' => '1.2.3',
        ]);
    }

    private function laravelProject(string $version): Project
    {
        $path = $this->workspaceRoot.DIRECTORY_SEPARATOR.'laravel';
        File::ensureDirectoryExists($path);

        return Project::create([
            'name' => 'Laravel API', 'type' => 'laravel', 'target_platform' => 'web',
            'local_path' => $path, 'angular_project' => null, 'build_output_path' => $path,
            'build_command' => null, 'ignore_patterns' => [], 'current_version' => $version,
        ]);
    }

    public function test_angular_version_bump_uses_npm_without_running_lifecycle_scripts_or_git_tags(): void
    {
        $packagePath = $this->angularPath.DIRECTORY_SEPARATOR.'package.json';
        $package = json_decode(File::get($packagePath), true, 512, JSON_THROW_ON_ERROR);
        $package['scripts'] = [
            'preversion' => 'node -e "require(\'fs\').writeFileSync(\'version-hook-ran\', \'yes\')"',
        ];
        File::put($packagePath, json_encode($package, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $project = $this->angularProject();

        $response = $this->postJson('/api/projects/'.$project->id.'/bump-version', ['type' => 'patch'])
            ->assertOk()->assertJsonPath('data.project.current_version', '1.2.4');

        $this->assertContains('package.json', $response->json('data.updated_files'));
        $this->assertSame('1.2.4', json_decode(File::get($packagePath), true, 512, JSON_THROW_ON_ERROR)['version']);
        $this->assertFileDoesNotExist($this->angularPath.DIRECTORY_SEPARATOR.'version-hook-ran');
        $this->assertSame('1.2.4', $project->fresh()->current_version);
    }

    public function test_laravel_increments_semver_metadata_without_modifying_composer_json(): void
    {
        $project = $this->laravelProject('2.3.4');
        $composerPath = $project->local_path.DIRECTORY_SEPARATOR.'composer.json';
        $composer = json_encode(['name' => 'fixture/api', 'version' => '2.3.4'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        File::put($composerPath, $composer);

        $this->postJson('/api/projects/'.$project->id.'/bump-version', ['type' => 'patch'])
            ->assertOk()->assertJsonPath('data.project.current_version', '2.3.5')->assertJsonPath('data.updated_files', []);
        $this->postJson('/api/projects/'.$project->id.'/bump-version', ['type' => 'minor'])
            ->assertOk()->assertJsonPath('data.project.current_version', '2.4.0');
        $this->postJson('/api/projects/'.$project->id.'/bump-version', ['type' => 'major'])
            ->assertOk()->assertJsonPath('data.project.current_version', '3.0.0');

        $this->assertSame($composer, File::get($composerPath));
    }

    public function test_version_bump_validates_semver_and_rejects_active_builds_or_deploys(): void
    {
        $project = $this->laravelProject('dev-main');
        $this->postJson('/api/projects/'.$project->id.'/bump-version', ['type' => 'patch'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/projects/'.$project->id.'/bump-version', ['type' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $project->update(['current_version' => '1.0.0']);
        Build::create([
            'project_id' => $project->id, 'status' => 'running', 'project_type' => 'laravel',
            'local_path' => $project->local_path, 'build_output_path' => $project->build_output_path,
            'command' => 'fixture', 'log' => '',
        ]);
        $this->postJson('/api/projects/'.$project->id.'/bump-version', ['type' => 'patch'])->assertStatus(409);
        Build::query()->where('project_id', $project->id)->delete();

        Deploy::create([
            'project_id' => $project->id, 'project_name' => $project->name,
            'project_type' => 'laravel', 'version' => '1.0.0', 'status' => 'running',
            'remote_path' => '/backend', 'log' => '',
        ]);
        $this->postJson('/api/projects/'.$project->id.'/bump-version', ['type' => 'patch'])->assertStatus(409);
        $this->assertSame('1.0.0', $project->fresh()->current_version);
    }
}
