<?php

declare(strict_types=1);

namespace Duon\Quma;

use RuntimeException;

/** @psalm-api */
class Folder
{
	protected Database $db;
	protected string $folder;

	public function __construct(Database $db, string $folder)
	{
		$this->db = $db;
		$this->folder = $folder;
	}

	public function __get(string $key): Script
	{
		return $this->getScript($key);
	}

	public function __call(string $key, array $args): Query
	{
		$script = $this->getScript($key);

		return $script->invoke(...$args);
	}

	protected function scriptPath(string $key, bool $isTemplate): bool|string
	{
		$ext = $isTemplate ? '.tpql' : '.sql';

		foreach ($this->db->getSqlDirs() as $path) {
			assert(is_string($path));
			$result = $path . DIRECTORY_SEPARATOR
				. $this->folder . DIRECTORY_SEPARATOR
				. $key . $ext;

			if (is_file($result)) {
				return $result;
			}
		}

		return false;
	}

	protected function readScript(string $key): string|false
	{
		$script = $this->scriptPath($key, false);

		if ($script && is_string($script)) {
			return file_get_contents($script);
		}

		return false;
	}

	protected function getScript(string $key): Script
	{
		$stmt = $this->readScript($key);

		if ($stmt) {
			return new Script($this->db, $stmt, false);
		}

		// If $stmt is not truthy until now,
		// assume the script is a dnyamic sql template
		$dynStmt = $this->scriptPath($key, true);

		if ($dynStmt && is_string($dynStmt)) {
			return new Script($this->db, $dynStmt, true);
		}

		throw new RuntimeException('SQL script does not exist');
	}
}
