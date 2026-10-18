<?php

namespace App\Console\Commands;

use App\SqlitePostgresImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('database:import-sqlite {source : Absolute path to a backed-up SQLite database} {--connection=pgsql : Destination PostgreSQL connection} {--json : Output the verified row counts and hashes as JSON}')]
#[Description('Copy and verify SQLite records into an empty migrated PostgreSQL database without modifying the source')]
class ImportSqliteDatabase extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SqlitePostgresImporter $importer): int
    {
        try {
            $results = $importer->import($this->argument('source'), $this->option('connection'));
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode(['committed' => true, 'tables' => $results], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Table', 'Rows', 'SHA-256 verified'], array_map(fn ($table, $result) => [$table, $result['rows'], $result['sha256']], array_keys($results), $results));
            $this->info('Import committed. All rows verified, foreign keys enforced, ID sequences repaired. SQLite source was not changed.');
        }

        return self::SUCCESS;
    }
}
