<?php

declare(strict_types=1);

namespace Duon\Quma\Tests;

use Duon\Quma\Commands\Migrations;
use Duon\Quma\Connection;
use Duon\Quma\Database;
use ReflectionMethod;

/**
 * @internal
 */
class MigrationsCommandTest extends TestCase
{
	public function testRunMigrationsHandlesUnreadableFile(): void
	{
		$_SERVER['argv'] = ['run'];
		$conn = $this->connection();
		$db = new Database($conn);
		$db->execute('CREATE TABLE migrations (migration text, applied text)')->run();

		$missing = sys_get_temp_dir() . '/missing-migration.sql';
		@unlink($missing);

		$command = new Migrations($conn);
		$method = new ReflectionMethod(Migrations::class, 'runMigrations');

		$handler = set_error_handler(static fn(): bool => true);
		try {
			ob_start();
			$result = $method->invoke($command, [$missing], $db, $conn, false, true);
			$output = ob_get_contents();
			ob_end_clean();
		} finally {
			if ($handler !== null) {
				restore_error_handler();
			}
		}

		$this->assertSame(1, $result);
		$this->assertStringContainsString('Could not read migration file', $output);
	}

	public function testFinishHandlesNonTransactionalDrivers(): void
	{
		$_SERVER['argv'] = ['run'];
		$mysqlConn = new Connection(
			'mysql:host=localhost;dbname=quma;user=quma;password=quma',
			$this->getSqlDirs(),
			TestCase::root() . 'migrations',
		);
		$command = new Migrations($mysqlConn);
		$db = new Database($mysqlConn);
		$method = new ReflectionMethod(Migrations::class, 'finish');

		ob_start();
		$resultError = $method->invoke($command, $db, 'error', true, 2);
		$outputError = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $resultError);
		$this->assertStringContainsString('2 migrations applied until the error occured', $outputError);

		ob_start();
		$resultSuccess = $method->invoke($command, $db, 'success', true, 2);
		$outputSuccess = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $resultSuccess);
		$this->assertStringContainsString('2 migrations successfully applied', $outputSuccess);

		ob_start();
		$resultEmpty = $method->invoke($command, $db, 'success', true, 0);
		$outputEmpty = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $resultEmpty);
		$this->assertStringContainsString('No migrations applied', $outputEmpty);
	}

	public function testSupportsTransactionsForPgsql(): void
	{
		$_SERVER['argv'] = ['run'];
		$pgsqlConn = new Connection(
			'pgsql:host=localhost;dbname=quma;user=quma;password=quma',
			$this->getSqlDirs(),
			TestCase::root() . 'migrations',
		);
		$command = new Migrations($pgsqlConn);
		$method = new ReflectionMethod(Migrations::class, 'supportsTransactions');

		$this->assertTrue($method->invoke($command));
	}
}
