<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\System\WebMcp\Content;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The articles the current visitor may read: published, within their publishing dates,
 * in a published category, at one of the visitor's access levels, in the visitor's
 * language or "All".
 *
 * @phpstan-type ArticleSummary array{id: int, title: string, url: string, category: string, date: string, summary: string}
 * @phpstan-type Article array{id: int, title: string, url: string, category: string, date: string, author: string, text: string}
 */
interface ArticleRepository
{
	/**
	 * Articles whose title or text contains all the words, newest first.
	 *
	 * @return  list<ArticleSummary>
	 */
	public function search(string $words, ?int $categoryId, int $limit): array;

	/**
	 * @return  Article|null
	 */
	public function find(int $id): ?array;
}
