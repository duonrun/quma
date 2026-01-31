<?php

declare(strict_types=1);

use Duon\Quma\Tests\TestCase;

require __DIR__ . '/../vendor/autoload.php';

$clean = static function (): void {
	$migrationsDir = __DIR__ . '/migrations/';
	$paths = glob("{$migrationsDir}*test-migration*");

	if ($paths !== false) {
		foreach ($paths as $path) {
			@unlink($path);
		}
	}

	TestCase::cleanUpTestDbs();
};

$clean();

register_shutdown_function($clean);
