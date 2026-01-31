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
use RuntimeException;
use ValueError;

/**
 * @internal
 */
class MigrationsTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		// Remove remnants of previous runs
		$migrationsDir = TestCase::root() . '/migrations/';
		array_map('unlink', glob("{$migrationsDir}*test-migration*"));

		TestCase::cleanupTestDbs();
	}

	public function testCreateMigrationsTableSuccess(): void
	{
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];

		ob_start();
		$result = (new Runner($this->commands()))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $result);
		$this->assertStringContainsString("Created table 'migrations'", $content);
	}

	public function testWrongConnection(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/doesnotexist/');

		$_SERVER['argv'] = ['run', 'create-migrations-table', '--conn', 'doesnotexist'];

		(new Runner($this->commands(multipleConnections: true)))->run();
	}

	public function testRunMigrationsNoMigrationsDirectoriesDefined(): void
	{
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];

		ob_start();
		$result = (new Runner($this->commands(migrations: [])))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString('No migration directories defined', $content);
	}

	#[DataProvider('transactionConnectionProvider')]
	public function testRunMigrationsSuccessWithoutApply(string $dsn): void
	{
		$_SERVER['argv'] = ['run', 'migrations'];
		$driver = strtok($dsn, ':');

		ob_start();
		$result = (new Runner($this->commands(dsn: $dsn)))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $result);
		$this->assertMatchesRegularExpression('/000000-000000-migration.sql[^\n]*?success/', $content);
		$this->assertMatchesRegularExpression('/000000-000001-migration.php[^\n]*?success/', $content);
		$this->assertMatchesRegularExpression('/000000-000002-migration.tpql[^\n]*?success/', $content);
		$this->assertMatchesRegularExpression('/000000-000005-migration-\[' . $driver . '\].sql[^\n]*?success/', $content);
		$this->assertStringContainsString('Would apply 4 migrations', $content);
	}

	#[DataProvider('connectionProvider')]
	public function testRunMigrationsSuccess(string $dsn): void
	{
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];
		$driver = strtok($dsn, ':');

		ob_start();
		$result = (new Runner($this->commands(dsn: $dsn)))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $result);
		$this->assertMatchesRegularExpression('/000000-000000-migration.sql[^\n]*?success/', $content);
		$this->assertMatchesRegularExpression('/000000-000001-migration.php[^\n]*?success/', $content);
		$this->assertMatchesRegularExpression('/000000-000002-migration.tpql[^\n]*?success/', $content);
		$this->assertMatchesRegularExpression('/000000-000005-migration-\[' . $driver . '\].sql[^\n]*?success/', $content);
		$this->assertStringContainsString('4 migrations successfully applied', $content);
	}

	#[DataProvider('connectionProvider')]
	#[Depends('testRunMigrationsSuccess')]
	public function testRunMigrationsAgain(string $dsn): void
	{
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];

		ob_start();
		(new Runner($this->commands(dsn: $dsn)))->run();
		ob_end_clean();

		ob_start();
		$result = (new Runner($this->commands(dsn: $dsn)))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $result);
		$this->assertDoesNotMatchRegularExpression('/000000-000000-migration.sql[^\n]*?success/', $content);
		$this->assertStringContainsString('No migrations applied', $content);
	}

	public function testAddMigrationSql(): void
	{
		// Run existing migrations first
		ob_start();
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];
		(new Runner($this->commands()))->run();
		ob_end_clean();

		// add the migrations
		ob_start();
		$_SERVER['argv'] = ['run', 'add-migration', '--file', 'test migration'];
		$migration = (new Runner($this->commands()))->run();
		ob_end_clean();

		$this->assertIsString($migration);
		$this->assertFileExists($migration);
		$this->assertStringStartsWith(TestCase::root(), $migration);
		$this->assertStringEndsWith('.sql', $migration);

		// Add content and run it
		file_put_contents($migration, 'SELECT 1;');

		// Re-run migrations
		ob_start();
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];
		$result = (new Runner($this->commands()))->run();
		$content = ob_get_contents();
		ob_end_clean();
		@unlink($migration);

		$this->assertFileDoesNotExist($migration);
		$this->assertSame(0, $result);
		$this->assertMatchesRegularExpression('/' . basename($migration) . '[^\n]*?success/', $content);
		$this->assertStringContainsString('1 migration successfully applied', $content);
	}

	public function testAddMigrationTpql(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '--file', 'test migration.tpql'];

		ob_start();
		$migration = (new Runner($this->commands()))->run();
		ob_end_clean();

		$this->assertIsString($migration);
		$this->assertFileExists($migration);
		$this->assertStringStartsWith(TestCase::root(), $migration);
		$this->assertStringEndsWith('.tpql', $migration);
		$this->assertStringNotContainsString('.sql', $migration);

		$content = file_get_contents($migration);

		@unlink($migration);
		$this->assertFileDoesNotExist($migration);
		$this->assertStringContainsString('<?php if', $content);
	}

	public function testAddMigrationPhp(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '--file', 'test migration.php'];

		ob_start();
		$migration = (new Runner($this->commands()))->run();
		ob_end_clean();

		$this->assertIsString($migration);
		$this->assertFileExists($migration);
		$this->assertStringStartsWith(TestCase::root(), $migration);
		$this->assertStringEndsWith('.php', $migration);
		$this->assertStringNotContainsString('.sql', $migration);

		$content = file_get_contents($migration);

		@unlink($migration);
		$this->assertFileDoesNotExist($migration);
		$this->assertStringContainsString('TestMigration_', $content);
		$this->assertStringContainsString('implements MigrationInterface', $content);
	}

	public function testAddMigrationWithWrongFileExtension(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '-f', 'test.exe'];

		ob_start();
		(new Runner($this->commands()))->run();
		$output = ob_get_contents();
		ob_end_clean();

		$this->assertStringContainsString('Wrong file extension', $output);
	}

	public function testWrongMigrationsDirectory(): void
	{
		$this->expectException(ValueError::class);
		$this->expectExceptionMessage('Path does not exist: not/available');

		$this->connection(migrations: 'not/available');
	}

	public function testAddMigrationToVendor(): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '-f', 'test'];

		ob_start();
		(new Runner($this->commands(migrations: TestCase::root() . '/../vendor')))->run();
		$output = ob_get_contents();
		ob_end_clean();

		$this->assertStringContainsString("is inside './vendor'", $output);
	}

	#[DataProvider('failingSqlMigrationProvider')]
	public function testFailingSqlMigration(string $dsn, string $ext): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '--file', "test-migration-failing{$ext}"];

		ob_start();
		$migration = (new Runner($this->commands(dsn: $dsn)))->run();

		// Add content and run it
		file_put_contents($migration, 'RUBBISH;');
		$_SERVER['argv'] = ['run', 'migrations', '--apply', '--stacktrace'];

		$result = (new Runner($this->commands(dsn: $dsn)))->run();
		$content = ob_get_contents();
		ob_end_clean();
		@unlink($migration);

		$this->assertFileDoesNotExist($migration);
		$this->assertSame(1, $result);
		$this->assertStringContainsString("\n#0", $content);

		if (str_starts_with($dsn, 'mysql')) {
			$this->assertStringContainsString('applied until the error occured', $content);
			$this->assertStringContainsString('SQLSTATE[42000]', $content);
		} elseif (str_starts_with($dsn, 'pgsql')) {
			$this->assertStringContainsString('Due to errors no migrations applied', $content);
			$this->assertStringContainsString('SQLSTATE[42601]', $content);
		} else {
			$this->assertStringContainsString('Due to errors no migrations applied', $content);
			$this->assertStringContainsString('SQLSTATE[HY000]', $content);
		}
	}

	#[DataProvider('failingPhpMigrationProvider')]
	public function testFailingTpqlPhpMigrationPhpError(string $dsn, string $ext): void
	{
		$_SERVER['argv'] = ['run', 'add-migration', '--file', "test-migration-php-failing.{$ext}"];

		ob_start();
		$migration = (new Runner($this->commands(dsn: $dsn)))->run();

		// Add content and run it
		file_put_contents($migration, '<?php echo if)');
		$_SERVER['argv'] = ['run', 'migrations', '--apply'];

		$result = (new Runner($this->commands(dsn: $dsn)))->run();
		$content = ob_get_contents();
		ob_end_clean();
		@unlink($migration);

		$this->assertFileDoesNotExist($migration);
		$this->assertSame(1, $result);

		if (str_starts_with($dsn, 'mysql')) {
			$this->assertStringContainsString('applied until the error occured', $content);
		} else {
			$this->assertStringContainsString('Due to errors no migrations applied', $content);
		}
	}

	public function testFailingDueToReadonlyMigrationsDirectory(): void
	{
		$tmpdir = sys_get_temp_dir() . '/chuck' . (string) mt_rand();
		mkdir($tmpdir, 0400);

		$_SERVER['argv'] = ['run', 'add-migration', '--file', 'test-migration.sql'];

		ob_start();
		(new Runner($this->commands(migrations: $tmpdir)))->run();
		$content = ob_get_contents();
		ob_end_clean();

		rmdir($tmpdir);

		$this->assertStringContainsString('directory is not writable', $content);
	}

	public function testRunMigrationsWithNamespace(): void
	{
		// Create namespaced migration structure - must use arrays not single strings
		$conn = $this->connection(
			migrations: [
				'default' => [TestCase::root() . 'migrations'],
				'feature' => [TestCase::root() . 'sql/additional'],
			],
		);

		$_SERVER['argv'] = ['run', 'migrations', '--apply'];

		ob_start();
		$result = (new Runner($this->commands()))->run();
		ob_end_clean();

		// Run migration with specific namespace
		$_SERVER['argv'] = ['run', 'migrations', '--namespace', 'feature', '--apply'];

		ob_start();
		$result = (new Runner(\Duon\Quma\Commands::get($conn)))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(0, $result);
		$this->assertStringContainsString('No migrations applied', $content);
	}

	public function testRunMigrationsWithNonExistentNamespace(): void
	{
		// Create namespaced migration structure
		$conn = $this->connection(
			migrations: [
				'default' => [TestCase::root() . 'migrations'],
				'feature' => [TestCase::root() . 'sql/additional'],
			],
		);

		$_SERVER['argv'] = ['run', 'migrations', '--namespace', 'nonexistent', '--apply'];

		ob_start();
		$result = (new Runner(\Duon\Quma\Commands::get($conn)))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString("Migration namespace 'nonexistent' does not exist", $content);
	}

	public function testRunMigrationsWithoutDefaultNamespace(): void
	{
		// Create namespaced migration structure without 'default'
		$conn = $this->connection(
			migrations: [
				'feature' => [TestCase::root() . 'migrations'],
			],
		);

		$_SERVER['argv'] = ['run', 'migrations', '--apply'];

		ob_start();
		$result = (new Runner(\Duon\Quma\Commands::get($conn)))->run();
		$content = ob_get_contents();
		ob_end_clean();

		$this->assertSame(1, $result);
		$this->assertStringContainsString("Migration namespace 'default' does not exist", $content);
		$this->assertStringContainsString('--namespace', $content);
	}

	public static function connectionProvider(): array
	{
		return array_map(fn($dsn) => [$dsn], TestCase::getAvailableDsns());
	}

	public static function transactionConnectionProvider(): array
	{
		return array_map(fn($dsn) => [$dsn], TestCase::getAvailableDsns(transactionsOnly: true));
	}

	public static function migrationExtensionProvider(): array
	{
		return [['.sql'], ['.tpql']];
	}

	public static function phpMigrationExtensionProvider(): array
	{
		return [['php'], ['tpql']];
	}

	public static function failingSqlMigrationProvider(): array
	{
		$connections = TestCase::getAvailableDsns();
		$extensions = ['.sql', '.tpql'];
		$result = [];

		foreach ($connections as $dsn) {
			foreach ($extensions as $ext) {
				$result[] = [$dsn, $ext];
			}
		}

		return $result;
	}

	public static function failingPhpMigrationProvider(): array
	{
		$connections = TestCase::getAvailableDsns();
		$extensions = ['php', 'tpql'];
		$result = [];

		foreach ($connections as $dsn) {
			foreach ($extensions as $ext) {
				$result[] = [$dsn, $ext];
			}
		}

		return $result;
	}
}
