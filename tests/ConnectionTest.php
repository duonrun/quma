<?php

declare(strict_types=1);

namespace Duon\Quma\Tests;

use Duon\Quma\Connection;
use RuntimeException;
use ValueError;

/**
 * @internal
 */
class ConnectionTest extends TestCase
{
	public function testInitialization(): void
	{
		$dsn = $this->getDsn();
		$sql = $this->getSqlDirs();
		$conn = new Connection($dsn, $sql);

		$this->assertSame($dsn, $conn->dsn);
		$this->assertSame(realpath($sql), realpath($conn->sql()[0]));
		$this->assertFalse($conn->print());
		$this->assertTrue($conn->print(true));
	}

	public function testDriverSpecificDir(): void
	{
		$conn = new Connection($this->getDsn(), [
			'all' => [
				TestCase::root() . 'sql/default',
				TestCase::root() . 'sql/more',
			],
			'sqlite' => TestCase::root() . 'sql/additional',
			'ignored' => TestCase::root() . 'sql/ignored',
		]);

		$sql = $conn->sql();
		$this->assertCount(3, $sql);
		// Driver specific must come first
		$this->assertStringEndsWith('/additional', $sql[0]);
		$this->assertStringEndsWith('/default', $sql[1]);
		$this->assertStringEndsWith('/more', $sql[2]);
	}

	public function testAddSqlDirsLater(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		);

		$conn->addSqlDirs([
			'sqlite' => TestCase::root() . 'sql/additional',
			'ignored' => TestCase::root() . 'sql/ignored',
		]);

		$sql = $conn->sql();
		$this->assertCount(2, $sql);
		// Driver specific must come first
		$this->assertStringEndsWith('/additional', $sql[0]);
		$this->assertStringEndsWith('/default', $sql[1]);
	}

	public function testMixedDirsFormat(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			[
				[
					'all' => TestCase::root() . 'sql/default',
					'sqlite' => TestCase::root() . 'sql/additional',
					'ignored' => TestCase::root() . 'sql/ignored',
				],
				TestCase::root() . 'sql/additional/members',
			],
		);

		$sql = $conn->sql();
		$this->assertCount(3, $sql);
		$this->assertStringEndsWith('/members', $sql[0]);
		$this->assertStringEndsWith('/additional', $sql[1]);
		$this->assertStringEndsWith('/default', $sql[2]);
	}

	public function testNestedSqlDirsList(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			[
				[
					TestCase::root() . 'sql/default',
					TestCase::root() . 'sql/more',
				],
			],
		);

		$sql = $conn->sql();
		$this->assertCount(2, $sql);
		$this->assertStringEndsWith('/more', $sql[0]);
		$this->assertStringEndsWith('/default', $sql[1]);
	}

	public function testDriverSpecificArrayDirs(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			[
				'sqlite' => [
					TestCase::root() . 'sql/additional',
					TestCase::root() . 'sql/default',
				],
				'all' => [
					TestCase::root() . 'sql/more',
				],
			],
		);

		$sql = $conn->sql();
		$this->assertCount(3, $sql);
		$this->assertStringEndsWith('/additional', $sql[0]);
		$this->assertStringEndsWith('/default', $sql[1]);
		$this->assertStringEndsWith('/more', $sql[2]);
	}

	public function testMigrationDirectories(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
			[TestCase::root() . 'migrations', TestCase::root() . 'sql/additional'],
		);
		$migrations = $conn->migrations();

		$this->assertCount(2, $migrations);
		$this->assertStringEndsWith('/additional', $migrations[0]);
		$this->assertStringEndsWith('/migrations', $migrations[1]);
	}

	public function testNamespacedMigrationDirectories(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
			[
				'default' => [TestCase::root() . 'migrations', TestCase::root() . 'sql/default'],
				'install' => TestCase::root() . 'sql/additional',
			],
		);
		$migrations = $conn->migrations();

		$this->assertCount(2, $migrations);
		$this->assertStringEndsWith('/migrations', $migrations['default'][0]);
		$this->assertStringEndsWith('/default', $migrations['default'][1]);
		$this->assertStringEndsWith('/additional', $migrations['install']);
	}

	public function testNamespacedMigrationDirectoriesWithEmptyNamespace(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
			[
				'' => TestCase::root() . 'migrations',
				'valid' => [
					[
						'all' => TestCase::root() . 'sql/default',
					],
				],
			],
		);
		$migrations = $conn->migrations();

		$this->assertCount(1, $migrations);
		$this->assertArrayHasKey('valid', $migrations);
		$this->assertStringEndsWith('/default', $migrations['valid'][0]);
	}

	public function testNamespacedMigrationDirectoriesWithNestedList(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
			[
				'valid' => [
					[
						TestCase::root() . 'migrations',
						TestCase::root() . 'sql/default',
					],
				],
			],
		);
		$migrations = $conn->migrations();

		$this->assertCount(1, $migrations);
		$this->assertArrayHasKey('valid', $migrations);
		$this->assertStringEndsWith('/migrations', $migrations['valid'][0]);
		$this->assertStringEndsWith('/default', $migrations['valid'][1]);
	}

	public function testAddMigrationDirectoriesLater(): void
	{
		$conn = new Connection(
			$this->getDsn(),
			TestCase::root() . 'sql/default',
		);
		$conn->addMigrationDir(TestCase::root() . 'migrations');
		$conn->addMigrationDir(TestCase::root() . 'sql/additional');
		$migrations = $conn->migrations();

		$this->assertCount(2, $migrations);
		$this->assertStringEndsWith('/additional', $migrations[0]);
		$this->assertStringEndsWith('/migrations', $migrations[1]);
	}

	public function testUnsupportedDsn(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('driver not supported');

		new Connection('notsupported:host=localhost;dbname=chuck', $this->getSqlDirs());
	}

	public function testMigrationTableSetting(): void
	{
		$conn = new Connection($this->getDsn(), $this->getSqlDirs());

		$this->assertSame('migrations', $conn->migrationsTable());
		$this->assertSame('migration', $conn->migrationsColumnMigration());
		$this->assertSame('applied', $conn->migrationsColumnApplied());

		$conn->setMigrationsTable('newmigrations');
		$conn->setMigrationsColumnMigration('newmigration');
		$conn->setMigrationsColumnApplied('newapplied');

		$this->assertSame('newmigrations', $conn->migrationsTable());
		$this->assertSame('newmigration', $conn->migrationsColumnMigration());
		$this->assertSame('newapplied', $conn->migrationsColumnApplied());
	}

	public function testWrongMigrationsTableName(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('Invalid migrations table name');

		$conn = new Connection($this->getDsn(), $this->getSqlDirs());
		$conn->setMigrationsTable('new migrations');
		$conn->migrationsTable();
	}

	public function testWrongMigrationColumnName(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('Invalid migrations table column name');

		$conn = new Connection($this->getDsn(), $this->getSqlDirs());
		$conn->setMigrationsColumnMigration('new migration');
		$conn->migrationsColumnMigration();
	}

	public function testWrongAppliedColumnName(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('Invalid migrations table column name');

		$conn = new Connection($this->getDsn(), $this->getSqlDirs());
		$conn->setMigrationsColumnApplied('new migration');
		$conn->migrationsColumnApplied();
	}
}
