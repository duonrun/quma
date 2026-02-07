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
 * @psalm-type SqlDirs = list<non-empty-string>
 * @psalm-type SqlAssoc = array<non-empty-string, non-empty-string|list<non-empty-string>>
 * @psalm-type SqlMixed = list<non-empty-string|SqlAssoc>
 * @psalm-type SqlConfig = non-empty-string|SqlAssoc|SqlMixed
 * @psalm-type MigrationDirsFlat = list<non-empty-string>
 * @psalm-type MigrationDirsNamespaced = array<non-empty-string, non-empty-string|list<non-empty-string>>
 * @psalm-type MigrationDirs = MigrationDirsFlat|MigrationDirsNamespaced
 */
class Connection
{
	use GetsSetsPrint;

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
	 * @psalm-param SqlConfig|null $migrations
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
		$this->sql = $this->readFlatDirs($sql);
		$this->migrations = $this->readMigrationDirs($migrations ?? []);
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

	/**
	 * Adds a migration directory to the flat list.
	 *
	 * Note: This only works when migrations are configured as a flat list.
	 * For namespaced migrations, configure them in the constructor.
	 *
	 * @psalm-param non-empty-string $migrations
	 */
	public function addMigrationDir(string $migrations): void
	{
		$dirs = $this->readFlatDirs($migrations);

		// Only merge if migrations is a flat list
		if (!array_is_list($this->migrations)) {
			return;
		}

		$merged = $dirs;

		foreach ($this->migrations as $existingDir) {
			$merged[] = $existingDir;
		}

		$this->migrations = $merged;
	}

	/** @psalm-return list<non-empty-string>|array<non-empty-string, non-empty-string|list<non-empty-string>> */
	public function migrations(): array
	{
		return $this->migrations;
	}

	/** @psalm-param SqlConfig $sql */
	public function addSqlDirs(array|string $sql): void
	{
		$dirs = $this->readFlatDirs($sql);
		$this->sql = array_merge($dirs, $this->sql);
	}

	public function sql(): array
	{
		return $this->sql;
	}

	/** @psalm-return non-empty-string */
	protected function preparePath(string $path): string
	{
		$result = realpath($path);

		if ($result !== false && $result !== '') {
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
	 * Reads directories from configuration into a flat list.
	 *
	 * @psalm-param SqlConfig $config
	 *
	 * @psalm-return list<non-empty-string>
	 */
	protected function readFlatDirs(string|array $config, bool $preserveOrder = false): array
	{
		if (is_string($config)) {
			return [$this->preparePath($config)];
		}

		if (count($config) === 0) {
			return [];
		}

		if (Util::isAssoc($config)) {
			return $this->readAssocDirs($config);
		}

		$dirs = [];

		foreach ($config as $entry) {
			if (is_string($entry)) {
				if ($preserveOrder) {
					$dirs[] = $this->preparePath($entry);
				} else {
					array_unshift($dirs, $this->preparePath($entry));
				}

				continue;
			}

			if (array_is_list($entry)) {
				foreach ($entry as $path) {
					if (is_string($path)) {
						if ($preserveOrder) {
							$dirs[] = $this->preparePath($path);
						} else {
							array_unshift($dirs, $this->preparePath($path));
						}
					}
				}

				continue;
			}

			if ($preserveOrder) {
				$dirs = array_merge($dirs, $this->readAssocDirs($entry));
			} else {
				$dirs = array_merge($this->readAssocDirs($entry), $dirs);
			}
		}

		return $dirs;
	}

	/**
	 * Reads directories from an associative array config.
	 *
	 * @psalm-param array<array-key, mixed> $entry
	 *
	 * @psalm-return list<non-empty-string>
	 */
	protected function readAssocDirs(array $entry): array
	{
		$hasDriver = array_key_exists($this->driver, $entry);
		$hasAll = array_key_exists('all', $entry);
		$dirs = [];

		if ($hasDriver) {
			$dirs = array_merge($dirs, $this->readDirsEntry($entry[$this->driver]));
		}

		if ($hasAll) {
			$dirs = array_merge($dirs, $this->readDirsEntry($entry['all']));
		}

		return $dirs;
	}

	/**
	 * @psalm-return list<non-empty-string>
	 */
	protected function readDirsEntry(mixed $entry): array
	{
		if (is_string($entry)) {
			return [$this->preparePath($entry)];
		}

		if (!is_array($entry)) {
			return [];
		}

		$dirs = [];

		array_walk(
			$entry,
			function (mixed $value) use (&$dirs): void {
				if (is_string($value)) {
					$dirs[] = $this->preparePath($value);

					return;
				}

				if (!is_array($value)) {
					return;
				}

				if (Util::isAssoc($value)) {
					$dirs = array_merge($dirs, $this->readAssocDirs($value));

					return;
				}

				array_walk(
					$value,
					function (mixed $path) use (&$dirs): void {
						if (is_string($path)) {
							$dirs[] = $this->preparePath($path);
						}
					},
				);
			},
		);

		return $dirs;
	}

	/**
	 * Reads migration directories from configuration.
	 *
	 * Migrations can be configured as:
	 * - A flat list of directories
	 * - A namespaced structure with string keys mapping to directories
	 *
	 * @psalm-param SqlConfig $config
	 *
	 * @psalm-return MigrationDirs
	 */
	protected function readMigrationDirs(string|array $config): array
	{
		if (is_string($config)) {
			return [$this->preparePath($config)];
		}

		if (count($config) === 0) {
			return [];
		}

		// Check if this is a namespaced config (assoc array with non-driver/all keys)
		if (Util::isAssoc($config) && !$this->isDriverConfig($config)) {
			return $this->readNamespacedDirs($config);
		}

		// Otherwise treat as flat dirs
		return $this->readFlatDirs($config);
	}

	/**
	 * Checks if an associative array is a driver-specific config.
	 *
	 * @psalm-param array<array-key, mixed> $config
	 */
	protected function isDriverConfig(array $config): bool
	{
		return array_key_exists($this->driver, $config) || array_key_exists('all', $config);
	}

	/**
	 * Reads namespaced migration directories.
	 *
	 * @psalm-param array<array-key, mixed> $config
	 *
	 * @psalm-return MigrationDirsNamespaced
	 */
	protected function readNamespacedDirs(array $config): array
	{
		$result = [];

		array_walk(
			$config,
			function (mixed $dirs, int|string $namespace) use (&$result): void {
				if (!is_string($namespace) || $namespace === '') {
					return;
				}

				if (is_string($dirs)) {
					$result[$namespace] = $this->preparePath($dirs);

					return;
				}

				if (!is_array($dirs)) {
					return;
				}

				$result[$namespace] = $this->readDirsEntry($dirs);
			},
		);

		return $result;
	}

	protected function getColumnName(string $column): string
	{
		if (preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
			return $column;
		}

		throw new ValueError('Invalid migrations table column name: ' . $column);
	}
}
