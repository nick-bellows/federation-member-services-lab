<?php

namespace Tests\Unit\Federation;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MariaDB and MySQL reject identifiers longer than 64 characters; SQLite, which
 * runs the test suite, accepts anything. A generated index name on the
 * organization_administrators table crossed that limit and broke the MariaDB
 * migration while every SQLite test passed. This test makes the limit visible
 * on SQLite for every table, index and foreign key in the schema.
 */
class SchemaIdentifierLengthTest extends TestCase
{
    private const MYSQL_IDENTIFIER_LIMIT = 64;

    public function test_every_table_index_and_foreign_key_name_fits_mysql_limits(): void
    {
        $tooLong = [];

        foreach (Schema::getTableListing() as $table) {
            if (strlen($table) > self::MYSQL_IDENTIFIER_LIMIT) {
                $tooLong[] = "table {$table}";
            }

            foreach (Schema::getIndexes($table) as $index) {
                if (strlen($index['name']) > self::MYSQL_IDENTIFIER_LIMIT) {
                    $tooLong[] = "index {$table}.{$index['name']} (".strlen($index['name']).')';
                }
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if ($foreignKey['name'] !== null && strlen($foreignKey['name']) > self::MYSQL_IDENTIFIER_LIMIT) {
                    $tooLong[] = "foreign key {$table}.{$foreignKey['name']} (".strlen($foreignKey['name']).')';
                }
            }
        }

        $this->assertSame([], $tooLong, 'Identifiers longer than 64 characters: '.implode(', ', $tooLong));
    }
}
