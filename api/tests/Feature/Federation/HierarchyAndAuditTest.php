<?php

namespace Tests\Feature\Federation;

use App\Federation\Enums\ApplicationStatus;
use App\Federation\Models\AuditEntry;
use App\Models\Club;
use App\Models\Member;
use Illuminate\Database\QueryException;
use LogicException;

class HierarchyAndAuditTest extends FederationTestCase
{
    public function test_a_club_may_belong_to_a_member_organization_and_defaults_to_none(): void
    {
        $club = Club::factory()->create();
        $this->assertNull($club->memberOrganization);

        $club->memberOrganization()->associate($this->organization)->save();

        $this->assertTrue($club->fresh()->memberOrganization->is($this->organization));
        $this->assertTrue($this->organization->clubs()->whereKey($club)->exists());
        $this->assertTrue($this->organization->federation->is($this->federation));
    }

    public function test_deleting_an_organization_detaches_its_clubs_instead_of_deleting_them(): void
    {
        $club = Club::factory()->create(['member_organization_id' => $this->otherOrganization->getKey()]);

        $this->otherOrganization->delete();

        $this->assertNull($club->fresh()->member_organization_id);
    }

    public function test_an_organization_with_applications_cannot_be_deleted(): void
    {
        $this->startApplication();

        $this->expectException(QueryException::class);

        $this->organization->delete();
    }

    public function test_a_member_may_be_linked_to_a_user(): void
    {
        $club = Club::factory()->create();
        $member = Member::factory()->create(['club_id' => $club->getKey()]);
        $this->assertNull($member->user);

        $member->user()->associate($this->applicant)->save();

        $this->assertTrue($member->fresh()->user->is($this->applicant));
    }

    public function test_audit_entries_are_append_only(): void
    {
        $application = $this->startApplication();
        $this->transition($application, ApplicationStatus::SUBMITTED, $this->applicant);
        $entry = AuditEntry::query()->latest('id')->first();

        try {
            $entry->update(['reason' => 'tampered']);
            $this->fail('Expected LogicException on update');
        } catch (LogicException) {
            $this->assertNull($entry->fresh()->reason);
        }

        $this->expectException(LogicException::class);
        $entry->delete();
    }
}
