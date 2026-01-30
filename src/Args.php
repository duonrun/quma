<?php

declare(strict_types=1);

namespace Duon\Quma;

use Duon\Quma\Util;

/**
 * @psalm-type ArgsArray = list<mixed>|array<non-empty-string, mixed>
 */
final class Args
{
	protected ArgType $type;
	protected int $count;

	/** @psalm-var ArgsArray */
	protected readonly array $args;

	public function __construct(array $args)
	{
		$this->args = $this->prepare($args);
	}

	/** @psalm-return ArgsArray */
	public function get(): array
	{
		return $this->args;
	}

	public function count(): int
	{
		return $this->count;
	}

	public function type(): ArgType
	{
		return $this->type;
	}

	protected function prepare(array $args): array
	{
		$this->count = count($args);

		if ($this->count === 1 && is_array($args[0])) {
			if (Util::isAssoc($args[0])) {
				$this->type = ArgType::Named;
			} else {
				$this->type = ArgType::Positional;
			}

			return $args[0];
		}

		$this->type = ArgType::Positional;

		return $args;
	}
}
