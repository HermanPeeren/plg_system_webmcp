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
 * The tools offered on one request, by name.
 */
final class ToolRegistry
{
	/**
	 * The name rule of the WebMCP specification.
	 */
	private const NAME_PATTERN = '/^[A-Za-z0-9_.\-]{1,128}$/';

	/**
	 * @var array<string, Tool>
	 */
	private array $tools = [];

	/**
	 * @throws  \InvalidArgumentException  For an invalid or duplicate name: the browser
	 *                                     would refuse to register it.
	 */
	public function add(Tool $tool): void
	{
		$name = $tool->name();

		if (!preg_match(self::NAME_PATTERN, $name)) {
			throw new \InvalidArgumentException(\sprintf('Invalid WebMCP tool name "%s".', $name));
		}

		if (isset($this->tools[$name])) {
			throw new \InvalidArgumentException(\sprintf('A WebMCP tool "%s" is already registered.', $name));
		}

		$this->tools[$name] = $tool;
	}

	public function get(string $name): ?Tool
	{
		return $this->tools[$name] ?? null;
	}

	/**
	 * @return  list<Tool>  Sorted by name.
	 */
	public function all(): array
	{
		$tools = $this->tools;
		ksort($tools);

		return array_values($tools);
	}
}
