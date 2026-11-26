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
 * One WebMCP tool: what the page registers with `document.modelContext.registerTool()`,
 * and what runs on the server when an agent calls it.
 *
 * The definition (name, title, description, input schema, annotations) goes to the
 * browser as script options; the browser's JavaScript only forwards a call to the
 * server. So a tool is written once, in PHP, and runs with the visitor's own session,
 * access levels and language.
 *
 * Other extensions can add tools: see {@see \Yepr\Plugin\System\WebMcp\Event\CollectToolsEvent}.
 */
interface Tool
{
	/**
	 * The tool's name: 1 to 128 characters, ASCII letters, digits, `_`, `-` and `.`.
	 */
	public function name(): string;

	/**
	 * A language key for a short, human-readable title.
	 */
	public function title(): string;

	/**
	 * A language key for the description the agent reads to decide when and how to
	 * call the tool. Say what it does, what it returns, and when not to use it.
	 */
	public function description(): string;

	/**
	 * The JSON Schema of the input: an object with properties. Only the subset that
	 * {@see InputValidator} checks is used.
	 *
	 * @return  array<string, mixed>
	 */
	public function inputSchema(): array;

	/**
	 * WebMCP tool annotations: readOnlyHint, consequentialHint, untrustedContentHint.
	 *
	 * @return  array<string, bool>
	 */
	public function annotations(): array;

	/**
	 * Run the tool. The input has been validated against the input schema, with the
	 * schema's defaults filled in.
	 *
	 * @param   array<string, mixed>  $input
	 *
	 * @return  array<string, mixed>  JSON-serialisable; the agent gets it as is.
	 *
	 * @throws  ToolFailure  With a message for the agent, e.g. "No article with id 12".
	 */
	public function execute(array $input): array;
}
