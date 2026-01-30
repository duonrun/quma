<?php

declare(strict_types=1);

namespace Duon\Quma\Commands;

use Duon\Cli\Opts;
use Override;

final class Add extends Command
{
	protected string $name = 'add-migration';
	protected string $group = 'Database';
	protected string $prefix = 'db';
	protected string $description = 'Initialize a new migration';

	#[Override]
	public function run(): string|int
	{
		$env = $this->env;
		$opts = new Opts();
		$fileName = $opts->get('-f', $opts->get('--file', ''));

		if (empty($fileName)) {
			// Would stop the test suit and wait for input
			// @codeCoverageIgnoreStart
			$fileName = readline('Name of the migration script: ');
			// @codeCoverageIgnoreEnd
		}

		$fileName = str_replace(' ', '-', $fileName);
		$fileName = str_replace('_', '-', $fileName);
		$fileName = strtolower($fileName);
		$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

		if (!$ext) {
			$fileName .= '.sql';
		} else {
			if (!in_array($ext, ['sql', 'php', 'tpql'])) {
				echo "Wrong file extension '{$ext}'. Use 'sql', 'php' or 'tpql' instead.\nAborting.\n";

				return 1;
			}
		}

		$migrations = $env->conn->migrations();

		// Get the first migrations directory from the list (the last one added)
		// TODO: let the user choose the migrations dir if there are more than one
		$migrationsDir = $migrations[0];

		if ($migrationsDir && strpos($migrationsDir, '/vendor') !== false) {
			echo "The migrations directory is inside './vendor'.\n  -> {$migrationsDir}\nAborting.\n";

			return 1;
		}

		if (!is_writable($migrationsDir)) {
			echo "Migrations directory is not writable\n  -> {$migrationsDir}\nAborting. \n";

			return 1;
		}

		$timestamp = date('ymd-His', time());

		$migration = $migrationsDir . DIRECTORY_SEPARATOR . $timestamp . '-' . $fileName;
		$f = fopen($migration, 'w');

		if ($ext === 'php') {
			fwrite($f, $this->getPhpContent($fileName, $timestamp));
		} elseif ($ext === 'tpql') {
			fwrite($f, $this->getTpqlContent());
		}

		fclose($f);
		echo "Migration created:\n{$migration}\n";

		return $migration;
	}

	protected function getPhpContent(string $fileName, string $timestamp): string
	{
		// Translates what-is-up.sql into WhatIsUp
		$className = implode(
			'',
			explode(
				'-',
				explode(
					'.',
					ucwords($fileName, '-'),
				)[0],
			),
		) . '_' . str_replace('-', '_', $timestamp);

		return "<?php

declare(strict_types=1);

use \\PDO;
use Duon\\Quma\\Connection;
use Duon\\Quma\\Database;
use Duon\\Quma\\MigrationInterface;


class {$className} implements MigrationInterface
{
    public function run(Database \$db): bool
    {
        \$db->execute('')->run();
        \$result = \$db->execute('')->all(PDO::FETCH_ASSOC);

        return true;
    }
}

return new {$className}();";
	}

	protected function getTpqlContent(): string
	{
		return "<?php if (\$driver === 'pgsql') : ?>

<?php else : ?>

<?php endif ?>
";
	}
}
