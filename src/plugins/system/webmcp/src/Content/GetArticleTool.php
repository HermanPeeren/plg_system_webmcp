<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\System\WebMcp\Content;

use Yepr\Plugin\System\WebMcp\Tool\Tool;
use Yepr\Plugin\System\WebMcp\Tool\ToolFailure;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * `get_article`: the full text of one article, as plain text.
 */
final class GetArticleTool implements Tool
{
	/**
	 * Enough for a long article; an agent's context is not unlimited.
	 */
	private const MAX_TEXT = 20000;

	public function __construct(private readonly ArticleRepository $articles)
	{
	}

	public function name(): string
	{
		return 'get_article';
	}

	public function title(): string
	{
		return 'PLG_SYSTEM_WEBMCP_TOOL_GET_ARTICLE_TITLE';
	}

	public function description(): string
	{
		return 'PLG_SYSTEM_WEBMCP_TOOL_GET_ARTICLE_DESC';
	}

	public function inputSchema(): array
	{
		return [
			'type'                 => 'object',
			'properties'           => [
				'id' => [
					'type'        => 'integer',
					'description' => 'The article id, as returned by search_articles.',
					'minimum'     => 1,
				],
			],
			'required'             => ['id'],
			'additionalProperties' => false,
		];
	}

	public function annotations(): array
	{
		return ['readOnlyHint' => true, 'untrustedContentHint' => true];
	}

	public function execute(array $input): array
	{
		$id      = \is_int($input['id'] ?? null) ? $input['id'] : 0;
		$article = $this->articles->find($id);

		// The same answer for "does not exist" and "you may not read it".
		if ($article === null) {
			throw new ToolFailure(\sprintf('No article with id %d.', $id));
		}

		$truncated       = mb_strlen($article['text']) > self::MAX_TEXT;
		$article['text'] = PlainText::cut($article['text'], self::MAX_TEXT);

		return $article + ['truncated' => $truncated];
	}
}
