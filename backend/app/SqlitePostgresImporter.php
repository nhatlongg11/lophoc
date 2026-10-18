<?php

namespace App;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

class SqlitePostgresImporter
{
    /** @return array<string, array{rows: int, sha256: string}> */
    public function import(string $sourcePath, string $connectionName = 'pgsql'): array
    {
        $destination = DB::connection($connectionName);
        if ($destination->getDriverName() !== 'pgsql') {
            throw new RuntimeException('The destination must be PostgreSQL.');
        }
        $sourcePath = realpath($sourcePath);
        if (! $sourcePath || ! is_file($sourcePath)) {
            throw new RuntimeException('The SQLite backup does not exist.');
        }
        $source = new PDO('sqlite:'.$sourcePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $source->exec('PRAGMA query_only = ON');
        $source->beginTransaction();
        try {
            if ($source->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $source->query('PRAGMA foreign_key_check')->fetch()) {
                throw new RuntimeException('SQLite integrity or foreign-key checks failed. No records were copied.');
            }
            $tables = $source->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            $schema = $destination->selectOne('SELECT current_schema() AS name')->name;
            $targetTables = $destination->getSchemaBuilder()->getTableListing($schema, false);
            sort($targetTables);
            if ($tables !== $targetTables || ! in_array('migrations', $tables, true)) {
                throw new RuntimeException('Source and destination table lists differ. Run the same migrations first.');
            }
            $sourceMigrations = $source->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
            if ($sourceMigrations !== $destination->table('migrations')->orderBy('migration')->pluck('migration')->all()) {
                throw new RuntimeException('Source and destination migration histories differ.');
            }

            return $destination->transaction(function () use ($source, $destination, $tables): array {
                $wrapped = array_map(fn ($table) => $this->identifier($table), $tables);
                $destination->statement('LOCK TABLE '.implode(', ', $wrapped).' IN ACCESS EXCLUSIVE MODE');
                $this->requireEmptyDestination($destination, $tables);
                $foreignKeys = $destination->select("SELECT conrelid::regclass::text AS table_name, conname, condeferrable FROM pg_constraint WHERE contype = 'f' AND connamespace = current_schema()::regnamespace");
                foreach ($foreignKeys as $key) {
                    if (! $key->condeferrable) {
                        $destination->statement('ALTER TABLE '.$this->identifier($key->table_name).' ALTER CONSTRAINT '.$this->identifier($key->conname).' DEFERRABLE INITIALLY IMMEDIATE');
                    }
                }
                $destination->statement('SET CONSTRAINTS ALL DEFERRED');
                $destination->table('migrations')->delete();
                if (in_array('organizations', $tables, true)) {
                    $destination->table('organizations')->delete();
                }
                $results = [];
                foreach ($tables as $table) {
                    $columns = $destination->getSchemaBuilder()->getColumns($table);
                    $sourceColumns = $source->query('PRAGMA table_info('.$this->identifier($table).')')->fetchAll(PDO::FETCH_ASSOC);
                    $names = array_column($sourceColumns, 'name');
                    $targetNames = array_column($columns, 'name');
                    sort($names);
                    sort($targetNames);
                    if ($names !== $targetNames) {
                        throw new RuntimeException('Column mismatch in '.$table.'. The import was rolled back.');
                    }
                    $rows = $source->query('SELECT * FROM '.$this->identifier($table));
                    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                        try {
                            $destination->table($table)->insert($row);
                        } catch (Throwable $exception) {
                            throw new RuntimeException('Cannot copy table '.$table.' (SQLSTATE '.$exception->getCode().'). Check data types and constraints. The import was rolled back.');
                        }
                    }
                    $primary = array_filter($sourceColumns, fn ($column) => $column['pk'] > 0);
                    usort($primary, fn ($a, $b) => $a['pk'] <=> $b['pk']);
                    $order = $primary ? implode(', ', array_map(fn ($column) => $this->identifier($column['name']), $primary)) : implode(', ', array_map($this->identifier(...), $names));
                    $sql = 'SELECT * FROM '.$this->identifier($table).' ORDER BY '.$order;
                    $expected = $this->fingerprint($source, $sql, $columns);
                    $actual = $this->fingerprint($destination->getPdo(), $sql, $columns);
                    if ($expected !== $actual) {
                        throw new RuntimeException('Record verification failed for '.$table.'. The import was rolled back.');
                    }
                    $results[$table] = $actual;
                }
                $destination->statement('SET CONSTRAINTS ALL IMMEDIATE');
                foreach ($foreignKeys as $key) {
                    if (! $key->condeferrable) {
                        $destination->statement('ALTER TABLE '.$this->identifier($key->table_name).' ALTER CONSTRAINT '.$this->identifier($key->conname).' NOT DEFERRABLE');
                    }
                }
                foreach ($tables as $table) {
                    if ($destination->getSchemaBuilder()->hasColumn($table, 'id')) {
                        $sequence = $destination->selectOne('SELECT pg_get_serial_sequence(?, ?) AS name', [$this->identifier($table), 'id'])->name;
                        if ($sequence) {
                            $maximum = $destination->table($table)->max('id');
                            $destination->select('SELECT setval(?::regclass, ?, ?)', [$sequence, max(1, (int) $maximum), $maximum !== null]);
                        }
                    }
                }

                return $results;
            });
        } finally {
            $source->rollBack();
        }
    }

    private function requireEmptyDestination(Connection $destination, array $tables): void
    {
        foreach ($tables as $table) {
            if (in_array($table, ['migrations', 'organizations'], true)) {
                continue;
            }
            if ($destination->table($table)->exists()) {
                throw new RuntimeException('Destination '.$table.' already contains records. Refusing to overwrite existing data.');
            }
        }
        if (in_array('organizations', $tables, true)) {
            $organizations = $destination->table('organizations')->get();
            if ($organizations->count() > 1 || ($organizations->isNotEmpty() && ($organizations[0]->id !== 1 || $organizations[0]->slug !== 'eduquest' || $organizations[0]->name !== 'Trung tâm EduQuest'))) {
                throw new RuntimeException('Destination contains an existing organization. Refusing to overwrite it.');
            }
        }
    }

    /** @return array{rows: int, sha256: string} */
    private function fingerprint(PDO $connection, string $sql, array $columns): array
    {
        $hash = hash_init('sha256');
        $rows = $connection->query($sql);
        $count = 0;
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $canonical = [];
            foreach ($columns as $column) {
                $canonical[$column['name']] = $this->canonicalValue($row[$column['name']], $column['type_name']);
            }
            ksort($canonical);
            hash_update($hash, json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
            $count++;
        }

        return ['rows' => $count, 'sha256' => hash_final($hash)];
    }

    private function canonicalValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($type === 'bool') {
            return in_array($value, [true, 1, '1', 't', 'true'], true) ? 'true' : 'false';
        }
        if (in_array($type, ['json', 'jsonb'], true)) {
            return json_encode(json_decode((string) $value, flags: JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        }
        if (in_array($type, ['timestamp', 'timestamptz', 'date'], true)) {
            return (new \DateTimeImmutable((string) $value))->format('Y-m-d H:i:s.u');
        }
        if (in_array($type, ['int2', 'int4', 'int8', 'numeric', 'float4', 'float8'], true)) {
            $number = (string) $value;

            return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
        }

        return (string) $value;
    }

    private function identifier(string $name): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/D', $name)) {
            throw new RuntimeException('Unsupported database identifier.');
        }

        return '"'.$name.'"';
    }
}
