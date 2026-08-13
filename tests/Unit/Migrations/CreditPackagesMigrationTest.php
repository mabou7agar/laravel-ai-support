<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Migrations;

use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Tests\TestCase;

final class CreditPackagesMigrationTest extends TestCase
{
    public function test_existing_application_owned_credit_schema_is_accepted(): void
    {
        self::assertTrue(Schema::hasTable('credit_packages'));
        self::assertTrue(Schema::hasTable('credit_transactions'));

        $migration = require dirname(__DIR__, 3)
            . '/database/migrations/2024_01_01_000006_create_credit_packages_table.php';

        $migration->up();

        self::assertTrue(Schema::hasTable('credit_packages'));
        self::assertTrue(Schema::hasTable('credit_transactions'));
    }
}
