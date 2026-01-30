<?php

declare(strict_types=1);

namespace Duon\Quma;

use Generator;
use InvalidArgumentException;
use PDO;
use PDOStatement;

/** @psalm-api */
class Query
{
	// Matches multi line single and double quotes and handles \' \" escapes
	public const string PATTERN_STRING = '/([\'"])(?:\\\1|[\s\S])*?\1/';

	// PostgreSQL blocks delimited with $$
	public const string PATTERN_BLOCK = '/(\$\$)[\s\S]*?\1/';

	// Multi line comments /* */
	public const string PATTERN_COMMENT_MULTI = '/\/\*([\s\S]*?)\*\//';

	// Single line comments --
	public const string PATTERN_COMMENT_SINGLE = '/--.*$/m';

	protected PDOStatement $stmt;
	protected bool $executed = false;

	public function __construct(
		protected Database $db,
		protected string $query,
		protected Args $args,
	) {
		$this->stmt = $this->db->getConn()->prepare($query);

		if ($args->count() > 0) {
			$this->bindArgs($args->get(), $args->type());
		}

		if ($db->print()) {
			$msg = "\n\n-----------------------------------------------\n\n"
				. $this->interpolate()
				. "\n------------------------------------------------\n";

			if (isset($_SERVER['SERVER_SOFTWARE'])) {
				// @codeCoverageIgnoreStart
				error_log($msg);
				// @codeCoverageIgnoreEnd
			} else {
				echo $msg;
			}
		}
	}

	public function __toString(): string
	{
		return $this->interpolate();
	}

	public function one(?int $fetchMode = null): ?array
	{
		$this->db->connect();

		if (!$this->executed) {
			$this->stmt->execute();
			$this->executed = true;
		}

		return $this->nullIfNot($this->stmt->fetch($fetchMode ?? $this->db->getFetchMode()));
	}

	public function all(?int $fetchMode = null): array
	{
		$this->db->connect();
		$this->stmt->execute();

		return $this->stmt->fetchAll($fetchMode ?? $this->db->getFetchMode());
	}

	public function lazy(?int $fetchMode = null): Generator
	{
		$this->db->connect();
		$this->stmt->execute();
		$fetchMode = $fetchMode ?? $this->db->getFetchMode();

		/**
		 * @psalm-suppress MixedAssignment
		 *
		 * As the fetch mode can be changed it is not clear
		 * which type will be returned from `fetch`
		 */
		while ($record = $this->stmt->fetch($fetchMode)) {
			yield $record;
		}
	}

	public function run(): bool
	{
		$this->db->connect();

		return $this->stmt->execute();
	}

	public function len(): int
	{
		$this->db->connect();
		$this->stmt->execute();

		return $this->stmt->rowCount();
	}

	/**
	 * For debugging purposes only.
	 *
	 * Replaces any parameter placeholders in a query with the
	 * value of that parameter and returns the query as string.
	 *
	 * Covers most of the cases but is not perfect.
	 */
	public function interpolate(): string
	{
		$prep = $this->prepareQuery($this->query);
		$argsArray = $this->args->get();

		if ($this->args->type() === ArgType::Named) {
			/** @psalm-suppress InvalidArgument */
			$interpolated = $this->interpolateNamed($prep->query, $argsArray);
		} else {
			$interpolated = $this->interpolatePositional($prep->query, $argsArray);
		}

		return $this->restoreQuery($interpolated, $prep);
	}

	protected function bindArgs(array $args, ArgType $argType): void
	{
		/** @psalm-suppress MixedAssignment -- $value is thouroughly typechecked in the loop */
		foreach ($args as $a => $value) {
			if ($argType === ArgType::Named) {
				$arg = ':' . $a;
			} else {
				$arg = (int) $a + 1; // question mark placeholders ar 1-indexed
			}

			switch (gettype($value)) {
				case 'boolean':
					$this->stmt->bindValue($arg, $value, PDO::PARAM_BOOL);

					break;

				case 'integer':
					$this->stmt->bindValue($arg, $value, PDO::PARAM_INT);

					break;

				case 'string':
					$this->stmt->bindValue($arg, $value, PDO::PARAM_STR);

					break;

				case 'NULL':
					$this->stmt->bindValue($arg, $value, PDO::PARAM_NULL);

					break;

				case 'array':
					$this->stmt->bindValue($arg, json_encode($value), PDO::PARAM_STR);

					break;

				default:
					throw new InvalidArgumentException(
						'Only the types bool, int, string, null and array are supported',
					);
			}
		}
	}

	protected function nullIfNot(mixed $value): ?array
	{
		if (is_array($value)) {
			return $value;
		}

		return null;
	}

	protected function convertValue(mixed $value): string
	{
		if (is_string($value)) {
			return "'" . $value . "'";
		}

		if (is_array($value)) {
			$encoded = json_encode($value);

			return "'" . ($encoded !== false ? $encoded : '[]') . "'";
		}

		if (is_null($value)) {
			return 'NULL';
		}

		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		return (string) $value;
	}

	protected function prepareQuery(string $query): PreparedQuery
	{
		$patterns = [
			self::PATTERN_BLOCK,
			self::PATTERN_STRING,
			self::PATTERN_COMMENT_MULTI,
			self::PATTERN_COMMENT_SINGLE,
		];

		/** @psalm-var array<non-empty-string, non-empty-string> */
		$swaps = [];

		$i = 0;

		do {
			$found = false;

			foreach ($patterns as $pattern) {
				$matches = [];

				if ($query !== null && preg_match($pattern, $query, $matches)) {
					$match = $matches[0];
					$replacement = "___CHUCK_REPLACE_{$i}___";
					assert(!empty($match));
					$swaps[$replacement] = $match;

					$query = preg_replace($pattern, $replacement, $query, limit: 1);
					$found = true;
					$i++;

					break;
				}
			}
		} while ($found);

		return new PreparedQuery($query ?? '', $swaps);
	}

	protected function restoreQuery(string $query, PreparedQuery $prep): string
	{
		foreach ($prep->swaps as $swap => $replacement) {
			$query = str_replace($swap, $replacement, $query);
		}

		return $query;
	}

	/** @psalm-param array<non-empty-string, mixed> $args */
	protected function interpolateNamed(string $query, array $args): string
	{
		$map = [];

		/** @psalm-suppress MixedAssignment -- $value is checked in convertValue */
		foreach ($args as $key => $value) {
			$key = ':' . $key;
			$map[$key] = $this->convertValue($value);
		}

		return strtr($query, $map);
	}

	protected function interpolatePositional(string $query, array $args): string
	{
		$result = $query;
		/** @psalm-suppress MixedAssignment -- $value is checked in convertValue */
		foreach ($args as $value) {
			$replaced = preg_replace('/\\?/', $this->convertValue($value), $result, 1);
			$result = $replaced ?? $result;
		}

		return $result;
	}
}
