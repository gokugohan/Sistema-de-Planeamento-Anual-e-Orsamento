<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ActivityListSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticking_a_row_stays_in_the_browser_instead_of_rerendering_the_list(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Volt::test('pages.activities.list-activities')
            ->assertSeeHtml("\$wire.\$entangle('selected')")
            ->assertDontSeeHtml('wire:model.live="selected"');
    }
}
