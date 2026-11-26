<?php

declare(strict_types=1);

namespace Yepr\Plugin\System\WebMcp\Tests\Support;

use Yepr\Plugin\System\WebMcp\Content\ArticleRepository;

/**
 * Articles in an array, already filtered for the visitor.
 *
 * @phpstan-import-type Article from ArticleRepository
 */
final class InMemoryArticles implements ArticleRepository
{
    /**
     * @var list<array{words: string, limit: int, category: ?int}>
     */
    public array $searches = [];

    /**
     * @param list<Article> $articles
     */
    public function __construct(private readonly array $articles)
    {
    }

    public function search(string $words, ?int $categoryId, int $limit): array
    {
        $this->searches[] = ['words' => $words, 'limit' => $limit, 'category' => $categoryId];

        $found = array_filter(
            $this->articles,
            static fn (array $a): bool => stripos($a['title'] . ' ' . $a['text'], $words) !== false
        );

        return array_values(array_map(static fn (array $a): array => [
            'id'       => $a['id'],
            'title'    => $a['title'],
            'url'      => $a['url'],
            'category' => $a['category'],
            'date'     => $a['date'],
            'summary'  => mb_substr($a['text'], 0, 50),
        ], \array_slice($found, 0, $limit)));
    }

    public function find(int $id): ?array
    {
        foreach ($this->articles as $article) {
            if ($article['id'] === $id) {
                return $article;
            }
        }

        return null;
    }
}
