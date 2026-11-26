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
 * A tool made of its definition and a closure: for a set of small tools that share one
 * service, like the shop tools, where a class per tool would only repeat the same
 * constructor.
 */
final class CallbackTool implements Tool
{
	/**
	 * @param   array<string, mixed>                                    $inputSchema
	 * @param   array<string, bool>                                     $annotations
	 * @param   \Closure(array<string, mixed>): array<string, mixed>    $callback
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $languagePrefix,
		private readonly array $inputSchema,
		private readonly array $annotations,
		private readonly \Closure $callback
	) {
	}

	public function name(): string
	{
		return $this->name;
	}

	public function title(): string
	{
		return $this->languagePrefix . '_TITLE';
	}

	public function description(): string
	{
		return $this->languagePrefix . '_DESC';
	}

	public function inputSchema(): array
	{
		return $this->inputSchema;
	}

	public function annotations(): array
	{
		return $this->annotations;
	}

	public function execute(array $input): array
	{
		return ($this->callback)($input);
	}
}
