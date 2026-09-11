<?php

declare(strict_types=1);

namespace Ramon\PointSystem\Tests\integration;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Builder;
use PHPUnit\Framework\TestCase;
use Ramon\PointSystem\Repository\PointsRepository;
use Ramon\PointSystem\Support\PointReason;

/**
 * Shared DB harness for the integration suite.
 *
 * Driver selection:
 *   - default: SQLite in-memory (needs pdo_sqlite)
 *   - PS_DB_DRIVER=mysql: a real MySQL scratch database (default name
 *     `ps_ext_test`). All `point_system_*` tables are dropped before each
 *     test and the real migrations are replayed, so the schema under test is
 *     production-identical (indexes, unique keys, InnoDB behaviour).
 *
 * Scratch DB credentials come from PS_DB_HOST / PS_DB_PORT / PS_DB_NAME /
 * PS_DB_USER / PS_DB_PASS.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Capsule $capsule;

    public static function setUpBeforeClass(): void
    {
        if (getenv('PS_DB_DRIVER') !== 'mysql' && ! extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite extension is required (or set PS_DB_DRIVER=mysql)');
        }
    }

    protected function setUp(): void
    {
        $this->capsule = new Capsule();

        if ($this->driver() === 'mysql') {
            $this->capsule->addConnection([
                'driver' => 'mysql',
                'host' => getenv('PS_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('PS_DB_PORT') ?: 3306),
                'database' => getenv('PS_DB_NAME') ?: 'ps_ext_test',
                'username' => getenv('PS_DB_USER') ?: 'root',
                'password' => getenv('PS_DB_PASS') ?: '123456',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'engine' => 'InnoDB',
            ]);
        } else {
            $this->capsule->addConnection([
                'driver' => 'sqlite',
                'database' => ':memory:',
                'foreign_key_constraints' => false,
            ]);
        }

        $this->capsule->bootEloquent();
        $this->capsule->setAsGlobal();

        if ($this->driver() === 'mysql') {
            // The harness does not create the `users` table; the migrations
            // declare FKs to it. Disabling enforcement lets the focused tests
            // exercise the point-system tables in isolation.
            $this->capsule->getConnection()->statement('SET FOREIGN_KEY_CHECKS=0');
            $this->dropPointSystemTables();
        }

        $this->createCoreStubs();
        $this->runMigrations();
    }

    protected function driver(): string
    {
        return getenv('PS_DB_DRIVER') === 'mysql' ? 'mysql' : 'sqlite';
    }

    protected function connection(): \Illuminate\Database\ConnectionInterface
    {
        return $this->capsule->getConnection();
    }

    protected function schema(): Builder
    {
        return $this->capsule->getConnection()->getSchemaBuilder();
    }

    private function dropPointSystemTables(): void
    {
        $schema = $this->schema();
        $rows = $this->capsule->getConnection()->select(
            'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()'
        );
        foreach ($rows as $row) {
            $name = is_array($row) ? ($row['name'] ?? '') : ($row->name ?? '');
            if (is_string($name) && str_starts_with($name, 'point_system_')) {
                $schema->drop($name);
            }
        }
    }

    protected function runMigrations(): void
    {
        $schema = $this->schema();

        $files = glob(__DIR__ . '/../../migrations/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $migration = require $file;
            if (is_array($migration) && isset($migration['up'])) {
                ($migration['up'])($schema);
            }
        }
    }

    /**
     * Minimal stand-ins for the core tables the permission-seeding migrations
     * write to. Real FK targets (users, groups) are intentionally absent —
     * FK enforcement is off, and the tests never read them.
     */
    private function createCoreStubs(): void
    {
        $schema = $this->schema();

        if (! $schema->hasTable('group_permission')) {
            $schema->create('group_permission', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('group_id');
                $t->string('permission', 200);
                $t->unique(['group_id', 'permission']);
            });
        }

        if (! $schema->hasTable('groups')) {
            $schema->create('groups', function (\Illuminate\Database\Schema\Blueprint $t) {
                $t->increments('id');
                $t->string('name_singular', 100)->nullable();
                $t->string('name_plural', 100)->nullable();
                $t->string('color', 20)->nullable();
                $t->string('icon', 100)->nullable();
                $t->boolean('is_hidden')->default(false);
            });
            $this->connection()->table('groups')->insert([
                ['id' => 3, 'name_singular' => null, 'name_plural' => 'Members', 'color' => null, 'icon' => null, 'is_hidden' => false],
                ['id' => 4, 'name_singular' => null, 'name_plural' => 'Guests', 'color' => null, 'icon' => null, 'is_hidden' => true],
            ]);
        }
    }

    protected function makePointsRepository(
        ?SettingsRepositoryInterface $settings = null,
        ?Dispatcher $events = null,
    ): PointsRepository {
        return new PointsRepository(
            $settings ?? $this->defaultSettings(),
            $events ?? $this->silentDispatcher(),
            $this->connection(),
            PointReason::builtIn(),
        );
    }

    protected function defaultSettings(): SettingsRepositoryInterface
    {
        return new class implements SettingsRepositoryInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'point-system.enabled' => true,
                    'point-system.daily_earn_cap' => 0,
                    'point-system.auto_group_enabled' => false,
                    default => $default,
                };
            }
            public function set(string $key, $value): void {}
            public function delete(string $key): void {}
            public function all(): array { return []; }
        };
    }

    protected function silentDispatcher(): Dispatcher
    {
        return new class implements Dispatcher {
            public function listen($events, $listener = null) {}
            public function subscribe($subscriber) {}
            public function until($event, $payload = []) {}
            public function dispatch($event, $payload = [], $halt = false) { return $event; }
            public function push($event, $payload = []) {}
            public function flush($event) {}
            public function forget($event) {}
            public function forgetPushed() {}
            public function hasListeners($event): bool { return false; }
        };
    }

    protected function spyDispatcher(array &$records): Dispatcher
    {
        return new class($records) implements Dispatcher {
            /** @param array<int, object> $records */
            public function __construct(protected array &$records) {}
            public function listen($events, $listener = null) {}
            public function subscribe($subscriber) {}
            public function until($event, $payload = []) {}
            public function dispatch($event, $payload = [], $halt = false)
            {
                $this->records[] = $event;
                return $event;
            }
            public function push($event, $payload = []) {}
            public function flush($event) {}
            public function forget($event) {}
            public function forgetPushed() {}
            public function hasListeners($event): bool { return false; }
        };
    }
}
