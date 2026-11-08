<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com', 'password' => 'correct horse battery']);

        $this->post(route('login.store'), ['email' => 'me@example.com', 'password' => 'correct horse battery'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'me@example.com', 'password' => 'correct horse battery']);

        $this->from(route('login'))
            ->post(route('login.store'), ['email' => 'me@example.com', 'password' => 'wrong'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_attempts_are_throttled(): void
    {
        User::factory()->create(['email' => 'me@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), ['email' => 'me@example.com', 'password' => 'wrong']);
        }

        $this->post(route('login.store'), ['email' => 'me@example.com', 'password' => 'wrong'])->assertTooManyRequests();
    }

    public function test_logged_in_users_are_sent_from_login_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get(route('login'))->assertRedirect(route('dashboard'));
    }
}
