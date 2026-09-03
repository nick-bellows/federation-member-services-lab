<?php

namespace Tests\Feature\Export;

use App\Actions\Export\ExportMemberResource;
use App\Models\Club;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipType;
use App\Support\OrderByIdList;
use Tests\TestCase;

/**
 * Exports keep the order the client asked for. Upstream used MySQL's FIELD();
 * the portable CASE expression must behave the same on every engine in the
 * compatibility matrix (docs/DATABASE_COMPATIBILITY.md).
 */
class ExportOrderTest extends TestCase
{
    public function test_an_export_returns_rows_in_the_requested_id_order(): void
    {
        [$club, [$a, $b, $c]] = $this->threeMembers();

        $ordered = (new ExportMemberResource)->getQuery([$c, $a, $b], $club->getKey())->pluck('id')->all();

        $this->assertSame([$c, $a, $b], $ordered);
    }

    public function test_ids_outside_the_list_sort_last_and_an_empty_list_adds_no_ordering(): void
    {
        [, [$a, $b, $c]] = $this->threeMembers();

        $query = Member::query()->whereIn('id', [$a, $b, $c]);
        $this->assertSame([$b, $a, $c], OrderByIdList::apply(clone $query, [$b, $a])->pluck('id')->all());
        $this->assertSame([$a, $b, $c], OrderByIdList::apply(clone $query, [])->orderBy('id')->pluck('id')->all());
    }

    /**
     * A club with one membership of a real type and three members; the
     * membership type matters because the memberships table's rollback
     * makes the column NOT NULL again.
     *
     * @return array{0: Club, 1: list<int>}
     */
    private function threeMembers(): array
    {
        $club = Club::factory()->create();
        $type = MembershipType::factory()->create(['club_id' => $club->getKey()]);
        $membership = Membership::factory()->create([
            'club_id' => $club->getKey(),
            'membership_type_id' => $type->getKey(),
        ]);
        $ids = Member::factory()->count(3)->create([
            'club_id' => $club->getKey(),
            'membership_id' => $membership->getKey(),
        ])->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [$club, $ids];
    }
}
