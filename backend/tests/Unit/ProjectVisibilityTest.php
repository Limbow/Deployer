<?php

namespace Tests\Unit;

use App\Services\ProjectVisibility;
use Tests\TestCase;

class ProjectVisibilityTest extends TestCase
{
    public function test_windows_desktop_ancestor_does_not_hide_every_web_project(): void
    {
        $settings = array_replace(config('deploy'), ['projects_root' => 'C:\\Users\\Joaquin\\Desktop\\Desarrollo\\Angular']);
        $visibility = new ProjectVisibility;
        $this->assertNull($visibility->reason($settings['projects_root'].'\\site\\frontend', 'web', $settings));
        $this->assertSame('Carpeta excluida: desktop', $visibility->reason($settings['projects_root'].'\\desktop\\app', 'web', $settings));
        $this->assertNull($visibility->reason($settings['projects_root'].'\\desktop-app', 'web', $settings));
    }
}
