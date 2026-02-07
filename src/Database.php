<?php

declare(strict_types=1);

namespace Duon\Quma;

use Duon\Quma\Connection;
use PDO;
use RuntimeException;

/** @psalm-api */
class Database
{
	use GetsSetsPrint;

	protected ?PDO $pdo = null;

	public function __construct(protected readonly Connection $conn)
	{
		$this->print = $conn->print();
	}

	public function __get(string $key): Folder
	{
		$exists = false;

		foreach ($this->conn->sql() as $path) {
			assert(is_string($path));
			$exists = is_dir($path . DIRECTORY_SEPARATOR . $key);

			if ($exists) {
				break;
			}
		}

		if (!$exists) {
			throw new RuntimeException('The SQL folder does not exist: ' . $key);
		}

		return new Folder($this, $key);
	}

	public function getFetchMode(): int
	{
		return $this->conn->fetchMode;
	}

	public function getPdoDriver(): string
	{
		return $this->conn->driver;
	}

	public function getSqlDirs(): array
	{
		return $this->conn->sql();
	}

	public function connect(): static
	{
		if ($this->pdo !== null) {
			return $this;
		}

		$conn = $this->conn;

		$pdo = new PDO(
			$conn->dsn,
			$conn->username,
			$conn->password,
			$conn->options,
		);

		// Always throw an exception when an error occures
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		// Allow getting the number of rows
		$pdo->setAttribute(PDO::ATTR_CURSOR, PDO::CURSOR_SCROLL);
		// deactivate native prepared statements by default
		$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
		// do not alter casing of the columns from sql
		$pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);

		$this->pdo = $pdo;

		return $this;
	}

	public function quote(string $value): string
	{
		return $this->requirePdo()->quote($value);
	}

	public function begin(): bool
	{
		return $this->requirePdo()->beginTransaction();
	}

	public function commit(): bool
	{
		return $this->requirePdo()->commit();
	}

	public function rollback(): bool
	{
		return $this->requirePdo()->rollback();
	}

	public function getConn(): PDO
	{
		return $this->requirePdo();
	}

	protected function requirePdo(): PDO
	{
		$this->connect();

		if ($this->pdo !== null) {
			return $this->pdo;
		}

		throw new RuntimeException('Database connection not initialized');
	}

	public function execute(string $query, mixed ...$args): Query
	{
		return new Query($this, $query, new Args($args));
	}
}
