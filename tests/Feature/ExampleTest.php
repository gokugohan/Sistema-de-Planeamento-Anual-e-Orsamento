<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    // Required: this test creates a user, which must not outlive it.
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_guests_are_sent_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_a_signed_in_user_sees_the_dashboard(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $user = \App\Models\User::factory()->create();
        $user->assignRole('Viewer');

        $this->actingAs($user)->get('/')->assertOk();
    }
}
