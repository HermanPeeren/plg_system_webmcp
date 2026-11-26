<?php

declare(strict_types=1);

namespace Yepr\Plugin\System\WebMcp\Tests\Support;

use Yepr\Plugin\System\WebMcp\Tool\Tool;

/**
 * A tool that does nothing, for the registry.
 */
final class FakeTool implements Tool
{
    public function __construct(private readonly string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function title(): string
    {
        return 'TITLE';
    }

    public function description(): string
    {
        return 'DESC';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true];
    }

    public function execute(array $input): array
    {
        return [];
    }
}
