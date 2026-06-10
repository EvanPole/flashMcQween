<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_page_is_displayed(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Inscription');
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

    public function test_login_page_is_displayed(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Connexion');
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

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}
