<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityVersion;
use App\Models\Directorate;
use App\Models\User;
use App\Services\ActivityVersions;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** A version keeps every field that says what the activity is, so it can be read exactly as it was approved. */
#[Group('office')]
class VersionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function approvedActivity(): Activity
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');

        return Activity::where('fiscal_year', 2027)->where('directorate_id', Directorate::where('code', 'UDIG')->value('id'))->with(['lineItems', 'indicators'])->firstOrFail();
    }

    public function test_the_snapshot_holds_the_fields_that_used_to_be_left_out(): void
    {
        $activity = $this->approvedActivity();
        $activity->forceFill(['location' => 'Dili', 'procurement_required' => true, 'gender_responsive' => true, 'period_type' => 'quarterly', 'quarter' => 2])->save();
        $indicator = $activity->indicators->first();
        $indicator->forceFill(['measure' => 'Users', 'target_text' => 'At least 50'])->save();
        $line = $activity->lineItems()->first();
        $line->forceFill(['code' => 'L-77', 'account_code' => '22.01'])->save();

        $snapshot = app(ActivityVersions::class)->snapshot($activity->fresh(['lineItems.economicClassification', 'lineItems.fundingSource', 'indicators.actions']));

        $this->assertSame(3, $snapshot['schema']);
        $this->assertSame('Dili', $snapshot['activity']['location']);
        $this->assertTrue((bool) $snapshot['activity']['procurement_required']);
        $this->assertTrue((bool) $snapshot['activity']['gender_responsive']);
        $this->assertSame('quarterly', $snapshot['activity']['period_type']);
        $this->assertEquals(2, $snapshot['activity']['quarter']);
        $ind = collect($snapshot['indicators'])->firstWhere('id', $indicator->id);
        $this->assertSame('Users', $ind['measure']);
        $this->assertSame('At least 50', $ind['target_text']);
        $l = collect($snapshot['lines'])->firstWhere('id', $line->id);
        $this->assertSame('L-77', $l['code']);
        $this->assertSame('22.01', $l['account_code']);
    }

    public function test_an_older_snapshot_without_the_new_fields_still_compares_without_error(): void
    {
        $activity = $this->approvedActivity();
        $by = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();
        ActivityVersion::where('activity_id', $activity->id)->delete();
        ActivityVersion::create([
            'activity_id' => $activity->id, 'version' => 1, 'total' => 0, 'approved_by' => $by->id, 'approved_at' => now(),
            'snapshot' => ['activity' => ['code' => $activity->code], 'indicators' => [], 'lines' => []],
        ]);

        $changes = app(ActivityVersions::class)->changes($activity->fresh());

        $this->assertArrayHasKey('lines', $changes);
        $this->assertArrayHasKey('indicators', $changes);
    }
}
