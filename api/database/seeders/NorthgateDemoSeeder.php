<?php

namespace Database\Seeders;

use App\Federation\Actions\StartApplication;
use App\Federation\Actions\TransitionApplication;
use App\Federation\Enums\ApplicationRole;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Enums\DocumentType;
use App\Federation\Models\ApplicationDocument;
use App\Federation\Models\Federation;
use App\Federation\Models\MemberOrganization;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Models\RegistrationWindow;
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

        // Registration is open for the current season at every organization,
        // and was open last season at the youth association.
        $windows = [];
        foreach ([$youth, $adult, $referees] as $organization) {
            $windows[$organization->code] = $organization->registrationWindows()->create([
                'season_id' => $season->getKey(),
                'opens_at' => now()->subWeeks(2),
                'closes_at' => now()->addMonths(2),
                'roles' => ApplicationRole::values(),
            ]);
        }
        $previousWindow = $youth->registrationWindows()->create([
            'season_id' => $previousSeason->getKey(),
            'opens_at' => now()->subYear()->subWeeks(2),
            'closes_at' => now()->subYear()->addMonths(2),
            'roles' => ApplicationRole::values(),
        ]);

        $federationAdmin = $this->user('Federation Admin', 'federation-admin@northgate.example', 'mock|federation-admin');
        $federation->administrators()->attach($federationAdmin);

        $youth->administrators()->attach($this->user('NYSA Admin', 'nysa-admin@northgate.example', 'mock|nysa-admin'));
        $adult->administrators()->attach($this->user('NASL Admin', 'nasl-admin@northgate.example', 'mock|nasl-admin'));
        $referees->administrators()->attach($this->user('NRA Admin', 'nra-admin@northgate.example', 'mock|nra-admin'));

        $alex = $this->user('Alex Participant', 'alex.participant@northgate.example', 'mock|alex');
        $sam = $this->user('Sam Coach', 'sam.coach@northgate.example', 'mock|sam');
        $riley = $this->user('Riley Referee', 'riley.referee@northgate.example', 'mock|riley');
        $jordan = $this->user('Jordan Newcomer', 'jordan.newcomer@northgate.example', 'mock|jordan');

        $start = app(StartApplication::class);
        $move = app(TransitionApplication::class);
        $youthAdmin = $youth->administrators()->first();
        $refereeAdmin = $referees->administrators()->first();

        // Submitted, waiting in the queue.
        $submitted = $this->complete($start->execute($alex, $windows['NYSA'], ApplicationRole::PARTICIPANT, 'seed-alex-participant'), '1998-04-12');
        $move->execute($submitted, ApplicationStatus::SUBMITTED, $alex);

        // Picked up by a reviewer, then sent back for information.
        $needsInfo = $this->complete($start->execute($sam, $windows['NYSA'], ApplicationRole::COACH, 'seed-sam-coach'), '1985-11-03');
        $move->execute($needsInfo, ApplicationStatus::SUBMITTED, $sam);
        $move->execute($needsInfo, ApplicationStatus::UNDER_REVIEW, $youthAdmin);
        $move->execute($needsInfo, ApplicationStatus::NEEDS_INFORMATION, $youthAdmin, 'Coaching licence number is missing.');

        // Approved by the referee association.
        $approved = $this->complete($start->execute($riley, $windows['NRA'], ApplicationRole::REFEREE, 'seed-riley-referee'), '1990-06-21');
        $move->execute($approved, ApplicationStatus::SUBMITTED, $riley);
        $move->execute($approved, ApplicationStatus::UNDER_REVIEW, $refereeAdmin);
        $move->execute($approved, ApplicationStatus::APPROVED, $refereeAdmin);

        // Rejected last season by the federation administrator, so a fresh application is possible.
        $rejected = $this->completeBackdated($previousWindow, $riley, ApplicationRole::COACH, 'seed-riley-coach-2025', '1990-06-21');
        $move->execute($rejected, ApplicationStatus::SUBMITTED, $riley);
        $move->execute($rejected, ApplicationStatus::UNDER_REVIEW, $federationAdmin);
        $move->execute($rejected, ApplicationStatus::REJECTED, $federationAdmin, 'Background check not completed before the deadline.');

        // Still a draft, nothing attached yet.
        $start->execute($jordan, $windows['NASL'], ApplicationRole::PARTICIPANT, 'seed-jordan-participant');
    }

    /**
     * Date of birth plus placeholder metadata for every required document.
     * Synthetic: file names and checksums are invented, no bytes exist.
     */
    private function complete(RegistrationApplication $application, string $dateOfBirth): RegistrationApplication
    {
        $application->forceFill(['date_of_birth' => $dateOfBirth])->save();

        foreach (DocumentType::requiredFor($application->role) as $type) {
            ApplicationDocument::query()->create([
                'registration_application_id' => $application->getKey(),
                'document_type' => $type,
                'file_name' => str_replace('_', '-', $type->value).'.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 120_000,
                'checksum_sha256' => hash('sha256', $application->getKey().$type->value),
                'review_status' => 'pending',
            ]);
        }

        return $application;
    }

    /**
     * Last season's window is closed now; the application is created directly
     * (not through StartApplication) to represent history.
     */
    private function completeBackdated(RegistrationWindow $window, User $applicant, ApplicationRole $role, string $key, string $dateOfBirth): RegistrationApplication
    {
        $application = RegistrationApplication::query()->create([
            'member_organization_id' => $window->member_organization_id,
            'season_id' => $window->season_id,
            'registration_window_id' => $window->getKey(),
            'applicant_user_id' => $applicant->getKey(),
            'role' => $role,
            'idempotency_key' => $key,
        ]);

        return $this->complete($application, $dateOfBirth);
    }

    private function assignClubs(MemberOrganization $youth, MemberOrganization $adult): void
    {
        $clubs = Club::query()->orderBy('id')->get();

        // The last club stays unassigned on purpose: upstream clubs keep working without an organization.
        $clubs->slice(0, 3)->each(fn (Club $club) => $club->memberOrganization()->associate($youth)->save());
        $clubs->slice(3, 2)->each(fn (Club $club) => $club->memberOrganization()->associate($adult)->save());
    }

    /**
     * Personas carry the subject the mock identity provider issues for them
     * (docker/oidc/config.json), so the credentials contract can be exercised
     * against seeded data before anyone signs in.
     */
    private function user(string $name, string $email, ?string $subject = null): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
        ]);

        if ($subject !== null) {
            $user->forceFill([
                'oidc_issuer' => config('oidc.issuer'),
                'oidc_subject' => $subject,
            ])->save();
        }

        return $user;
    }
}
