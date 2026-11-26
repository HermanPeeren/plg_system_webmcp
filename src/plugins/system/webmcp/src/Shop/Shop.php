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
 * A shop as the tools see it: products with properties, and the visitor's cart.
 *
 * One implementation, J2CommerceShop. Another shop extension (VirtueMart, HikaShop,
 * Phoca Cart, EasyStore) would need only another implementation of this interface;
 * the tools, their descriptions and schemas stay the same.
 *
 * @phpstan-type FilterGroup array{group: string, values: list<string>}
 * @phpstan-type ProductSummary array{
 *     id: int, name: string, url: string, price: float, price_formatted: string,
 *     in_stock: bool, type: string, needs_options: bool, properties: array<string, list<string>>
 * }
 * @phpstan-type ProductOption array{
 *     option_id: int, name: string, required: bool,
 *     values: list<array{value_id: int, name: string, price_change: string}>
 * }
 * @phpstan-type ProductDetail array{
 *     id: int, name: string, url: string, price: float, price_formatted: string,
 *     in_stock: bool, type: string, needs_options: bool, properties: array<string, list<string>>,
 *     description: string, options: list<ProductOption>
 * }
 * @phpstan-type CartItem array{
 *     cart_item_id: int, product_id: int, name: string, quantity: float, options: list<string>, line_total: string
 * }
 * @phpstan-type Cart array{
 *     items: list<CartItem>, item_count: int, totals: array<string, string>, cart_url: string, checkout_url: string
 * }
 */
interface Shop
{
	/**
	 * The properties products can be filtered on, with their values.
	 *
	 * @return  list<FilterGroup>
	 */
	public function filterGroups(): array;

	/**
	 * @return  list<ProductSummary>
	 */
	public function searchProducts(ProductQuery $query): array;

	/**
	 * @return  ProductDetail|null  Null when there is no such product the visitor may see.
	 */
	public function product(int $id): ?array;

	/**
	 * @param   array<int, int>  $options  Option id => value id, as product() lists them.
	 *
	 * @return  string  The shop's confirmation message.
	 *
	 * @throws  \Yepr\Plugin\System\WebMcp\Tool\ToolFailure  With the shop's reason: out of stock, option missing.
	 */
	public function addToCart(int $productId, int $quantity, array $options): string;

	/**
	 * @return  Cart
	 */
	public function cart(): array;

	/**
	 * @throws  \Yepr\Plugin\System\WebMcp\Tool\ToolFailure  When the item is not in this visitor's cart.
	 */
	public function removeFromCart(int $cartItemId): void;

	public function checkoutUrl(): string;
}
