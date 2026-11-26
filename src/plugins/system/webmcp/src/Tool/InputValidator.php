<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\System\WebMcp\Tool;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Checks a tool call's input against the tool's input schema, on the server.
 *
 * The browser may check the schema too, but the request that reaches the server can
 * come from anything: an agent, a script, a person with the developer tools open. So
 * the server checks it again and never trusts the input.
 *
 * Only the JSON Schema subset the tools use: an object with typed properties
 * (string, integer, number, boolean, array of those), `required`, `enum`, `minLength`,
 * `maxLength`, `minimum`, `maximum`, `maxItems` and `default`. Unknown properties are
 * refused.
 */
final class InputValidator
{
	/**
	 * @param   array<string, mixed>  $schema
	 * @param   mixed                 $input   The decoded JSON input.
	 *
	 * @return  array<string, mixed>  The input, with the defaults filled in.
	 *
	 * @throws  ToolFailure
	 */
	public function validate(array $schema, mixed $input): array
	{
		if ($input === null || $input === []) {
			$input = [];
		}

		if (!\is_array($input) || array_is_list($input) && $input !== []) {
			throw new ToolFailure('The input must be a JSON object.');
		}

		/** @var array<string, array<string, mixed>> $properties */
		$properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
		$required   = \is_array($schema['required'] ?? null) ? $schema['required'] : [];

		foreach (array_keys($input) as $name) {
			if (!isset($properties[$name])) {
				throw new ToolFailure(\sprintf('Unknown input property "%s".', $name));
			}
		}

		$result = [];

		foreach ($properties as $name => $rules) {
			if (!\array_key_exists($name, $input) || $input[$name] === null) {
				if (\in_array($name, $required, true)) {
					throw new ToolFailure(\sprintf('The input property "%s" is required.', $name));
				}

				if (\array_key_exists('default', $rules)) {
					$result[$name] = $rules['default'];
				}

				continue;
			}

			$result[$name] = $this->value($name, $rules, $input[$name]);
		}

		return $result;
	}

	/**
	 * @param   array<string, mixed>  $rules
	 *
	 * @throws  ToolFailure
	 */
	private function value(string $name, array $rules, mixed $value): mixed
	{
		$type = \is_string($rules['type'] ?? null) ? $rules['type'] : 'string';

		$value = match ($type) {
			'string'  => $this->string($name, $rules, $value),
			'integer' => $this->integer($name, $rules, $value),
			'number'  => $this->number($name, $rules, $value),
			'boolean' => $this->boolean($name, $value),
			'array'   => $this->list($name, $rules, $value),
			default   => throw new \LogicException(\sprintf('Schema type "%s" is not supported.', $type)),
		};

		if (\is_array($rules['enum'] ?? null) && !\in_array($value, $rules['enum'], true)) {
			throw new ToolFailure(\sprintf(
				'"%s" must be one of: %s.',
				$name,
				implode(', ', array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $rules['enum']))
			));
		}

		return $value;
	}

	/**
	 * @param   array<string, mixed>  $rules
	 */
	private function string(string $name, array $rules, mixed $value): string
	{
		if (!\is_string($value)) {
			throw new ToolFailure(\sprintf('"%s" must be a string.', $name));
		}

		$value  = trim($value);
		$length = mb_strlen($value);

		if (\is_int($rules['minLength'] ?? null) && $length < $rules['minLength']) {
			throw new ToolFailure(\sprintf('"%s" must be at least %d characters.', $name, $rules['minLength']));
		}

		if (\is_int($rules['maxLength'] ?? null) && $length > $rules['maxLength']) {
			throw new ToolFailure(\sprintf('"%s" must be at most %d characters.', $name, $rules['maxLength']));
		}

		return $value;
	}

	/**
	 * @param   array<string, mixed>  $rules
	 */
	private function integer(string $name, array $rules, mixed $value): int
	{
		// JSON has one number type: 3.0 is an integer, "3" is not.
		if (\is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
			$value = (int) $value;
		}

		if (!\is_int($value)) {
			throw new ToolFailure(\sprintf('"%s" must be an integer.', $name));
		}

		$this->range($name, $rules, $value);

		return $value;
	}

	/**
	 * @param   array<string, mixed>  $rules
	 */
	private function number(string $name, array $rules, mixed $value): float
	{
		if (!\is_int($value) && !\is_float($value)) {
			throw new ToolFailure(\sprintf('"%s" must be a number.', $name));
		}

		$this->range($name, $rules, $value);

		return (float) $value;
	}

	/**
	 * @param   array<string, mixed>  $rules
	 */
	private function range(string $name, array $rules, int|float $value): void
	{
		$minimum = $rules['minimum'] ?? null;
		$maximum = $rules['maximum'] ?? null;

		if ((\is_int($minimum) || \is_float($minimum)) && $value < $minimum) {
			throw new ToolFailure(\sprintf('"%s" must be at least %s.', $name, $minimum));
		}

		if ((\is_int($maximum) || \is_float($maximum)) && $value > $maximum) {
			throw new ToolFailure(\sprintf('"%s" must be at most %s.', $name, $maximum));
		}
	}

	private function boolean(string $name, mixed $value): bool
	{
		if (!\is_bool($value)) {
			throw new ToolFailure(\sprintf('"%s" must be true or false.', $name));
		}

		return $value;
	}

	/**
	 * @param   array<string, mixed>  $rules
	 *
	 * @return  list<mixed>
	 */
	private function list(string $name, array $rules, mixed $value): array
	{
		if (!\is_array($value) || !array_is_list($value)) {
			throw new ToolFailure(\sprintf('"%s" must be an array.', $name));
		}

		if (\is_int($rules['maxItems'] ?? null) && \count($value) > $rules['maxItems']) {
			throw new ToolFailure(\sprintf('"%s" may have at most %d items.', $name, $rules['maxItems']));
		}

		/** @var array<string, mixed> $items */
		$items = \is_array($rules['items'] ?? null) ? $rules['items'] : ['type' => 'string'];

		return array_map(fn (mixed $item): mixed => $this->value($name . '[]', $items, $item), $value);
	}
}
