<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\System\WebMcp\Shop;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * What search_products asks for. Property values are names ("Red", "Outdoor"), the way
 * an agent says them; the shop turns them into its own filter ids.
 */
final class ProductQuery
{
	/**
	 * @param   list<string>  $properties
	 */
	public function __construct(
		public readonly string $words = '',
		public readonly array $properties = [],
		public readonly ?float $minPrice = null,
		public readonly ?float $maxPrice = null,
		public readonly bool $inStockOnly = false,
		public readonly string $sort = 'default',
		public readonly int $limit = 10
	) {
	}
}
