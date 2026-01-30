<?php

declare(strict_types=1);

namespace Duon\Quma;

final class Util
{
	public static function isAssoc(array $arr): bool
	{
		if ([] === $arr) {
			return false;
		}

		return array_keys($arr) !== range(0, count($arr) - 1);
	}
}
