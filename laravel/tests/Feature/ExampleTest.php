<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_the_single_page_shell(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('login-form')
            ->assertSee('register-form')
            ->assertSee('app-shell');
    }
}
