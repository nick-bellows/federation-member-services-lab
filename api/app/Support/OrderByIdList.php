<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Order rows by an explicit list of ids, on every engine. MySQL's FIELD() is
 * the only dialect that has this built in; a CASE expression with bindings
 * is the portable form (docs/DATABASE_COMPATIBILITY.md).
 */
final class OrderByIdList
{
    /**
     * @param  list<int|string>  $ids
     */
    public static function apply(Builder $query, array $ids, string $column = 'id'): Builder
    {
        $ids = array_values(array_map('intval', $ids));

        if ($ids === []) {
            return $query;
        }

        $qualified = $query->getModel()->qualifyColumn($column);
        $cases = implode(' ', array_map(static fn (int $position) => "WHEN ? THEN {$position}", array_keys($ids)));

        return $query->orderByRaw("CASE {$qualified} {$cases} ELSE ".count($ids).' END', $ids);
    }
}
