<?php

/**
 * Migration testing is hard.
 *
 * Some of these tests depend on each other and the order
 * in which they are executed. Reorganize with care.
 *
 * Running a single test with '->only()' might be impossible.
 */

declare(strict_types=1);

namespace Duon\Quma\Tests;

use Duon\Cli\Runner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;

/**
 * @internal
 */
class CreateMigrationsTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		// Remove remnants of previous runs
		$migrationsDir = TestCase::root() . '/migrations/';
		array_map('unlink', glob("{$migrationsDir}*test-migration*"));

		TestCase::cleanupTestDbs();
	}

	#[DataProvider('connectionProvider')]
	public function testCreateMigrationsTableSuccess(string $dsn): void
	{
		$_SERVER['argv'] = ['run', 'create-migrations-table'];

		ob_start();
		$result = (new Runner($this->commands(dsn: $dsn)))->run();
		ob_end_clean();

		$this->assertSame(0, $result);
	}

	#[DataProvider('connectionProvider')]
	#[Depends('testCreateMigrationsTableSuccess')]
	public function testCreateMigrationsTableAlreadyExists(string $dsn): void
	{
		$_SERVER['argv'] = ['run', 'create-migrations-table'];

		ob_start();
		$result = (new Runner($this->commands(dsn: $dsn)))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);

		if (str_starts_with($dsn, 'pgsql')) {
			$this->assertStringContainsString("Table 'public.migrations' already exists", $content);
		} else {
			$this->assertStringContainsString("Table 'migrations' already exists", $content);
		}
	}

	public function testCreateMigrationsTableAlreadyExistsConnectionAsArg(): void
	{
		$_SERVER['argv'] = ['run', 'create-migrations-table', '--conn', 'first'];

		ob_start();
		$result = (new Runner($this->commands(
			multipleConnections: true,
			firstMultipleConnectionsKey: 'first',
		)))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString("Table 'migrations' already exists", $content);
	}

	public function testCreateMigrationsTableAlreadyExistsMulticonnectionWithDefault(): void
	{
		$_SERVER['argv'] = ['run', 'create-migrations-table'];

		ob_start();
		$result = (new Runner($this->commands(
			multipleConnections: true,
			firstMultipleConnectionsKey: 'default',
		)))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString("Table 'migrations' already exists", $content);
	}

	public function testCreateMigrationsTableAlternateConnection(): void
	{
		$_SERVER['argv'] = ['run', 'create-migrations-table', '--conn', 'second'];

		ob_start();
		$result = (new Runner($this->commands(multipleConnections: true)))->run();
		ob_end_clean();

		$this->assertSame(0, $result);
	}

	#[Depends('testCreateMigrationsTableAlternateConnection')]
	public function testCreateMigrationsTableAlreadyExistsAlternateConnection(): void
	{
		$_SERVER['argv'] = ['run', 'create-migrations-table', '--conn', 'second'];

		ob_start();
		$result = (new Runner($this->commands(multipleConnections: true)))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString("Table 'migrations' already exists", $content);
	}

	public static function connectionProvider(): array
	{
		return array_map(fn($dsn) => [$dsn], TestCase::getAvailableDsns());
	}
}
