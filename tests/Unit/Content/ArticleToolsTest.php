<?php

declare(strict_types=1);

namespace Yepr\Plugin\System\WebMcp\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Yepr\Plugin\System\WebMcp\Content\GetArticleTool;
use Yepr\Plugin\System\WebMcp\Content\PlainText;
use Yepr\Plugin\System\WebMcp\Content\SearchArticlesTool;
use Yepr\Plugin\System\WebMcp\Tests\Support\InMemoryArticles;
use Yepr\Plugin\System\WebMcp\Tool\InputValidator;
use Yepr\Plugin\System\WebMcp\Tool\ToolFailure;

final class ArticleToolsTest extends TestCase
{
    private InMemoryArticles $articles;

    protected function setUp(): void
    {
        $this->articles = new InMemoryArticles([
            [
                'id'       => 1,
                'title'    => 'Balloon festival',
                'url'      => 'https://example.test/balloon-festival',
                'category' => 'News',
                'date'     => '2026-11-01',
                'author'   => 'Editor',
                'text'     => 'Hot air balloons over the city.',
            ],
            [
                'id'       => 2,
                'title'    => 'Long read',
                'url'      => 'https://example.test/long-read',
                'category' => 'News',
                'date'     => '2026-10-01',
                'author'   => 'Editor',
                'text'     => str_repeat('word ', 5000),
            ],
        ]);
    }

    public function testSearchUsesTheDefaultLimitAndSaysWhatToDoNext(): void
    {
        $tool   = new SearchArticlesTool($this->articles, 10);
        $input  = (new InputValidator())->validate($tool->inputSchema(), ['query' => 'balloon']);
        $result = $tool->execute($input);

        self::assertSame(1, $result['count']);
        self::assertSame(5, $this->articles->searches[0]['limit']);
        self::assertStringContainsString('get_article', $result['hint']);
    }

    public function testSearchLimitCannotExceedThePluginsMaximum(): void
    {
        $tool = new SearchArticlesTool($this->articles, 3);

        self::assertSame(3, $tool->inputSchema()['properties']['limit']['maximum']);
        self::assertSame(3, $tool->inputSchema()['properties']['limit']['default']);
    }

    public function testSearchSaysSoWhenNothingIsFound(): void
    {
        $result = (new SearchArticlesTool($this->articles, 10))->execute(['query' => 'zeppelin', 'limit' => 5]);

        self::assertSame(0, $result['count']);
        self::assertStringContainsString('Nothing found', $result['hint']);
    }

    public function testGetArticleReturnsTheText(): void
    {
        $result = (new GetArticleTool($this->articles))->execute(['id' => 1]);

        self::assertSame('Balloon festival', $result['title']);
        self::assertFalse($result['truncated']);
    }

    public function testGetArticleCutsAVeryLongText(): void
    {
        $result = (new GetArticleTool($this->articles))->execute(['id' => 2]);

        self::assertTrue($result['truncated']);
        self::assertLessThanOrEqual(20001, mb_strlen($result['text']));
    }

    public function testGetArticleGivesOneAnswerForMissingAndForbidden(): void
    {
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage('No article with id 99.');

        (new GetArticleTool($this->articles))->execute(['id' => 99]);
    }

    public function testArticleToolsAreReadOnlyAndFlagTheirContentAsUntrusted(): void
    {
        foreach ([new SearchArticlesTool($this->articles, 10), new GetArticleTool($this->articles)] as $tool) {
            self::assertTrue($tool->annotations()['readOnlyHint']);
            self::assertTrue($tool->annotations()['untrustedContentHint']);
        }
    }

    public function testPlainTextDropsMarkupScriptsAndPluginCodes(): void
    {
        $html = '<p>Hello&nbsp;<b>world</b></p><script>alert(1)</script>{loadmodule mod_menu}<ul><li>One</li><li>Two</li></ul>';

        self::assertSame("Hello world\nOne\nTwo", PlainText::fromHtml($html));
    }

    public function testPlainTextCutsAtAWord(): void
    {
        self::assertSame('The quick brown…', PlainText::cut('The quick brown fox jumps', 18));
    }
}
