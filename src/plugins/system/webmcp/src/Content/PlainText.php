<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\System\WebMcp\Content;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * HTML from the editor as plain text for an agent: no tags, no scripts, no
 * `{loadmodule}`-style plugin codes, whitespace collapsed, cut at a word.
 */
final class PlainText
{
	public static function fromHtml(string $html, int $maxLength = 0): string
	{
		// Script and style content is not text.
		$html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';

		// Block elements end a sentence; keep them apart.
		$html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "$0\n", $html) ?? '';

		$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// Content plugin codes such as {loadmodule mod_menu} or {emailcloak=off}.
		$text = preg_replace('/\{[a-z_]+(?:[ =][^}]*)?\}/i', '', $text) ?? '';
		$text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? '';
		$text = trim(preg_replace('/\s*\n\s*/', "\n", $text) ?? '');

		return $maxLength > 0 ? self::cut($text, $maxLength) : $text;
	}

	public static function cut(string $text, int $maxLength): string
	{
		if (mb_strlen($text) <= $maxLength) {
			return $text;
		}

		$cut   = mb_substr($text, 0, $maxLength);
		$space = mb_strrpos($cut, ' ');

		if ($space !== false && $space > $maxLength * 0.6) {
			$cut = mb_substr($cut, 0, $space);
		}

		return rtrim($cut, " ,.;:\n") . '…';
	}
}
