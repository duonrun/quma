<?php

declare(strict_types=1);

namespace Duon\Quma\Tests;

use Duon\Cli\Commands;
use Duon\Quma\Commands as QumaCommands;
use Duon\Quma\Connection;
use Duon\Quma\Database;
use Override;
use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Throwable;

/**
 * @internal
 *
 * @coversNothing
 */
class TestCase extends BaseTestCase
{
	protected const DS = DIRECTORY_SEPARATOR;
	protected static string $sqliteDbPath1;
	protected static string $sqliteDbPath2;

	#[Override]
	public static function setUpBeforeClass(): void
	{
		self::$sqliteDbPath1 = getenv('QUMA_SQLITE_DB_PATH_1') ?: 'quma_db1.sqlite3';
		self::$sqliteDbPath2 = getenv('QUMA_SQLITE_DB_PATH_2') ?: 'quma_db2.sqlite3';
	}

	public static function root(): string
	{
		return __DIR__ . '/';
	}

	public function connection(
		?string $dsn = null,
		bool $additionalDirs = false,
		array|string|null $migrations = null,
	): Connection {
		$dsn = $dsn ?: $this->getDsn();
		$sql = $this->getSqlDirs($additionalDirs);
		$migrations = $migrations ?? self::root() . 'migrations';
		$conn = new Connection($dsn, $sql, migrations: $migrations);
		$conn->setMigrationsTable(str_starts_with($dsn, 'pgsql') ? 'public.migrations' : 'migrations');

		return $conn;
	}

	public function getSqlDirs(bool $additionalDirs = false): array|string
	{
		$prefix = self::root() . '/sql/';

		return $additionalDirs
			? [
				$prefix . 'default',
				[
					'sqlite' => $prefix . 'additional',
					'all' => $prefix . 'default',
				],
			] : $prefix . 'default';
	}

	public function getDb(
		bool $additionalDirs = false,
	): Database {
		return new Database($this->connection(additionalDirs: $additionalDirs));
	}

	public static function createTestDb(): void
	{
		$dbfile = self::getDbFile();

		if (is_file($dbfile)) {
			unlink($dbfile);
		}

		$db = new PDO(self::getDsn());

		$commands = [
			'
                CREATE TABLE members (
                    member INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    joined INTEGER NOT NULL,
                    left INTEGER
                )
            ', "
                INSERT INTO members
                    (name, joined, left)
                VALUES
                    ('Chuck Schuldiner', 1983, NULL),
                    ('Rick Rozz', 1983, 1989),
                    ('Chris Reifert', 1986, 1987),
                    ('Terry Butler', 1987, 1990),
                    ('Bill Andrews', 1987, 1990),
                    ('Paul Masdival', 1989, 1992),
                    ('James Murphy', 1989, 1990),
                    ('Sean Reinert', 1991, 1992),
                    ('Steve DiGiorgio', 1991, 1995),
                    ('Scott Carino', 1991, 1992),
                    ('Gene Hoglan', 1993, 1995),
                    ('Andy LaRocque', 1993, 1993),
                    ('Bobby Koelble', 1995, 1995),
                    ('Kelly Conlon', 1995, 1995),
                    ('Shannon Hamm', 1997, 2001),
                    ('Scott Clendenin', 1997, 2001),
                    ('Richard Christy', 1997, 2001)
            ", '
                CREATE TABLE albums (
                    album INTEGER PRIMARY KEY,
                    year  INTEGER NOT NULL,
                    title  VARCHAR (255) NOT NULL
                )
            ', "
                INSERT INTO albums
                    (year, title)
                VALUES
                    (1987,  'Scream Bloody Gore'),
                    (1988,  'Leprosy'),
                    (1990,  'Spiritual Healing'),
                    (1991,  'Human'),
                    (1993,  'Individual Thought Patterns'),
                    (1995,  'Symbolic'),
                    (1998,  'The Sound of Perseverance')
            ", '
                CREATE TABLE contributions (
                    album INTEGER NOT NULL,
                    member  INTEGER NOT NULL,
                    PRIMARY KEY(album, member)
                )
            ', '
                INSERT INTO contributions
                    (album, member)
                VALUES
                    (1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7),
                    (2, 2),
                    (3, 1),
                    (4, 3),
                    (5, 2), (5, 3),
                    (6, 4),
                    (7, 3),
                    (8, 4),
                    (9, 4), (9, 5),
                    (10, 4),
                    (11, 4), (11, 5),
                    (12, 5),
                    (13, 6),
                    (14, 6),
                    (15, 7),
                    (16, 7),
                    (17, 7)
            ', 'CREATE TABLE typetest (id INTEGER PRIMARY KEY, val)',
		];

		// execute the sql commands to create new tables
		foreach ($commands as $command) {
			$db->exec($command);
		}
	}

	public static function getAvailableDsns(bool $transactionsOnly = false): array
	{
		$dsns = [['transactions' => true, 'dsn' => 'sqlite:' . self::getDbFile()]];

		foreach (self::getServerDsns() as $dsn) {
			try {
				new PDO($dsn['dsn']);
				$dsns[] = $dsn;
			} catch (Throwable) {
				continue;
			}
		}

		if ($transactionsOnly) {
			return array_map(
				fn($dsn) => $dsn['dsn'],
				array_filter($dsns, fn($dsn) => $dsn['transactions'] === true),
			);
		}

		return array_map(fn($dsn) => $dsn['dsn'], $dsns);
	}

	public static function cleanUpTestDbs(): void
	{
		@unlink(self::getDbFile(self::$sqliteDbPath1));
		@unlink(self::getDbFile(self::$sqliteDbPath2));

		foreach (self::getServerDsns() as $dsn) {
			try {
				$conn = new PDO($dsn['dsn']);
				$conn->prepare('DROP TABLE IF EXISTS migrations')->execute();
				$conn->prepare('DROP TABLE IF EXISTS genres')->execute();
				$conn = null;
			} catch (Throwable) {
				continue;
			}
		}
	}

	protected function connections(string $firstKey): array
	{
		return [
			$firstKey => $this->connection(),
			'second' => $this->connection($this->getDsn(self::$sqliteDbPath2)),
		];
	}

	protected function commands(
		?string $dsn = null,
		array|string|null $migrations = null,
		bool $multipleConnections = false,
		string $firstMultipleConnectionsKey = 'default',
	): Commands {
		if ($multipleConnections) {
			$conn = $this->connections($firstMultipleConnectionsKey);
		} else {
			$conn = $this->connection(dsn: $dsn, migrations: $migrations);
		}

		return QumaCommands::get($conn);
	}

	protected static function getServerDsns(): array
	{
		$dbPgsqlHost = getenv('QUMA_DB_PGSQL_HOST') ?: 'localhost';
		// MySQL tries to use a local socket when host=localhost
		// is specified which does not work with WSL2/Windows.
		$dbMysqlHost = getenv('QUMA_DB_MYSQL_HOST') ?: '127.0.0.1';
		$dbName = getenv('QUMA_DB_NAME') ?: 'quma';
		$dbUser = getenv('QUMA_DB_USER') ?: 'quma';
		$dbPassword = getenv('QUMA_DB_PASSWORD') ?: 'quma';

		return [
			[
				'transactions' => true,
				'dsn' => "pgsql:host={$dbPgsqlHost};dbname={$dbName};user={$dbUser};password={$dbPassword}",
			], [
				'transactions' => false,
				'dsn' => "mysql:host={$dbMysqlHost};dbname={$dbName};user={$dbUser};password={$dbPassword}",
			],
		];
	}

	protected static function getDbFile(string $file = null): string
	{
		$file = $file ?? self::$sqliteDbPath1;

		return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $file;
	}

	protected static function getDsn(string $file = null): string
	{
		$file = $file ?? self::$sqliteDbPath1;
		return 'sqlite:' . self::getDbFile($file);
	}
}
