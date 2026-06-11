<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_route_returns_to_single_page_shell(): void
    {
        $this->get('/register')
            ->assertRedirect('/');
    }

    public function test_user_can_register(): void
    {
        $this->post('/register', [
            'name' => 'Evan',
            'email' => 'evan@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect('/');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'name' => 'Evan',
            'email' => 'evan@example.com',
        ]);
    }

    public function test_user_can_register_with_json_response(): void
    {
        $this->postJson('/register', [
            'name' => 'Evan',
            'email' => 'evan@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('redirect', '/')
            ->assertJsonPath('user.email', 'evan@example.com');

        $this->assertAuthenticated();
    }

    public function test_login_route_returns_to_single_page_shell(): void
    {
        $this->get('/login')
            ->assertRedirect('/');
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'evan@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->post('/login', [
            'email' => 'evan@example.com',
            'password' => 'password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_login_with_json_response(): void
    {
        $user = User::factory()->create([
            'email' => 'evan@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/login', [
            'email' => 'evan@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('redirect', url('/'))
            ->assertJsonPath('user.email', 'evan@example.com');

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_login_with_invalid_password(): void
    {
        User::factory()->create([
            'email' => 'evan@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->post('/login', [
            'email' => 'evan@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_json_login_returns_validation_error_for_invalid_password(): void
    {
        User::factory()->create([
            'email' => 'evan@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/login', [
            'email' => 'evan@example.com',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}
