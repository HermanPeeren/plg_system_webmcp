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
 * A tool call that cannot be done, with a message the agent may read: invalid input,
 * nothing found, out of stock. The message must not contain anything the visitor may
 * not see; other exceptions are logged and the agent gets a generic message.
 */
final class ToolFailure extends \RuntimeException
{
}
