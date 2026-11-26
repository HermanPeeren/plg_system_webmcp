<?php

declare(strict_types=1);

namespace Yepr\Plugin\System\WebMcp\Tests\Unit\Tool;

use PHPUnit\Framework\TestCase;
use Yepr\Plugin\System\WebMcp\Tests\Support\FakeTool;
use Yepr\Plugin\System\WebMcp\Tool\ToolRegistry;

final class ToolRegistryTest extends TestCase
{
    public function testListsToolsByName(): void
    {
        $registry = new ToolRegistry();
        $registry->add(new FakeTool('search_products'));
        $registry->add(new FakeTool('add_to_cart'));

        self::assertSame(
            ['add_to_cart', 'search_products'],
            array_map(static fn ($tool): string => $tool->name(), $registry->all())
        );
        self::assertSame('add_to_cart', $registry->get('add_to_cart')?->name());
        self::assertNull($registry->get('place_order'));
    }

    public function testRefusesADuplicateName(): void
    {
        $registry = new ToolRegistry();
        $registry->add(new FakeTool('get_cart'));

        $this->expectException(\InvalidArgumentException::class);
        $registry->add(new FakeTool('get_cart'));
    }

    public function testRefusesANameTheBrowserWouldRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ToolRegistry())->add(new FakeTool('add to cart'));
    }
}
