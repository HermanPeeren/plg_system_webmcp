<?php

declare(strict_types=1);

namespace Yepr\Plugin\System\WebMcp\Tests\Unit\Shop;

use PHPUnit\Framework\TestCase;
use Yepr\Plugin\System\WebMcp\Shop\ShopTools;
use Yepr\Plugin\System\WebMcp\Tests\Support\FakeShop;
use Yepr\Plugin\System\WebMcp\Tool\InputValidator;
use Yepr\Plugin\System\WebMcp\Tool\Tool;
use Yepr\Plugin\System\WebMcp\Tool\ToolFailure;
use Yepr\Plugin\System\WebMcp\Tool\ToolRegistry;

final class ShopToolsTest extends TestCase
{
    private FakeShop $shop;

    private ToolRegistry $tools;

    protected function setUp(): void
    {
        $this->shop  = new FakeShop();
        $this->tools = new ToolRegistry();
        (new ShopTools($this->shop))->addTo($this->tools, 10);
    }

    /**
     * Validate the input against the tool's schema first, as the plugin does.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(string $name, array $input = []): array
    {
        $tool = $this->tools->get($name);
        self::assertInstanceOf(Tool::class, $tool);

        return $tool->execute((new InputValidator())->validate($tool->inputSchema(), $input));
    }

    public function testOffersTheShopTools(): void
    {
        self::assertSame(
            ['add_to_cart', 'get_cart', 'get_product', 'go_to_checkout', 'list_product_filters', 'remove_from_cart', 'search_products'],
            array_map(static fn (Tool $tool): string => $tool->name(), $this->tools->all())
        );
    }

    public function testThereIsNoToolThatPlacesAnOrder(): void
    {
        foreach ($this->tools->all() as $tool) {
            self::assertDoesNotMatchRegularExpression('/order|pay|purchase|buy/i', $tool->name());
        }
    }

    public function testOnlyTheReadingToolsAreReadOnlyAndCheckoutIsConsequential(): void
    {
        $readOnly = [];

        foreach ($this->tools->all() as $tool) {
            if ($tool->annotations()['readOnlyHint'] ?? false) {
                $readOnly[] = $tool->name();
            }
        }

        self::assertSame(['get_cart', 'get_product', 'list_product_filters', 'search_products'], $readOnly);
        self::assertTrue($this->tools->get('go_to_checkout')?->annotations()['consequentialHint']);
    }

    public function testSearchPassesPropertiesPriceAndStockToTheShop(): void
    {
        $result = $this->call('search_products', [
            'properties'    => ['Red', 'Outdoor'],
            'max_price'     => 50,
            'in_stock_only' => true,
            'sort'          => 'price-asc',
        ]);

        self::assertSame(2, $result['count']);
        self::assertSame(['Red', 'Outdoor'], $this->shop->lastQuery?->properties);
        self::assertSame(50.0, $this->shop->lastQuery->maxPrice);
        self::assertNull($this->shop->lastQuery->minPrice);
        self::assertTrue($this->shop->lastQuery->inStockOnly);
        self::assertSame('price-asc', $this->shop->lastQuery->sort);
        self::assertSame(10, $this->shop->lastQuery->limit);
    }

    public function testSearchRefusesAnUnknownSortOrder(): void
    {
        $this->expectException(ToolFailure::class);
        $this->call('search_products', ['sort' => 'cheapest-first']);
    }

    public function testAddToCartTurnsOptionPairsIntoIds(): void
    {
        $result = $this->call('add_to_cart', ['product_id' => 1, 'quantity' => 2, 'options' => ['5:21', '7:30']]);

        self::assertTrue($result['added']);
        self::assertSame([['product' => 1, 'quantity' => 2, 'options' => [5 => 21, 7 => 30]]], $this->shop->added);
        self::assertSame(1, $result['cart']['item_count']);
    }

    public function testAddToCartRefusesAMalformedOption(): void
    {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage('option_id:value_id');

        $this->call('add_to_cart', ['product_id' => 1, 'options' => ['Size: M']]);
    }

    public function testAddToCartPassesTheShopsReasonOn(): void
    {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage('Out of Stock');

        $this->call('add_to_cart', ['product_id' => 2]);
    }

    public function testAddToCartLimitsTheQuantity(): void
    {
        $this->expectException(ToolFailure::class);
        $this->call('add_to_cart', ['product_id' => 1, 'quantity' => 1000]);
    }

    public function testGetProductSaysSoWhenThereIsNone(): void
    {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage('No product with id 9.');

        $this->call('get_product', ['id' => 9]);
    }

    public function testCheckoutNeedsAFullCart(): void
    {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage('The cart is empty');

        $this->call('go_to_checkout');
    }

    public function testCheckoutSendsTheVisitorToTheCheckoutPage(): void
    {
        $this->call('add_to_cart', ['product_id' => 1]);
        $result = $this->call('go_to_checkout');

        self::assertSame('https://shop.test/checkout', $result['navigate_to']);
        self::assertStringContainsString('place the order themselves', $result['message']);
    }

    public function testToolsWithoutInputHaveAnEmptyObjectSchema(): void
    {
        $schema = $this->tools->get('get_cart')?->inputSchema();

        self::assertSame('{"type":"object","properties":{},"additionalProperties":false}', json_encode($schema));
    }

    public function testEveryToolHasItsTitleAndDescriptionInTheLanguageFile(): void
    {
        $strings = parse_ini_file(\dirname(__DIR__, 3) . '/src/plugins/system/webmcp/language/en-GB/plg_system_webmcp.ini');
        self::assertIsArray($strings);

        foreach ($this->tools->all() as $tool) {
            self::assertArrayHasKey($tool->title(), $strings);
            self::assertArrayHasKey($tool->description(), $strings);
        }
    }
}
