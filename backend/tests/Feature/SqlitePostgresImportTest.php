<?php

namespace Tests\Feature;

use App\SqlitePostgresImporter;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\TestCase;

class SqlitePostgresImportTest extends TestCase
{
    public function test_refuses_to_import_into_sqlite(): void
    {
        config(['database.connections.import_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The destination must be PostgreSQL.');
        app(SqlitePostgresImporter::class)->import('/missing.sqlite', 'import_sqlite');
    }

    public function test_import_preserves_cyclic_relationships_json_booleans_scores_and_next_ids(): void
    {
        $this->withFixture(function (Connection $destination, string $source): void {
            $originalHash = hash_file('sha256', $source);
            $result = app(SqlitePostgresImporter::class)->import($source, 'import_target');
            $this->assertSame(1, $result['parents']['rows']);
            $parent = $destination->table('parents')->find(41);
            $this->assertSame('Học sinh Nguyễn', $parent->name);
            $this->assertTrue($parent->active);
            $this->assertSame('8.50', $parent->score);
            $this->assertSame(['answer' => 'Đúng', 'ids' => [7, 41]], json_decode($parent->content, true));
            $this->assertSame(7, $parent->child_id);
            $this->assertSame(41, $destination->table('children')->find(7)->parent_id);
            $this->assertSame(42, $destination->table('parents')->insertGetId(['name' => 'Next student', 'active' => false, 'score' => 0, 'content' => '{}']));
            $this->assertSame(0, (int) $destination->selectOne("SELECT count(*) AS total FROM pg_constraint WHERE connamespace = current_schema()::regnamespace AND contype = 'f' AND condeferrable")->total);
            $this->assertSame($originalHash, hash_file('sha256', $source));
        });
    }

    public function test_existing_destination_records_are_never_overwritten(): void
    {
        $this->withFixture(function (Connection $destination, string $source): void {
            $destination->table('parents')->insert(['id' => 99, 'name' => 'Keep this record', 'active' => true, 'score' => 10, 'content' => '{}']);
            try {
                app(SqlitePostgresImporter::class)->import($source, 'import_target');
                $this->fail('The populated destination was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Refusing to overwrite', $exception->getMessage());
            }
            $this->assertSame('Keep this record', $destination->table('parents')->find(99)->name);
            $this->assertSame(1, $destination->table('parents')->count());
            $this->assertSame(0, $destination->table('children')->count());
        });
    }

    public function test_invalid_source_json_rolls_back_all_copied_rows(): void
    {
        $this->withFixture(function (Connection $destination, string $source): void {
            (new PDO('sqlite:'.$source))->exec("UPDATE parents SET content = 'invalid-json'");
            try {
                app(SqlitePostgresImporter::class)->import($source, 'import_target');
                $this->fail('Invalid JSON was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('import was rolled back', $exception->getMessage());
            }
            $this->assertSame(0, $destination->table('parents')->count());
            $this->assertSame(0, $destination->table('children')->count());
            $this->assertSame('fixture', $destination->table('migrations')->value('migration'));
        });
    }

    private function withFixture(callable $test): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Run with the isolated PostgreSQL test database to verify cross-driver import.');
        }
        $schema = 'import_fixture_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA '.$schema);
        config(['database.connections.import_target' => [...config('database.connections.'.config('database.default')), 'search_path' => $schema]]);
        DB::purge('import_target');
        $destination = DB::connection('import_target');
        $source = tempnam(sys_get_temp_dir(), 'eduquest-import-');
        try {
            $builder = $destination->getSchemaBuilder();
            $builder->create('migrations', function (Blueprint $table): void {
                $table->id();
                $table->string('migration');
                $table->integer('batch');
            });
            $builder->create('parents', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->boolean('active');
                $table->decimal('score', 7, 2);
                $table->json('content');
                $table->unsignedBigInteger('child_id')->nullable();
            });
            $builder->create('children', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('parent_id')->constrained('parents');
            });
            $builder->table('parents', fn (Blueprint $table) => $table->foreign('child_id')->references('id')->on('children'));
            $destination->table('migrations')->insert(['id' => 1, 'migration' => 'fixture', 'batch' => 1]);
            $sqlite = new PDO('sqlite:'.$source);
            $sqlite->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
            $sqlite->exec('CREATE TABLE parents (id INTEGER PRIMARY KEY, name TEXT, active INTEGER, score NUMERIC, content TEXT, child_id INTEGER REFERENCES children(id))');
            $sqlite->exec('CREATE TABLE children (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES parents(id))');
            $sqlite->exec("INSERT INTO migrations VALUES (1, 'fixture', 1)");
            $statement = $sqlite->prepare('INSERT INTO parents VALUES (41, ?, 1, 8.5, ?, 7)');
            $statement->execute(['Học sinh Nguyễn', json_encode(['answer' => 'Đúng', 'ids' => [7, 41]], JSON_UNESCAPED_UNICODE)]);
            $sqlite->exec('INSERT INTO children VALUES (7, 41)');
            $statement = null;
            $sqlite = null;
            $test($destination, $source);
        } finally {
            DB::purge('import_target');
            DB::statement('DROP SCHEMA '.$schema.' CASCADE');
            unlink($source);
        }
    }
}
