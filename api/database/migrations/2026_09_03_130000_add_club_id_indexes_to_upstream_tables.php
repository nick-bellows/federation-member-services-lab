<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every tenant-scoped query filters on club_id (ClubScope), and the original
 * 2023 tables declared the column without an index (M0 baseline, confirmed
 * live). Measured before and after in docs/PERFORMANCE.md (ADR-0013).
 */
return new class extends Migration
{
    /** table => columns that every scoped query or per-row lookup filters on */
    private const INDEXES = [
        'members' => ['club_id', 'membership_id'],
        'memberships' => ['club_id'],
        'membership_types' => ['club_id'],
        'divisions' => ['club_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->hasIndex($table, "{$table}_{$column}_index")) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($column) {
                    $blueprint->index($column);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                if (! $this->hasIndex($table, "{$table}_{$column}_index")) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($column) {
                    $blueprint->dropIndex([$column]);
                });
            }
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $name) {
                return true;
            }
        }

        return false;
    }
};
