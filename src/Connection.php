<?php

declare(strict_types=1);

namespace Duon\Quma;

use Duon\Quma\Util;
use PDO;
use RuntimeException;
use ValueError;

/**
 * @psalm-api
 *
 * @psalm-type MigrationDirs = list<array<non-empty-string, non-empty-string>>
 * @psalm-type SqlDirs = list<non-empty-string>
 * @psalm-type SqlAssoc = array<non-empty-string, non-empty-string>
 * @psalm-type SqlMixed = list<non-empty-string|SqlAssoc>
 * @psalm-type SqlConfig = non-empty-string|SqlAssoc|SqlMixed
 */
class Connection
{
	use GetsSetsPrint;

	private const string TYPE_SQL = 'sql';
	private const string TYPE_MIGRATION = 'migration';

	/** @psalm-var non-empty-string */
	public readonly string $driver;

	/** @psalm-var SqlDirs */
	protected array $sql;

	/** @psalm-var MigrationDirs */
	protected array $migrations;

	protected string $migrationsTable = 'migrations';
	protected string $migrationsColumnMigration = 'migration';
	protected string $migrationsColumnApplied = 'applied';

	/**
	 * @psalm-param SqlConfig $sql
	 * @psalm-param MigrationDirs $migrations
	 * */
	public function __construct(
		public readonly string $dsn,
		string|array $sql,
		string|array|null $migrations = null,
		public readonly ?string $username = null,
		public readonly ?string $password = null,
		public readonly array $options = [],
		public readonly int $fetchMode = PDO::FETCH_BOTH,
		bool $print = false,
	) {
		$this->driver = $this->readDriver($this->dsn);
		$this->sql = $this->readDirs($sql, self::TYPE_SQL);
		$this->migrations = $this->readDirs($migrations ?? [], self::TYPE_MIGRATION);
		$this->print = $print;
	}

	public function setMigrationsTable(string $table): void
	{
		$this->migrationsTable = $table;
	}

	public function setMigrationsColumnMigration(string $column): void
	{
		$this->migrationsColumnMigration = $column;
	}

	public function setMigrationsColumnApplied(string $column): void
	{
		$this->migrationsColumnApplied = $column;
	}

	public function migrationsTable(): string
	{
		if ($this->driver === 'pgsql') {
			// PostgreSQL table names can contain a schema
			if (preg_match('/^([a-zA-Z0-9_]+\.)?[a-zA-Z0-9_]+$/', $this->migrationsTable)) {
				return $this->migrationsTable;
			}
		} else {
			if (preg_match('/^[a-zA-Z0-9_]+$/', $this->migrationsTable)) {
				return $this->migrationsTable;
			}
		}

		throw new ValueError('Invalid migrations table name: ' . $this->migrationsTable);
	}

	public function migrationsColumnMigration(): string
	{
		return $this->getColumnName($this->migrationsColumnMigration);
	}

	public function migrationsColumnApplied(): string
	{
		return $this->getColumnName($this->migrationsColumnApplied);
	}

	/** @psalm-param non-empty-string $migrations */
	public function addMigrationDir(string $migrations): void
	{
		$migrations = $this->readDirs($migrations, self::TYPE_MIGRATION);
		$this->migrations = array_merge($migrations, $this->migrations);
	}

	/** @psalm-return MigrationDirs */
	public function migrations(): array
	{
		return $this->migrations;
	}

	/** @psalm-param SqlConfig $sql */
	public function addSqlDirs(array|string $sql): void
	{
		$sql = $this->readDirs($sql, self::TYPE_SQL);
		$this->sql = array_merge($sql, $this->sql);
	}

	public function sql(): array
	{
		return $this->sql;
	}

	/** @psalm-return non-empty-string */
	protected function preparePath(string $path): string
	{
		$result = realpath($path);

		if ($result) {
			return $result;
		}

		throw new ValueError("Path does not exist: {$path}");
	}

	/** @psalm-return non-empty-string */
	protected function readDriver(string $dsn): string
	{
		$driver = explode(':', $dsn)[0];

		if (in_array($driver, PDO::getAvailableDrivers())) {
			assert(!empty($driver));

			return $driver;
		}

		throw new RuntimeException('PDO driver not supported: ' . $driver);
	}

	/**
	 * @psalm-param SqlAssoc $entry
	 *
	 * @psalm-return MigrationDirs
	 */
	protected function prepareDirs(array $entry, string $type): array
	{
		$hasDriver = array_key_exists($this->driver, $entry);
		$hasAll = array_key_exists('all', $entry);
		/** @psalm-var MigrationDirs */
		$dirs = [];

		if ($type === self::TYPE_SQL || $hasDriver || $hasAll) {
			// Add sql scripts for the current pdo driver.
			// Should be the first in the list as they
			// may have platform specific queries.
			if ($hasDriver) {
				$dirs = $this->collectDirs($dirs, $entry[$this->driver]);
			}

			// Add sql scripts for all platforms
			if ($hasAll) {
				$dirs = $this->collectDirs($dirs, $entry['all']);
			}

			return $dirs;
		}

		// If we get here it must be migrations
		foreach ($entry as $namespace => $migrations) {
			if (is_array($migrations)) {
				if (array_is_list($migrations)) {
					$dirs[$namespace] = $this->collectDirs([], $migrations);
				} else {
					$dirs[$namespace] = $this->prepareDirs($migrations, $type);
				}
			} else {
				$dirs[$namespace] = $this->preparePath($migrations);
			}
		}

		return $dirs;
	}

	protected function collectDirs(array $dirs, string|array $entry): array
	{
		if (is_string($entry)) {
			$dirs[] = $this->preparePath($entry);
		} else {
			$dirs = array_merge($dirs, array_map(fn($e) => $this->preparePath($e), $entry));
		}

		return $dirs;
	}

	/**
	 * Adds the sql script paths from configuration.
	 *
	 * Script paths are ordered last in first out (LIFO).
	 * Which means the last path added is the first one searched
	 * for a SQL script.
	 *
	 * @psalm-param SqlConfig $sql
	 *
	 * @psalm-return MigrationDirs
	 */
	protected function readDirs(string|array $sql, string $type): array
	{
		if (is_string($sql)) {
			/** @psalm-var MigrationDirs */
			return [$this->preparePath($sql)];
		}

		if (Util::isAssoc($sql)) {
			/** @psalm-var SqlAssoc $sql */
			return $this->prepareDirs($sql, $type);
		}

		/** @psalm-var MigrationDirs */
		$dirs = [];

		foreach ($sql as $entry) {
			if (is_string($entry)) {
				array_unshift($dirs, $this->preparePath($entry));

				continue;
			}

			if (array_is_list($entry)) {
				$dirs = array_merge(array_map(fn($e) => $this->preparePath($e), $entry), $dirs);

				continue;
			}

			$dirs = array_merge($this->prepareDirs($entry, $type), $dirs);
		}

		return $dirs;
	}

	protected function getColumnName(string $column): string
	{
		if (preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
			return $column;
		}

		throw new ValueError('Invalid migrations table column name: ' . $column);
	}
}
