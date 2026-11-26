<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\System\WebMcp\Content;

use Joomla\CMS\Router\Route;
use Joomla\CMS\User\User;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Articles from com_content's tables, filtered as the site's own article views filter
 * them for the visitor.
 *
 * @phpstan-import-type ArticleSummary from ArticleRepository
 * @phpstan-import-type Article from ArticleRepository
 */
final class JoomlaArticleRepository implements ArticleRepository
{
	private const SUMMARY_LENGTH = 300;

	/**
	 * @param   string  $language  The visitor's language tag, e.g. en-GB.
	 */
	public function __construct(
		private readonly DatabaseInterface $db,
		private readonly User $user,
		private readonly string $language
	) {
	}

	public function search(string $words, ?int $categoryId, int $limit): array
	{
		$db    = $this->db;
		$query = $this->visibleArticles()
			->order($db->quoteName('a.publish_up') . ' DESC')
			->setLimit($limit);

		// Every word must occur in the title or the text. Bound, never concatenated.
		// bind() takes its value by reference, so each term needs its own variable:
		// one reused $like would give every placeholder the last term.
		$terms = \array_slice(preg_split('/\s+/u', $words, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8);
		$likes = array_map(
			static fn (string $term): string => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%',
			$terms
		);

		foreach (array_keys($likes) as $i) {
			$name = ':term' . $i;

			$query->where('(' . implode(' OR ', [
				$db->quoteName('a.title') . ' LIKE ' . $name,
				$db->quoteName('a.introtext') . ' LIKE ' . $name,
				$db->quoteName('a.fulltext') . ' LIKE ' . $name,
			]) . ')')
				->bind($name, $likes[$i]);
		}

		if ($categoryId !== null) {
			$query->where($db->quoteName('a.catid') . ' = :catid')
				->bind(':catid', $categoryId, ParameterType::INTEGER);
		}

		/** @var list<object{id: string, title: string, catid: string, language: string, introtext: string, publish_up: ?string, created: string, category: string}> $rows */
		$rows = $db->setQuery($query)->loadObjectList();

		return array_map(fn (object $row): array => [
			'id'       => (int) $row->id,
			'title'    => $row->title,
			'url'      => $this->url((int) $row->id, (int) $row->catid, $row->language),
			'category' => $row->category,
			'date'     => substr($row->publish_up ?? $row->created, 0, 10),
			'summary'  => PlainText::fromHtml($row->introtext, self::SUMMARY_LENGTH),
		], $rows);
	}

	public function find(int $id): ?array
	{
		$db    = $this->db;
		$query = $this->visibleArticles()
			->select([$db->quoteName('a.fulltext'), $db->quoteName('a.created_by_alias'), $db->quoteName('u.name', 'author')])
			->join('LEFT', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('a.created_by'))
			->where($db->quoteName('a.id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);

		/** @var object{id: string, title: string, catid: string, language: string, introtext: string, fulltext: string, publish_up: ?string, created: string, category: string, created_by_alias: string, author: ?string}|null $row */
		$row = $db->setQuery($query)->loadObject();

		if ($row === null) {
			return null;
		}

		return [
			'id'       => (int) $row->id,
			'title'    => $row->title,
			'url'      => $this->url((int) $row->id, (int) $row->catid, $row->language),
			'category' => $row->category,
			'date'     => substr($row->publish_up ?? $row->created, 0, 10),
			'author'   => $row->created_by_alias !== '' ? $row->created_by_alias : (string) $row->author,
			'text'     => PlainText::fromHtml($row->introtext . "\n" . $row->fulltext),
		];
	}

	/**
	 * Published, current, readable by this visitor, in their language or "All".
	 */
	private function visibleArticles(): QueryInterface
	{
		$db     = $this->db;
		$now    = gmdate('Y-m-d H:i:s');
		$levels = array_map('intval', $this->user->getAuthorisedViewLevels());

		return $db->createQuery()
			->select($db->quoteName(['a.id', 'a.title', 'a.catid', 'a.language', 'a.introtext', 'a.publish_up', 'a.created']))
			->select($db->quoteName('c.title', 'category'))
			->from($db->quoteName('#__content', 'a'))
			->join('INNER', $db->quoteName('#__categories', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid'))
			->where($db->quoteName('a.state') . ' = 1')
			->where($db->quoteName('c.published') . ' = 1')
			->whereIn($db->quoteName('a.access'), $levels)
			->whereIn($db->quoteName('c.access'), $levels)
			->where('(' . $db->quoteName('a.publish_up') . ' IS NULL OR ' . $db->quoteName('a.publish_up') . ' <= :now1)')
			->where('(' . $db->quoteName('a.publish_down') . ' IS NULL OR ' . $db->quoteName('a.publish_down') . ' > :now2)')
			->whereIn($db->quoteName('a.language'), [$this->language, '*'], ParameterType::STRING)
			->bind(':now1', $now)
			->bind(':now2', $now);
	}

	private function url(int $id, int $categoryId, string $language): string
	{
		return Route::link('site', RouteHelper::getArticleRoute($id, $categoryId, $language), false, Route::TLS_IGNORE, true);
	}
}
