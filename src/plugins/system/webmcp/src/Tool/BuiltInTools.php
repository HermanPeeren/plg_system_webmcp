<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\System\WebMcp\Tool;

use Joomla\Registry\Registry;
use Yepr\Plugin\System\WebMcp\Content\ArticleRepository;
use Yepr\Plugin\System\WebMcp\Content\GetArticleTool;
use Yepr\Plugin\System\WebMcp\Content\SearchArticlesTool;
use Yepr\Plugin\System\WebMcp\Shop\ShopTools;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The plugin's own tools, as far as the plugin's options switch them on.
 *
 * The repositories come in as closures: they need the visitor (user, language), which
 * the application knows only once the request runs, and a page that offers no tool
 * should not build them.
 */
final class BuiltInTools
{
	/**
	 * @param   \Closure(): ArticleRepository  $articles
	 * @param   \Closure(): ?ShopTools         $shop      Null when J2Commerce is not installed and enabled.
	 */
	public function __construct(private readonly \Closure $articles, private readonly \Closure $shop)
	{
	}

	public function addTo(ToolRegistry $registry, Registry $params): void
	{
		$maxResults = max(1, min(50, (int) $params->get('max_results', 10)));

		if ($params->get('article_tools', 1)) {
			$articles = ($this->articles)();
			$registry->add(new SearchArticlesTool($articles, $maxResults));
			$registry->add(new GetArticleTool($articles));
		}

		if ($params->get('shop_tools', 1)) {
			($this->shop)()?->addTo($registry, $maxResults);
		}
	}
}
