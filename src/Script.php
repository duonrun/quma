<?php

declare(strict_types=1);

namespace Duon\Quma;

use InvalidArgumentException;

/** @psalm-api */
class Script
{
	protected Database $db;
	protected string $script;
	protected bool $isTemplate;

	public function __construct(Database $db, string $script, bool $isTemplate)
	{
		$this->db = $db;
		$this->script = $script;
		$this->isTemplate = $isTemplate;
	}

	public function __invoke(mixed ...$args): Query
	{
		return $this->invoke(...$args);
	}

	public function invoke(mixed ...$argsArray): Query
	{
		$args = new Args($argsArray);

		if ($this->isTemplate) {
			if ($args->type() === ArgType::Positional) {
				throw new InvalidArgumentException(
					'Template queries `*.sql.php` allow named parameters only',
				);
			}

			$script = $this->evaluateTemplate($this->script, $args);

			// We need to wrap the result of the prepare call in an array
			// to get back to the format of ...$argsArray.
			$args = new Args([$this->prepareTemplateVars($script, $args)]);
		} else {
			$script = $this->script;
		}

		return new Query($this->db, $script, $args);
	}

	protected function evaluateTemplate(string $path, Args $args): string
	{
		if (!is_file($path)) {
			return '';
		}

		return $this->renderTemplateFile(
			$path,
			$this->buildTemplateContext($args),
		);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function buildTemplateContext(Args $args): array
	{
		return array_merge(
			['pdodriver' => $this->db->getPdoDriver()],
			$args->getNamed(),
		);
	}

	/**
	 * @param string $templatePath
	 * @param array<array-key, mixed> $context
	 */
	protected function renderTemplateFile(string $templatePath, array $context): string
	{
		ob_start();

		(static function (string $__templatePath, array $__context): void {
			extract($__context, EXTR_SKIP);

			/** @psalm-suppress UnresolvableInclude */
			include $__templatePath;
		})($templatePath, $context);

		$result = ob_get_clean();

		return is_string($result) ? $result : '';
	}

	/**
	 * Removes all keys from $params which are not present
	 * in the $script.
	 *
	 * PDO does not allow unused parameters.
	 */
	protected function prepareTemplateVars(string $script, Args $args): array
	{
		// Remove PostgreSQL blocks
		$cleaned = preg_replace(Query::PATTERN_BLOCK, ' ', $script);
		// Remove strings
		$cleaned = preg_replace(Query::PATTERN_STRING, ' ', $cleaned ?? '');
		// Remove /* */ comments
		$cleaned = preg_replace(Query::PATTERN_COMMENT_MULTI, ' ', $cleaned ?? '');
		// Remove single line comments
		$cleaned = preg_replace(Query::PATTERN_COMMENT_SINGLE, ' ', $cleaned ?? '');

		$newArgs = [];

		// Match everything starting with : and a letter.
		// Exclude multiple colons, like type casts (::text).
		// Would not find a var if it is at the very beginning of script.
		$matches = preg_match_all(
			'/[^:]:[a-zA-Z][a-zA-Z0-9_]*/',
			$cleaned ?? '',
			$result,
			PREG_PATTERN_ORDER,
		);

		if ($matches !== false && $matches > 0) {
			$argsArray = $args->getNamed();
			$namedKeys = [];
			$newArgs = [];

			foreach (array_unique($result[0]) as $arg) {
				$a = substr($arg, 2);

				if ($a !== '') {
					$namedKeys[$a] = true;
				}
			}

			if (count($namedKeys) > 0) {
				$newArgs = array_intersect_key($argsArray, $namedKeys);
			}
		}

		return $newArgs;
	}
}
