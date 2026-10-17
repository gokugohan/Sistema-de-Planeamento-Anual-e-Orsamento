<?php

namespace Tests\Feature;

use App\Models\Directorate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_directors_open_the_review_of_activities_and_planners_do_not(): void
    {
        $unit = $this->unit();
        $this->as('Director', $unit->id);
        $this->get(route('planning.paa-approval'))->assertOk();

        $this->as('Planning', $unit->id);
        $this->get(route('planning.paa-approval'))->assertForbidden();
    }
}
