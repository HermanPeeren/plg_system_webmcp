<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\System\WebMcp\Shop;

use Yepr\Plugin\System\WebMcp\Tool\CallbackTool;
use Yepr\Plugin\System\WebMcp\Tool\ToolFailure;
use Yepr\Plugin\System\WebMcp\Tool\ToolRegistry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The shop tools:
 *
 * | Tool                  | Does                                              | Annotation       |
 * |-----------------------|---------------------------------------------------|------------------|
 * | list_product_filters  | the properties (colour, material…) and values     | read-only        |
 * | search_products       | products by words, properties, price, stock       | read-only        |
 * | get_product           | one product, with the options to choose from      | read-only        |
 * | add_to_cart           | a product into the visitor's cart                 | changes the cart |
 * | get_cart              | what is in the cart, and the totals               | read-only        |
 * | remove_from_cart      | one line out of the cart                          | changes the cart |
 * | go_to_checkout        | takes the visitor to the checkout page            | consequential    |
 *
 * There is deliberately no "place order" tool: paying is for the visitor, on the
 * checkout page, where they see the address, the shipping costs and the total. The
 * agent fills the cart; the person decides.
 */
final class ShopTools
{
	private const PREFIX = 'PLG_SYSTEM_WEBMCP_TOOL_';

	public function __construct(private readonly Shop $shop)
	{
	}

	public function addTo(ToolRegistry $registry, int $maxResults): void
	{
		$shop = $this->shop;

		$registry->add(new CallbackTool(
			'list_product_filters',
			self::PREFIX . 'LIST_PRODUCT_FILTERS',
			['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
			['readOnlyHint' => true],
			static fn (): array => ['properties' => $shop->filterGroups()]
		));

		$registry->add(new CallbackTool(
			'search_products',
			self::PREFIX . 'SEARCH_PRODUCTS',
			[
				'type'                 => 'object',
				'properties'           => [
					'query'         => [
						'type'        => 'string',
						'description' => 'Words in the product name, description or SKU. Optional.',
						'maxLength'   => 100,
					],
					'properties'    => [
						'type'        => 'array',
						'description' => 'Property values the products must have, such as "Red" or "Outdoor". '
							. 'Values of one property are alternatives (red OR blue); different properties must all match '
							. '(red AND outdoor). See list_product_filters for the values.',
						'items'       => ['type' => 'string', 'maxLength' => 50],
						'maxItems'    => 10,
					],
					'min_price'     => ['type' => 'number', 'description' => 'Lowest price, in the shop currency.', 'minimum' => 0],
					'max_price'     => ['type' => 'number', 'description' => 'Highest price, in the shop currency.', 'minimum' => 0],
					'in_stock_only' => ['type' => 'boolean', 'description' => 'Only products that can be ordered now.', 'default' => false],
					'sort'          => [
						'type'    => 'string',
						'enum'    => ['default', 'price-asc', 'price-desc', 'name-asc', 'newest'],
						'default' => 'default',
					],
					'limit'         => ['type' => 'integer', 'minimum' => 1, 'maximum' => $maxResults, 'default' => $maxResults],
				],
				'additionalProperties' => false,
			],
			['readOnlyHint' => true],
			static function (array $input) use ($shop): array {
				$products = $shop->searchProducts(new ProductQuery(
					\is_string($input['query'] ?? null) ? $input['query'] : '',
					array_values(array_filter($input['properties'] ?? [], 'is_string')),
					\is_float($input['min_price'] ?? null) ? $input['min_price'] : null,
					\is_float($input['max_price'] ?? null) ? $input['max_price'] : null,
					($input['in_stock_only'] ?? false) === true,
					\is_string($input['sort'] ?? null) ? $input['sort'] : 'default',
					\is_int($input['limit'] ?? null) ? $input['limit'] : 10
				));

				return [
					'count'    => \count($products),
					'products' => $products,
					'hint'     => 'Products with needs_options = true need get_product first, to choose the options for add_to_cart.',
				];
			}
		));

		$registry->add(new CallbackTool(
			'get_product',
			self::PREFIX . 'GET_PRODUCT',
			self::idSchema('id', 'The product id, as returned by search_products.'),
			['readOnlyHint' => true, 'untrustedContentHint' => true],
			static function (array $input) use ($shop): array {
				$id      = \is_int($input['id'] ?? null) ? $input['id'] : 0;
				$product = $shop->product($id);

				if ($product === null) {
					throw new ToolFailure(\sprintf('No product with id %d.', $id));
				}

				return $product;
			}
		));

		$registry->add(new CallbackTool(
			'add_to_cart',
			self::PREFIX . 'ADD_TO_CART',
			[
				'type'                 => 'object',
				'properties'           => [
					'product_id' => ['type' => 'integer', 'description' => 'The product id.', 'minimum' => 1],
					'quantity'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99, 'default' => 1],
					'options'    => [
						'type'        => 'array',
						'description' => 'The chosen options, as "option_id:value_id" strings from get_product, e.g. ["12:40", "13:44"].',
						'items'       => ['type' => 'string', 'maxLength' => 23],
						'maxItems'    => 20,
					],
				],
				'required'             => ['product_id'],
				'additionalProperties' => false,
			],
			['readOnlyHint' => false],
			static function (array $input) use ($shop): array {
				$message = $shop->addToCart(
					\is_int($input['product_id'] ?? null) ? $input['product_id'] : 0,
					\is_int($input['quantity'] ?? null) ? $input['quantity'] : 1,
					self::options($input['options'] ?? [])
				);

				return ['added' => true, 'message' => $message, 'cart' => $shop->cart()];
			}
		));

		$registry->add(new CallbackTool(
			'get_cart',
			self::PREFIX . 'GET_CART',
			['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
			['readOnlyHint' => true],
			static fn (): array => $shop->cart()
		));

		$registry->add(new CallbackTool(
			'remove_from_cart',
			self::PREFIX . 'REMOVE_FROM_CART',
			self::idSchema('cart_item_id', 'The cart line to remove, as get_cart lists it.'),
			['readOnlyHint' => false],
			static function (array $input) use ($shop): array {
				$shop->removeFromCart(\is_int($input['cart_item_id'] ?? null) ? $input['cart_item_id'] : 0);

				return ['removed' => true, 'cart' => $shop->cart()];
			}
		));

		$registry->add(new CallbackTool(
			'go_to_checkout',
			self::PREFIX . 'GO_TO_CHECKOUT',
			['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
			['readOnlyHint' => false, 'consequentialHint' => true],
			static function () use ($shop): array {
				$cart = $shop->cart();

				if ($cart['items'] === []) {
					throw new ToolFailure('The cart is empty. Add a product first.');
				}

				return [
					'navigate_to' => $cart['checkout_url'],
					'message'     => 'The visitor is taken to the checkout page. They enter their address, choose shipping and '
						. 'payment, and place the order themselves.',
					'totals'      => $cart['totals'],
				];
			}
		));
	}

	/**
	 * @return  array<string, mixed>
	 */
	private static function idSchema(string $name, string $description): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [$name => ['type' => 'integer', 'description' => $description, 'minimum' => 1]],
			'required'             => [$name],
			'additionalProperties' => false,
		];
	}

	/**
	 * "12:40" pairs into [12 => 40].
	 *
	 * @return  array<int, int>
	 */
	private static function options(mixed $pairs): array
	{
		$options = [];

		foreach (\is_array($pairs) ? $pairs : [] as $pair) {
			if (!\is_string($pair) || !preg_match('/^(\d{1,10}):(\d{1,10})$/', $pair, $match)) {
				throw new ToolFailure('Each option must be "option_id:value_id", e.g. "12:40".');
			}

			$options[(int) $match[1]] = (int) $match[2];
		}

		return $options;
	}
}
