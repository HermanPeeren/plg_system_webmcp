<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\System\WebMcp\Shop;

use J2Commerce\Component\J2commerce\Administrator\Helper\CartOrder;
use J2Commerce\Component\J2commerce\Administrator\Helper\J2CommerceHelper;
use J2Commerce\Component\J2commerce\Administrator\Helper\ProductHelper;
use J2Commerce\Component\J2commerce\Administrator\Helper\ProductTypeHelper;
use J2Commerce\Component\J2commerce\Administrator\Model\CartModel;
use J2Commerce\Component\J2commerce\Site\Model\CartsModel;
use J2Commerce\Component\J2commerce\Site\Model\ProductsModel;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use Yepr\Plugin\System\WebMcp\Content\PlainText;
use Yepr\Plugin\System\WebMcp\Tool\ToolFailure;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The Shop for J2Commerce 6.
 *
 * Everything goes through J2Commerce's own models and helpers, the ones its pages use, so
 * an agent sees exactly what a visitor sees: the same visibility and access checks, the
 * same prices (special prices, currency), the same stock rules, and the same cart.
 *
 * J2Commerce's cart model reads the product, quantity and options from the request, as its
 * add-to-cart form posts them. So addToCart() puts the tool's input there first.
 *
 * @phpstan-import-type FilterGroup from Shop
 * @phpstan-import-type ProductSummary from Shop
 * @phpstan-import-type ProductDetail from Shop
 * @phpstan-import-type ProductOption from Shop
 * @phpstan-import-type Cart from Shop
 */
final class J2CommerceShop implements Shop
{
	private const DESCRIPTION_LENGTH = 2000;

	public function __construct(
		private readonly DatabaseInterface $db,
		private readonly CMSApplicationInterface $app
	) {
	}

	public function filterGroups(): array
	{
		$groups = [];

		foreach ($this->filterRows() as $row) {
			$groups[$row->group_name][] = $row->filter_name;
		}

		return array_map(
			static fn (string $group, array $values): array => ['group' => $group, 'values' => $values],
			array_keys($groups),
			array_values($groups)
		);
	}

	public function searchProducts(ProductQuery $query): array
	{
		$model = $this->productsModel();

		if ($query->words !== '') {
			$model->setState('filter.search', $query->words);
		}

		if ($query->properties !== []) {
			$model->setState('filter.productfilter_ids', $this->filterIds($query->properties));
		}

		if ($query->minPrice !== null || $query->maxPrice !== null) {
			$model->setState('filter.price_from', $query->minPrice ?? 0.0);
			$model->setState('filter.price_to', $query->maxPrice ?? 0.0);
		}

		[$ordering, $direction] = match ($query->sort) {
			'price-asc'  => ['v.price', 'ASC'],
			'price-desc' => ['v.price', 'DESC'],
			'name-asc'   => ['a.title', 'ASC'],
			'newest'     => ['a.created', 'DESC'],
			default      => ProductsModel::resolveMenuOrdering($model->getState('params')),
		};

		$model->setState('list.ordering', $ordering);
		$model->setState('list.direction', $direction);

		// Stock is checked per product below, so fetch more when only stock counts.
		$model->setState('list.start', 0);
		$model->setState('list.limit', $query->inStockOnly ? min(100, $query->limit * 4) : $query->limit);

		$products = [];

		foreach ($model->getItems() ?: [] as $item) {
			$product = $this->summary($item);

			if ($query->inStockOnly && !$product['in_stock']) {
				continue;
			}

			$products[] = $product;

			if (\count($products) >= $query->limit) {
				break;
			}
		}

		return $products;
	}

	public function product(int $id): ?array
	{
		if ($id < 1 || !$this->viewable($id)) {
			return null;
		}

		$product = ProductHelper::getFullProduct($id, true, true);

		if (!$product || (int) ($product->enabled ?? 0) !== 1) {
			return null;
		}

		$options = [];

		foreach ($product->options ?? [] as $option) {
			if (!\is_array($option['optionvalue'] ?? null)) {
				// Text, date and file options: not offered to agents in this prototype.
				continue;
			}

			$options[] = [
				'option_id' => (int) $option['productoption_id'],
				'name'      => (string) $option['option_name'],
				'required'  => (bool) $option['required'],
				'values'    => array_values(array_map(
					fn (array $value): array => [
						'value_id'     => (int) $value['product_optionvalue_id'],
						'name'         => (string) $value['optionvalue_name'],
						'price_change' => (float) $value['product_optionvalue_price'] > 0
							? $value['product_optionvalue_prefix'] . ' ' . $this->money((float) $value['product_optionvalue_price'])
							: '',
					],
					$option['optionvalue']
				)),
			];
		}

		return $this->summary($product) + [
			'description' => PlainText::fromHtml(
				(string) ($product->product_short_desc ?? '') . "\n" . (string) ($product->product_long_desc ?? ''),
				self::DESCRIPTION_LENGTH
			),
			'options'     => $options,
		];
	}

	public function addToCart(int $productId, int $quantity, array $options): string
	{
		if (!$this->viewable($productId)) {
			throw new ToolFailure(\sprintf('No product with id %d.', $productId));
		}

		// The cart's messages are J2Commerce language strings; com_ajax loads only its own.
		$language = $this->app->getLanguage();
		$language->load('com_j2commerce', JPATH_SITE) || $language->load('com_j2commerce', JPATH_SITE . '/components/com_j2commerce');

		$input = $this->app->getInput();
		$input->set('product_id', $productId);
		$input->set('product_qty', $quantity);
		$input->set('product_option', $options);
		$input->set('ajax', 1);

		$result = (array) $this->cartModel()->addCartItem();

		if (empty($result['success'])) {
			$error = $result['error'] ?? [];
			$text  = \is_array($error) ? self::errorText($error) : (string) $error;

			throw new ToolFailure($text !== '' ? $text : 'The product could not be added to the cart.');
		}

		return Text::_('COM_J2COMMERCE_ITEM_ADDED_TO_CART');
	}

	public function cart(): array
	{
		$cartModel = $this->cartModel();
		$items     = [];

		foreach ($cartModel->getItems(true) as $item) {
			$options = [];

			foreach ((array) ($item->options ?? []) as $option) {
				$option    = (array) $option;
				$name      = $option['name'] ?? $option['option_name'] ?? '';
				$value     = $option['option_value'] ?? $option['optionvalue_name'] ?? '';
				$options[] = trim($name . ': ' . $value, ': ');
			}

			$items[] = [
				'cart_item_id' => (int) $item->j2commerce_cartitem_id,
				'product_id'   => (int) $item->product_id,
				'name'         => (string) ($item->product_name ?? ''),
				'quantity'     => (float) $item->product_qty,
				'options'      => array_values(array_filter($options)),
				'line_total'   => '',
			];
		}

		$totals = [];
		$order  = $items === [] ? null : $this->model('Carts', 'Site', CartsModel::class)->getOrder();

		if ($order instanceof CartOrder) {
			foreach (array_values($order->getItems()) as $i => $orderItem) {
				if (isset($items[$i])) {
					$items[$i]['line_total'] = $this->money((float) $order->get_formatted_lineitem_total($orderItem, 0));
				}
			}

			foreach ($order->get_formatted_order_totals() as $key => $total) {
				$totals[(string) $key] = $this->plain((string) ($total['label'] ?? $key)) . ': ' . $this->plain((string) ($total['value'] ?? ''));
			}
		}

		return [
			'items'        => $items,
			'item_count'   => \count($items),
			'totals'       => $totals,
			'cart_url'     => $this->absolute($cartModel->getCartUrl()),
			'checkout_url' => $this->absolute($cartModel->getCheckoutUrl()),
		];
	}

	public function removeFromCart(int $cartItemId): void
	{
		$cartModel = $this->cartModel();
		$cart      = $cartModel->getCart(0, false);
		$cartId    = (int) ($cart->j2commerce_cart_id ?? 0);

		$factory = $this->factory();
		$table   = $factory->createTable('Cartitem', 'Administrator');

		// Only a line of this visitor's own cart.
		if (!$table->load($cartItemId) || (int) $table->cart_id !== $cartId || $cartId === 0) {
			throw new ToolFailure(\sprintf('There is no line %d in the cart.', $cartItemId));
		}

		$table->delete($cartItemId);
		J2CommerceHelper::plugin()->event('RemoveFromCart', [$table]);
	}

	public function checkoutUrl(): string
	{
		return $this->absolute($this->cartModel()->getCheckoutUrl());
	}

	/**
	 * @return  ProductSummary
	 */
	private function summary(object $product): array
	{
		$id      = (int) ($product->j2commerce_product_id ?? 0);
		$type    = (string) ($product->product_type ?? 'simple');
		$price   = (float) ($product->pricing->price ?? $product->variant->price ?? 0);
		// Types whose listing card says "choose options" instead of "add to cart".
		$variants = ProductTypeHelper::has($type, ProductTypeHelper::CHOOSE_OPTIONS);
		$options  = $variants || !empty($product->has_options);

		return [
			'id'              => $id,
			'name'            => (string) ($product->product_name ?? ''),
			'url'             => $this->absolute((string) ($product->product_view_url ?? '')),
			'price'           => round($price, 2),
			'price_formatted' => $this->money($price),
			'in_stock'        => $this->inStock($id, $product, $variants),
			'type'            => $type,
			'needs_options'   => $options,
			'properties'      => $this->properties($product),
		];
	}

	/**
	 * A simple product is in stock when its variant can be bought; a product with
	 * variants when one of them can.
	 */
	private function inStock(int $id, object $product, bool $hasVariants): bool
	{
		if (!$hasVariants) {
			return ProductHelper::isVariantPurchasable($product->variant ?? null);
		}

		$full = ProductHelper::getFullProduct($id, true, false);

		foreach ((array) ($full->variants ?? []) as $variant) {
			if (\is_object($variant) && empty($variant->is_master) && ProductHelper::isVariantPurchasable($variant)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return  array<string, list<string>>  Group => values, e.g. ['Colour' => ['Red']].
	 */
	private function properties(object $product): array
	{
		$properties = [];

		foreach ((array) ($product->productfilters ?? []) as $group) {
			$group  = (array) $group;
			$name   = (string) ($group['group_name'] ?? '');
			$values = [];

			foreach ((array) ($group['filters'] ?? []) as $filter) {
				$filter   = (array) $filter;
				$values[] = (string) ($filter['filter_name'] ?? '');
			}

			if ($name !== '' && $values !== []) {
				$properties[$name] = $values;
			}
		}

		return $properties;
	}

	/**
	 * Property names to J2Commerce filter ids, case-insensitively.
	 *
	 * @param   list<string>  $names
	 *
	 * @return  list<int>
	 */
	private function filterIds(array $names): array
	{
		$byName = [];

		foreach ($this->filterRows() as $row) {
			$byName[mb_strtolower($row->filter_name)][] = (int) $row->j2commerce_filter_id;
		}

		$ids = [];

		foreach ($names as $name) {
			$found = $byName[mb_strtolower(trim($name))] ?? null;

			if ($found === null) {
				throw new ToolFailure(\sprintf(
					'There is no property value "%s". Use list_product_filters to see the values, e.g. %s.',
					$name,
					implode(', ', \array_slice(array_map(static fn (object $r): string => $r->filter_name, $this->filterRows()), 0, 8))
				));
			}

			array_push($ids, ...$found);
		}

		return array_values(array_unique($ids));
	}

	/**
	 * @return  list<object{j2commerce_filter_id: string, filter_name: string, group_name: string}>
	 */
	private function filterRows(): array
	{
		static $rows = null;

		if ($rows !== null) {
			return $rows;
		}

		$db     = $this->db;
		$levels = array_map('intval', $this->app->getIdentity()?->getAuthorisedViewLevels() ?? [1]);
		$query  = $db->createQuery()
			->select($db->quoteName(['f.j2commerce_filter_id', 'f.filter_name', 'g.group_name']))
			->from($db->quoteName('#__j2commerce_filters', 'f'))
			->join(
				'INNER',
				$db->quoteName('#__j2commerce_filtergroups', 'g'),
				$db->quoteName('g.j2commerce_filtergroup_id') . ' = ' . $db->quoteName('f.group_id')
			)
			->where($db->quoteName('g.enabled') . ' = 1')
			->whereIn($db->quoteName('g.access'), $levels)
			->order([$db->quoteName('g.ordering'), $db->quoteName('f.ordering')]);

		/** @var list<object{j2commerce_filter_id: string, filter_name: string, group_name: string}> $rows */
		$rows = $db->setQuery($query)->loadObjectList();

		return $rows;
	}

	/**
	 * Whether the product is on the storefront for this visitor: the listing's own query,
	 * narrowed to one product.
	 */
	private function viewable(int $id): bool
	{
		$model = $this->productsModel();
		$model->setState('filter.product_ids', [$id]);
		$model->setState('list.start', 0);
		$model->setState('list.limit', 1);

		return \count($model->getItems() ?: []) === 1;
	}

	private function productsModel(): ProductsModel
	{
		$model = $this->model('Products', 'Site', ProductsModel::class);
		$model->getState();

		$params = $this->app instanceof SiteApplication ? $this->app->getParams('com_j2commerce') : new Registry();
		$model->setState('params', $params);
		$model->setState('filter.subcategory_levels', 10);

		return $model;
	}

	/**
	 * J2Commerce's administrator CartModel: the same one its own cart controller uses.
	 */
	private function cartModel(): CartModel
	{
		return $this->model('Cart', 'Administrator', CartModel::class);
	}

	/**
	 * A J2Commerce model, of the class the code below expects.
	 *
	 * @template T of object
	 *
	 * @param   class-string<T>  $class
	 *
	 * @return  T
	 */
	private function model(string $name, string $prefix, string $class): object
	{
		$model = $this->factory()->createModel($name, $prefix, ['ignore_request' => true]);

		if (!$model instanceof $class) {
			throw new \RuntimeException(\sprintf('J2Commerce has no %s model %s.', $prefix, $name));
		}

		return $model;
	}

	private function factory(): MVCFactoryInterface
	{
		$component = $this->app->bootComponent('com_j2commerce');

		if (!$component instanceof MVCFactoryServiceInterface) {
			throw new \RuntimeException('J2Commerce does not offer an MVC factory.');
		}

		return $component->getMVCFactory();
	}

	private function money(float $amount): string
	{
		return $this->plain(J2CommerceHelper::currency()->format($amount));
	}

	/**
	 * J2Commerce formats for HTML (an encoded currency symbol, sometimes a span).
	 */
	private function plain(string $html): string
	{
		return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	private function absolute(string $url): string
	{
		if ($url === '' || preg_match('#^https?://#', $url)) {
			return $url;
		}

		if (str_starts_with($url, 'index.php')) {
			return Route::link('site', $url, false, Route::TLS_IGNORE, true);
		}

		// A routed URL starts with the site's path (/plg_system_webmcp/joomla/...).
		$path = Uri::root(true);

		if ($path !== '' && str_starts_with($url, $path . '/')) {
			$url = substr($url, \strlen($path));
		}

		return rtrim(Uri::root(), '/') . '/' . ltrim($url, '/');
	}

	/**
	 * J2Commerce's error array ({general: "...", option: {12: "..."}}) as one sentence.
	 *
	 * @param   array<mixed>  $error
	 */
	private static function errorText(array $error): string
	{
		$parts = [];

		array_walk_recursive($error, static function (mixed $value) use (&$parts): void {
			if (\is_string($value) && trim($value) !== '') {
				$parts[] = trim(strip_tags(Text::_(trim($value))));
			}
		});

		return implode(' ', array_unique($parts));
	}
}
