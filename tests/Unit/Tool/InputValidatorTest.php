<?php

declare(strict_types=1);

namespace Yepr\Plugin\System\WebMcp\Tests\Unit\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yepr\Plugin\System\WebMcp\Tool\InputValidator;
use Yepr\Plugin\System\WebMcp\Tool\ToolFailure;

final class InputValidatorTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private const SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'query'    => ['type' => 'string', 'minLength' => 2, 'maxLength' => 10],
            'limit'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5],
            'maxPrice' => ['type' => 'number', 'minimum' => 0],
            'inStock'  => ['type' => 'boolean'],
            'sort'     => ['type' => 'string', 'enum' => ['price', 'name']],
            'colours'  => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 20], 'maxItems' => 3],
        ],
        'required'   => ['query'],
    ];

    public function testFillsInDefaultsAndTrimsStrings(): void
    {
        $result = (new InputValidator())->validate(self::SCHEMA, ['query' => '  red  ']);

        self::assertSame(['query' => 'red', 'limit' => 5], $result);
    }

    public function testAcceptsAWholeFloatAsAnInteger(): void
    {
        $result = (new InputValidator())->validate(self::SCHEMA, ['query' => 'red', 'limit' => 3.0, 'maxPrice' => 10]);

        self::assertSame(3, $result['limit']);
        self::assertSame(10.0, $result['maxPrice']);
    }

    public function testAcceptsAListOfStrings(): void
    {
        $result = (new InputValidator())->validate(self::SCHEMA, ['query' => 'shirt', 'colours' => ['red', 'blue']]);

        self::assertSame(['red', 'blue'], $result['colours']);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'not an object' => [['a', 'b'], 'must be a JSON object'];
        yield 'a string' => ['red', 'must be a JSON object'];
        yield 'missing required' => [['limit' => 3], '"query" is required'];
        yield 'unknown property' => [['query' => 'red', 'admin' => true], 'Unknown input property "admin"'];
        yield 'too short' => [['query' => 'r'], 'at least 2 characters'];
        yield 'too long' => [['query' => str_repeat('a', 11)], 'at most 10 characters'];
        yield 'integer as string' => [['query' => 'red', 'limit' => '3'], '"limit" must be an integer'];
        yield 'fraction' => [['query' => 'red', 'limit' => 2.5], '"limit" must be an integer'];
        yield 'below minimum' => [['query' => 'red', 'limit' => 0], 'at least 1'];
        yield 'above maximum' => [['query' => 'red', 'limit' => 21], 'at most 20'];
        yield 'not a boolean' => [['query' => 'red', 'inStock' => 1], 'true or false'];
        yield 'not in enum' => [['query' => 'red', 'sort' => 'random'], 'must be one of: price, name'];
        yield 'too many items' => [['query' => 'red', 'colours' => ['a', 'b', 'c', 'd']], 'at most 3 items'];
        yield 'item of wrong type' => [['query' => 'red', 'colours' => [1]], '"colours[]" must be a string'];
        yield 'array as object' => [['query' => 'red', 'colours' => ['x' => 'red']], '"colours" must be an array'];
    }

    #[DataProvider('invalidInput')]
    public function testRefusesInvalidInput(mixed $input, string $message): void
    {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage($message);

        (new InputValidator())->validate(self::SCHEMA, $input);
    }

    public function testTreatsNoInputAsAnEmptyObject(): void
    {
        $schema = ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'default' => 5]]];

        self::assertSame(['limit' => 5], (new InputValidator())->validate($schema, null));
        self::assertSame(['limit' => 5], (new InputValidator())->validate($schema, []));
    }
}
