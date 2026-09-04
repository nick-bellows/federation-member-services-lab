<?php

namespace Tests\Feature\Performance;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The regression guard for the B6 finding: every tenant-scoped upstream table
 * keeps an index on club_id on every engine in the matrix. A refactor that
 * drops one fails here before it fails in a load run.
 */
class ClubIdIndexTest extends TestCase
{
    public function test_every_tenant_scoped_upstream_table_has_a_club_id_index(): void
    {
        $expected = [
            'members' => ['club_id', 'membership_id'],
            'memberships' => ['club_id'],
            'membership_types' => ['club_id'],
            'divisions' => ['club_id'],
        ];

        foreach ($expected as $table => $columns) {
            $indexes = collect(Schema::getIndexes($table));

            foreach ($columns as $column) {
                $indexed = $indexes->contains(fn (array $index) => $index['columns'] === [$column]);

                $this->assertTrue($indexed, "{$table}.{$column} has no index");
            }
        }
    }
}
