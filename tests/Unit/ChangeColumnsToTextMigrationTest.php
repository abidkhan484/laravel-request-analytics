<?php

declare(strict_types=1);

namespace MeShaon\RequestAnalytics\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MeShaon\RequestAnalytics\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class ChangeColumnsToTextMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_runs_without_error_and_preserves_the_columns_on_sqlite(): void
    {
        $migration = require __DIR__.'/../../database/migrations/change_referrer_page_title_path_columns_to_text.php.stub';

        $migration->up();
        $migration->down();

        $columns = Schema::getColumnListing('request_analytics');

        $this->assertContains('path', $columns);
        $this->assertContains('page_title', $columns);
        $this->assertContains('referrer', $columns);
    }

    #[Test]
    public function it_runs_the_expected_alter_statement_on_mysql(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('statement')
            ->once()
            ->with('ALTER TABLE `request_analytics` MODIFY `path` TEXT NOT NULL, MODIFY `page_title` TEXT NULL, MODIFY `referrer` TEXT NULL')
            ->andReturn(true);

        DB::shouldReceive('connection')->once()->with(null)->andReturn($connection);

        $migration = require __DIR__.'/../../database/migrations/change_referrer_page_title_path_columns_to_text.php.stub';
        $migration->up();
    }

    #[Test]
    public function it_runs_the_expected_alter_statement_on_postgres(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('statement')
            ->once()
            ->with('ALTER TABLE "request_analytics" ALTER COLUMN "path" TYPE TEXT, ALTER COLUMN "page_title" TYPE TEXT, ALTER COLUMN "referrer" TYPE TEXT')
            ->andReturn(true);

        DB::shouldReceive('connection')->once()->with(null)->andReturn($connection);

        $migration = require __DIR__.'/../../database/migrations/change_referrer_page_title_path_columns_to_text.php.stub';
        $migration->up();
    }
}
