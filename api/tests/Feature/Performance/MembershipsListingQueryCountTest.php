<?php

namespace Tests\Feature\Performance;

use App\Models\Club;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The second B6 finding: upstream's memberships listing ran a member count
 * and a fee lookup per row (M0 baseline), so a page of fifty cost more than
 * a hundred queries. The listing now eager-loads; this guard keeps the query
 * count flat as the page grows (docs/PERFORMANCE.md).
 */
class MembershipsListingQueryCountTest extends TestCase
{
    public function test_a_page_of_memberships_costs_a_bounded_number_of_queries(): void
    {
        $club = Club::factory()->create();
        $type = MembershipType::factory()->create(['club_id' => $club->getKey()]);
        foreach (range(1, 20) as $i) {
            $membership = Membership::factory()->create([
                'club_id' => $club->getKey(),
                'membership_type_id' => $type->getKey(),
            ]);
            Member::factory()->count(3)->create([
                'club_id' => $club->getKey(),
                'membership_id' => $membership->getKey(),
            ]);
        }

        $admin = User::factory()->create();
        setPermissionsTeamId($club);
        $admin->assignRole('club admin');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($admin)
            ->jsonApi()
            ->expects('memberships')
            ->get('/api/v1/memberships?page[size]=50');

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertFetchedMany(Membership::query()->where('club_id', $club->getKey())->get());
        $this->assertLessThanOrEqual(
            15,
            $queries,
            "a page of 20 memberships ran {$queries} queries; the listing must not query per row",
        );
    }
}
