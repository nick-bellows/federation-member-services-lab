<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synthetic volume for the B6 measurements (docs/PERFORMANCE.md): clubs with
 * many members, memberships, membership types and divisions, inserted in
 * chunks straight through the query builder. Every name is generated; the
 * seed is idempotent on its marker club and never touches existing rows.
 *
 *   php artisan db:seed --class=PerformanceSeeder            # 20 clubs x 1500 members
 *   PERF_CLUBS=40 PERF_MEMBERS_PER_CLUB=2000 php artisan db:seed --class=PerformanceSeeder
 */
class PerformanceSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('clubs')->where('slug', 'perf-club-1')->exists()) {
            $this->command?->info('Performance seed already present (perf-club-1); nothing to do.');

            return;
        }

        $clubs = max(1, (int) config('performance.seed.clubs', 20));
        $membersPerClub = max(1, (int) config('performance.seed.members_per_club', 1500));
        $now = now();

        for ($c = 1; $c <= $clubs; $c++) {
            $clubId = DB::table('clubs')->insertGetId([
                'slug' => "perf-club-{$c}",
                'title' => "Performance Club {$c}",
                'address' => "{$c} Synthetic Street",
                'zip_code' => str_pad((string) (10000 + $c), 5, '0', STR_PAD_LEFT),
                'city' => 'Northgate',
                'country' => 'DE',
                'email' => "perf-club-{$c}@northgate.example",
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $typeIds = [];
            for ($t = 1; $t <= 3; $t++) {
                $typeIds[] = DB::table('membership_types')->insertGetId([
                    'club_id' => $clubId,
                    'title' => json_encode(['de' => "Typ {$t}", 'en' => "Type {$t}"]),
                    'description' => json_encode(['de' => 'Synthetisch', 'en' => 'Synthetic']),
                    'monthly_fee' => 1000 * $t,
                    'minimum_number_of_months' => 12,
                    'maximum_number_of_members' => 6,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            for ($d = 1; $d <= 5; $d++) {
                DB::table('divisions')->insert([
                    'club_id' => $clubId,
                    'title' => json_encode(['de' => "Abteilung {$d}", 'en' => "Division {$d}"]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $membershipRows = [];
            $membershipCount = (int) ceil($membersPerClub / 3);
            for ($m = 1; $m <= $membershipCount; $m++) {
                $membershipRows[] = [
                    'club_id' => $clubId,
                    'membership_type_id' => $typeIds[$m % 3],
                    'bank_iban' => 'DE00'.str_pad((string) (($c * 100000) + $m), 18, '0', STR_PAD_LEFT),
                    'bank_account_holder' => "Holder {$c}-{$m}",
                    'started_at' => $now->copy()->subDays($m % 365),
                    'status' => ['active', 'applied', 'inactive'][$m % 3],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($membershipRows, 500) as $chunk) {
                DB::table('memberships')->insert($chunk);
            }
            $membershipIds = DB::table('memberships')->where('club_id', $clubId)->orderBy('id')->pluck('id')->all();

            $memberRows = [];
            for ($m = 1; $m <= $membersPerClub; $m++) {
                $memberRows[] = [
                    'club_id' => $clubId,
                    'membership_id' => $membershipIds[($m - 1) % count($membershipIds)],
                    'first_name' => 'Member',
                    'last_name' => Str::upper(Str::random(6)),
                    'birthday' => $now->copy()->subYears(18 + ($m % 40))->toDateString(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($memberRows, 500) as $chunk) {
                DB::table('members')->insert($chunk);
            }

            $this->command?->line(sprintf('perf-club-%d: %d memberships, %d members', $c, count($membershipIds), $membersPerClub));
        }
    }
}
