<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\System\WebMcp\Content;

use Yepr\Plugin\System\WebMcp\Tool\Tool;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * `search_articles`: find articles on this site by words in their title or text.
 */
final class SearchArticlesTool implements Tool
{
	public function __construct(private readonly ArticleRepository $articles, private readonly int $maxResults)
	{
	}

	public function name(): string
	{
		return 'search_articles';
	}

	public function title(): string
	{
		return 'PLG_SYSTEM_WEBMCP_TOOL_SEARCH_ARTICLES_TITLE';
	}

	public function description(): string
	{
		return 'PLG_SYSTEM_WEBMCP_TOOL_SEARCH_ARTICLES_DESC';
	}

	public function inputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'query'       => [
					'type'        => 'string',
					'description' => 'Words to search for in the title and text. All words must occur.',
					'minLength'   => 2,
					'maxLength'   => 100,
				],
				'category_id' => [
					'type'        => 'integer',
					'description' => 'Only articles in this category (id). Optional.',
					'minimum'     => 1,
				],
				'limit'       => [
					'type'        => 'integer',
					'description' => 'How many articles to return at most.',
					'minimum'     => 1,
					'maximum'     => $this->maxResults,
					'default'     => min(5, $this->maxResults),
				],
			],
			'required'             => ['query'],
			'additionalProperties' => false,
		];
	}

	public function annotations(): array
	{
		// Article text is written by people, not by the site: it can contain
		// instructions aimed at the agent, which the agent should not follow.
		return ['readOnlyHint' => true, 'untrustedContentHint' => true];
	}

	public function execute(array $input): array
	{
		$query      = \is_string($input['query'] ?? null) ? $input['query'] : '';
		$categoryId = \is_int($input['category_id'] ?? null) ? $input['category_id'] : null;
		$limit      = \is_int($input['limit'] ?? null) ? $input['limit'] : 5;

		$found = $this->articles->search($query, $categoryId, $limit);

		return [
			'count'    => \count($found),
			'articles' => $found,
			'hint'     => $found === []
				? 'Nothing found. Try fewer or other words.'
				: 'Use get_article with an id to read the full text.',
		];
	}
}
