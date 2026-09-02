<?php

namespace Database\Seeders;

use App\Federation\Actions\StartApplication;
use App\Federation\Actions\TransitionApplication;
use App\Federation\Enums\ApplicationRole;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Models\Federation;
use App\Federation\Models\MemberOrganization;
use App\Federation\Models\Season;
use App\Models\Club;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The fictional Northgate Soccer Federation on top of upstream's fake clubs.
 * Every person and organization here is invented; e-mail addresses use the
 * reserved .example domain. Run after (or instead of) FakeDatabaseSeeder:
 *
 *   php artisan migrate:fresh --seeder=NorthgateDemoSeeder
 */
class NorthgateDemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (Federation::query()->where('code', 'NSF')->exists()) {
            $this->command?->warn('Northgate Soccer Federation already exists. Aborted seeding '.self::class.'.');

            return;
        }

        if (! Club::query()->exists()) {
            $this->call(FakeDatabaseSeeder::class);
        }

        $federation = Federation::create(['name' => 'Northgate Soccer Federation', 'code' => 'NSF']);

        $previousSeason = $federation->seasons()->create(['label' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
        $season = $federation->seasons()->create(['label' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);

        $youth = $federation->memberOrganizations()->create(['name' => 'Northgate Youth Soccer Association', 'code' => 'NYSA']);
        $adult = $federation->memberOrganizations()->create(['name' => 'Northgate Adult Soccer League', 'code' => 'NASL']);
        $referees = $federation->memberOrganizations()->create(['name' => 'Northgate Referee Association', 'code' => 'NRA']);

        $this->assignClubs($youth, $adult);

        $federationAdmin = $this->user('Federation Admin', 'federation-admin@northgate.example');
        $federation->administrators()->attach($federationAdmin);

        $youth->administrators()->attach($this->user('NYSA Admin', 'nysa-admin@northgate.example'));
        $adult->administrators()->attach($this->user('NASL Admin', 'nasl-admin@northgate.example'));
        $referees->administrators()->attach($this->user('NRA Admin', 'nra-admin@northgate.example'));

        $alex = $this->user('Alex Participant', 'alex.participant@northgate.example');
        $sam = $this->user('Sam Coach', 'sam.coach@northgate.example');
        $riley = $this->user('Riley Referee', 'riley.referee@northgate.example');
        $jordan = $this->user('Jordan Newcomer', 'jordan.newcomer@northgate.example');

        $start = app(StartApplication::class);
        $move = app(TransitionApplication::class);
        $youthAdmin = $youth->administrators()->first();
        $refereeAdmin = $referees->administrators()->first();

        // Submitted, waiting in the queue.
        $submitted = $start->execute($alex, $youth, $season, ApplicationRole::PARTICIPANT, 'seed-alex-participant');
        $move->execute($submitted, ApplicationStatus::SUBMITTED, $alex);

        // Picked up by a reviewer, then sent back for information.
        $needsInfo = $start->execute($sam, $youth, $season, ApplicationRole::COACH, 'seed-sam-coach');
        $move->execute($needsInfo, ApplicationStatus::SUBMITTED, $sam);
        $move->execute($needsInfo, ApplicationStatus::UNDER_REVIEW, $youthAdmin);
        $move->execute($needsInfo, ApplicationStatus::NEEDS_INFORMATION, $youthAdmin, 'Coaching licence number is missing.');

        // Approved by the referee association.
        $approved = $start->execute($riley, $referees, $season, ApplicationRole::REFEREE, 'seed-riley-referee');
        $move->execute($approved, ApplicationStatus::SUBMITTED, $riley);
        $move->execute($approved, ApplicationStatus::UNDER_REVIEW, $refereeAdmin);
        $move->execute($approved, ApplicationStatus::APPROVED, $refereeAdmin);

        // Rejected last season by the federation administrator, so a fresh application is possible.
        $rejected = $start->execute($riley, $youth, $previousSeason, ApplicationRole::COACH, 'seed-riley-coach-2025');
        $move->execute($rejected, ApplicationStatus::SUBMITTED, $riley);
        $move->execute($rejected, ApplicationStatus::UNDER_REVIEW, $federationAdmin);
        $move->execute($rejected, ApplicationStatus::REJECTED, $federationAdmin, 'Background check not completed before the deadline.');

        // Still a draft.
        $start->execute($jordan, $adult, $season, ApplicationRole::PARTICIPANT, 'seed-jordan-participant');
    }

    private function assignClubs(MemberOrganization $youth, MemberOrganization $adult): void
    {
        $clubs = Club::query()->orderBy('id')->get();

        // The last club stays unassigned on purpose: upstream clubs keep working without an organization.
        $clubs->slice(0, 3)->each(fn (Club $club) => $club->memberOrganization()->associate($youth)->save());
        $clubs->slice(3, 2)->each(fn (Club $club) => $club->memberOrganization()->associate($adult)->save());
    }

    private function user(string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
        ]);
    }
}
