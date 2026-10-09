<?php

namespace Tests\Feature;

use App\Models\Directorate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ReviewPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(DatabaseSeeder::class);
    }

    private function unit(): Directorate
    {
        return Directorate::create(['name' => 'Unit '.uniqid(), 'code' => strtoupper(substr(uniqid(), -6))]);
    }

    private function as(string $role, ?int $unit = null): User
    {
        $u = User::factory()->create(['directorate_id' => $unit]);
        $u->assignRole($role);
        $this->actingAs($u);

        return $u;
    }

    public function test_units_can_open_their_allocation_review_but_not_the_technical_review(): void
    {
        $unit = $this->unit();
        $this->as('Director', $unit->id);

        $this->get(route('planning.director-review'))->assertOk();
        $this->get(route('planning.paa-approval'))->assertForbidden();
    }

    public function test_a_unit_cannot_answer_for_another_unit(): void
    {
        $mine = $this->unit();
        $other = $this->unit();
        $this->as('Director', $mine->id);

        Volt::test('pages.planning.director-budget-review')->set('unitId', (string) $other->id)->call('confirmAllocation')->assertForbidden();
    }
}
