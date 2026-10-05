<?php

namespace Tests\Unit;

use App\Exceptions\ProjectScanException;
use App\Services\LocalPath;
use App\Services\ProjectScanner;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\CreatesAngularWorkspaces;
use Tests\TestCase;

class ProjectScannerTest extends TestCase
{
    use CreatesAngularWorkspaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaceRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deploy-scanner-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->workspaceRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspaceRoot);
        parent::tearDown();
    }

    #[DataProvider('outputCases')]
    public function test_resolves_the_actual_browser_output(mixed $output, string $builder, array $production, string $expected): void
    {
        $path = $this->createWorkspace('project with spaces', ['site' => $this->application($output, $builder, $production)]);
        $result = $this->app->make(ProjectScanner::class)->inspect($path);

        $this->assertSame(LocalPath::absolute($expected, $path), $result['applications'][0]['build_output_path']);
        $this->assertSame('1.2.3', $result['current_version']);
        $this->assertStringContainsString('--configuration production', $result['applications'][0]['build_command']);
    }

    public static function outputCases(): array
    {
        return [
            ['dist/site', '@angular/build:application', [], 'dist/site/browser'],
            ['dist/site', '@angular-devkit/build-angular:application', [], 'dist/site/browser'],
            [['base' => '../backend/public/app', 'browser' => ''], '@angular/build:application', [], '../backend/public/app'],
            [['base' => 'dist/site'], '@angular/build:application', [], 'dist/site/browser'],
            [['base' => 'dist/site', 'browser' => 'web'], '@angular/build:application', [], 'dist/site/web'],
            ['dist/site', '@angular-devkit/build-angular:browser', [], 'dist/site'],
            ['dist/site', '@angular-devkit/build-angular:browser-esbuild', [], 'dist/site'],
            [null, '@angular/build:application', [], 'dist/site/browser'],
            ['dist/dev', '@angular/build:application', ['outputPath' => 'dist/prod'], 'dist/prod/browser'],
            ['dist/dev', '@angular/build:application', ['outputPath' => ['base' => 'dist/prod', 'browser' => '']], 'dist/prod'],
            ['dist\\site', '@angular/build:application', [], 'dist/site/browser'],
        ];
    }

    public function test_supports_jsonc_and_targets_without_corrupting_strings(): void
    {
        $path = $this->createWorkspace('jsonc', ['site' => $this->application()]);
        File::put($path.DIRECTORY_SEPARATOR.'angular.json', <<<'JSON'
{
  // workspace comment
  "projects": {
    "site": {
      "projectType": "application",
      "targets": {
        "build": {
          "builder": "@angular/build:application",
          "options": { "outputPath": { "base": "dist/site", "browser": "", }, },
        },
      },
    },
  },
}
JSON);
        File::put($path.DIRECTORY_SEPARATOR.'package.json', '{"version":"1.0.0","homepage":"https://example.test/a/*b*/",}');
        $result = $this->app->make(ProjectScanner::class)->inspect($path);
        $this->assertSame('1.0.0', $result['current_version']);
        $this->assertSame(LocalPath::absolute('dist/site', $path), $result['applications'][0]['build_output_path']);
    }

    public function test_scans_nested_workspaces_excludes_dependencies_and_reports_invalid_projects(): void
    {
        $this->createWorkspace('nested'.DIRECTORY_SEPARATOR.'frontend', ['site' => $this->application()]);
        $this->createWorkspace('node_modules'.DIRECTORY_SEPARATOR.'dependency', ['dependency' => $this->application()]);
        $this->createWorkspace('.git'.DIRECTORY_SEPARATOR.'ignored', ['ignored' => $this->application()]);
        $broken = $this->createWorkspace('broken', ['site' => $this->application()]);
        File::put($broken.DIRECTORY_SEPARATOR.'angular.json', '{broken');
        $this->createWorkspace('unsupported', ['site' => $this->application('dist/site', 'custom:builder')]);
        $result = $this->app->make(ProjectScanner::class)->scan($this->workspaceRoot);

        $this->assertCount(1, $result['projects']);
        $this->assertCount(2, $result['issues']);
        $this->assertSame('site', $result['projects'][0]['angular_project']);
    }

    public function test_reports_each_application_and_ignores_libraries(): void
    {
        $path = $this->createWorkspace('multi', [
            'first' => $this->application(), 'second' => $this->application('dist/second'),
            'library' => ['projectType' => 'library'],
        ]);
        $result = $this->app->make(ProjectScanner::class)->scan($path);
        $this->assertCount(2, $result['projects']);
        $this->assertSame(['first', 'second'], array_column($result['projects'], 'angular_project'));
    }

    public function test_invalid_root_is_not_reported_as_an_empty_success(): void
    {
        $this->expectException(ProjectScanException::class);
        $this->app->make(ProjectScanner::class)->scan($this->workspaceRoot.DIRECTORY_SEPARATOR.'missing');
    }

    public function test_invalid_browser_suffix_and_missing_legacy_output_are_reported(): void
    {
        $this->createWorkspace('escape', ['site' => $this->application(['base' => 'dist', 'browser' => '../outside'])]);
        $this->createWorkspace('legacy', ['site' => $this->application(null, '@angular-devkit/build-angular:browser')]);
        $result = $this->app->make(ProjectScanner::class)->scan($this->workspaceRoot);
        $this->assertCount(0, $result['projects']);
        $this->assertCount(2, $result['issues']);
    }

    public function test_does_not_scan_the_deploy_tool_frontend(): void
    {
        $result = $this->app->make(ProjectScanner::class)->scan(base_path('../frontend'));
        $this->assertSame(['projects' => [], 'issues' => []], $result);
    }

    public function test_explicit_null_output_is_invalid_not_a_silent_default(): void
    {
        $application = $this->application();
        $application['architect']['build']['options']['outputPath'] = null;
        $this->createWorkspace('null-output', ['site' => $application]);
        $this->createWorkspace('null-browser', ['site' => $this->application(['base' => 'dist/site', 'browser' => null])]);
        $result = $this->app->make(ProjectScanner::class)->scan($this->workspaceRoot);
        $this->assertCount(0, $result['projects']);
        $this->assertCount(2, $result['issues']);
    }

    public function test_normalizes_absolute_windows_unc_and_relative_output_paths(): void
    {
        $this->assertSame(LocalPath::native('D:/dist/web'), LocalPath::absolute('D:\\dist\\web', 'C:\\projects\\site'));
        $this->assertSame(LocalPath::native('C:/projects/dist/browser'), LocalPath::absolute('../dist/./browser', 'C:\\projects\\site'));
        $this->assertSame(LocalPath::native('//host/share/web'), LocalPath::absolute('\\\\host\\share\\build\\..\\web', 'C:\\projects\\site'));
    }

    public function test_rejects_ambiguous_drive_relative_output_paths(): void
    {
        $this->expectException(ProjectScanException::class);
        LocalPath::absolute('C:', 'C:\\projects\\site');
    }

    public function test_scan_limit_reports_an_error_instead_of_partial_success(): void
    {
        $children = array_map(fn (int $index) => $this->workspaceRoot.DIRECTORY_SEPARATOR.'missing-'.$index, range(1, 5000));
        File::partialMock()->shouldReceive('directories')->once()->with($this->workspaceRoot)->andReturn($children);
        $this->expectException(ProjectScanException::class);
        $this->expectExceptionMessage('demasiados directorios');
        $this->app->make(ProjectScanner::class)->scan($this->workspaceRoot);
    }
}
