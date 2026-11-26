<?php

declare(strict_types=1);

namespace Yepr\Plugin\System\WebMcp\Tests\Support;

use Yepr\Plugin\System\WebMcp\Shop\ProductQuery;
use Yepr\Plugin\System\WebMcp\Shop\Shop;
use Yepr\Plugin\System\WebMcp\Tool\ToolFailure;

/**
 * A shop with two products and a cart in memory, that records what the tools asked.
 *
 * @phpstan-import-type CartItem from Shop
 * @phpstan-import-type ProductSummary from Shop
 */
final class FakeShop implements Shop
{
    public ?ProductQuery $lastQuery = null;

    /**
     * @var list<array{product: int, quantity: int, options: array<int, int>}>
     */
    public array $added = [];

    /**
     * @var list<CartItem>
     */
    private array $lines = [];

    public function filterGroups(): array
    {
        return [['group' => 'Colour', 'values' => ['Red', 'Blue']]];
    }

    public function searchProducts(ProductQuery $query): array
    {
        $this->lastQuery = $query;

        return [$this->summary(1, 'Red shirt'), $this->summary(2, 'Blue shirt')];
    }

    public function product(int $id): ?array
    {
        if ($id !== 1) {
            return null;
        }

        return $this->summary(1, 'Red shirt') + [
            'description' => 'A shirt.',
            'options'     => [[
                'option_id' => 5,
                'name'      => 'Size',
                'required'  => true,
                'values'    => [['value_id' => 21, 'name' => 'M', 'price_change' => '']],
            ]],
        ];
    }

    public function addToCart(int $productId, int $quantity, array $options): string
    {
        if ($productId === 2) {
            throw new ToolFailure('Out of Stock');
        }

        $this->added[] = ['product' => $productId, 'quantity' => $quantity, 'options' => $options];
        $this->lines[] = [
            'cart_item_id' => \count($this->lines) + 1,
            'product_id'   => $productId,
            'name'         => 'Red shirt',
            'quantity'     => (float) $quantity,
            'options'      => [],
            'line_total'   => '$10.00',
        ];

        return 'Item added to basket.';
    }

    public function cart(): array
    {
        return [
            'items'        => $this->lines,
            'item_count'   => \count($this->lines),
            'totals'       => $this->lines === [] ? [] : ['grandtotal' => 'Total: $10.00'],
            'cart_url'     => 'https://shop.test/cart',
            'checkout_url' => 'https://shop.test/checkout',
        ];
    }

    public function removeFromCart(int $cartItemId): void
    {
        throw new ToolFailure(\sprintf('There is no line %d in the cart.', $cartItemId));
    }

    public function checkoutUrl(): string
    {
        return 'https://shop.test/checkout';
    }

    /**
     * @return ProductSummary
     */
    private function summary(int $id, string $name): array
    {
        return [
            'id'              => $id,
            'name'            => $name,
            'url'             => 'https://shop.test/p/' . $id,
            'price'           => 10.0,
            'price_formatted' => '$10.00',
            'in_stock'        => $id === 1,
            'type'            => 'simple',
            'needs_options'   => false,
            'properties'      => ['Colour' => [$id === 1 ? 'Red' : 'Blue']],
        ];
    }
}
