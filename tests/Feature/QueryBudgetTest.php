<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Each page has a ceiling on the number of database queries it may run. The ceilings are set a little
 * above what the page needs today, so a new N+1 (a query per row) fails the build instead of reaching
 * users: on a hosted database every query is a network round trip.
 *
 * If a page legitimately needs more, raise its ceiling here on purpose and say why in the commit.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** route => maximum queries for one visit by an administrator, with the Gabinete sample loaded */
    private const BUDGET = [
        '/' => 48, // includes the year-at-a-glance panel (cycle, last month's reporting, the inbox); it does not grow with the data
        '/activities' => 44, // includes the per-directorate planning card
        '/activities/create' => 25,
        '/planning/paa-approval' => 30,
        '/planning/cycle' => 40,
        '/monitoring' => 40,
        '/monitoring/approval' => 25,
        '/reports/paa' => 60,
        '/management/entities' => 30,
        '/management/users' => 30,
        '/management/audit' => 25,
        '/management/settings' => 20,
        '/notifications' => 20,
        '/profile' => 20,
    ];

    public function test_pages_stay_within_their_query_budget(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $admin = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $over = [];
        foreach (self::BUDGET as $url => $max) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $status = $this->get($url)->getStatusCode();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertSame(200, $status, "{$url} did not open");
            if ($queries > $max) {
                $over[] = "{$url}: {$queries} queries (budget {$max})";
            }
        }

        $this->assertSame([], $over, "Pages over their query budget:\n".implode("\n", $over));
    }
}
