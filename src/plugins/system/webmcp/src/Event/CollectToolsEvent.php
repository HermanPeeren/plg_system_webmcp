<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\System\WebMcp\Event;

use Joomla\Event\Event;
use Yepr\Plugin\System\WebMcp\Tool\Tool;
use Yepr\Plugin\System\WebMcp\Tool\ToolRegistry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * `onWebMcpCollectTools`: lets any other plugin offer its own WebMCP tools.
 *
 * ```php
 * public static function getSubscribedEvents(): array
 * {
 *     return ['onWebMcpCollectTools' => 'addTools'];
 * }
 *
 * public function addTools(CollectToolsEvent $event): void
 * {
 *     $event->addTool(new MyEventsTool(...));
 * }
 * ```
 *
 * The event is dispatched on every page that offers tools and on every tool call, so
 * add tools cheaply: build them, don't query anything until execute().
 */
final class CollectToolsEvent extends Event
{
	public const NAME = 'onWebMcpCollectTools';

	public function __construct(private readonly ToolRegistry $registry)
	{
		parent::__construct(self::NAME);
	}

	public function addTool(Tool $tool): void
	{
		$this->registry->add($tool);
	}
}
