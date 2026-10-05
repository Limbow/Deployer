<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_root_redirects_to_the_interface(): void
    {
        $this->get('/')
            ->assertRedirect('/app/')
            ->assertHeader('Location', '/app/');
    }
}
