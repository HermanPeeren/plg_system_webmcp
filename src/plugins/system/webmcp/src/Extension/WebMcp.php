<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\System\WebMcp\Extension;

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\SubscriberInterface;
use Yepr\Plugin\System\WebMcp\Event\CollectToolsEvent;
use Yepr\Plugin\System\WebMcp\Tool\BuiltInTools;
use Yepr\Plugin\System\WebMcp\Tool\InputValidator;
use Yepr\Plugin\System\WebMcp\Tool\Tool;
use Yepr\Plugin\System\WebMcp\Tool\ToolFailure;
use Yepr\Plugin\System\WebMcp\Tool\ToolRegistry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Offers the site's tools to AI agents in the browser, through WebMCP.
 *
 * Two halves:
 *
 * 1. On every frontend HTML page, the tool definitions go into the page as script
 *    options, with media/plg_system_webmcp/js/webmcp.js. That script registers each
 *    tool with `document.modelContext.registerTool()`, if the browser has WebMCP.
 * 2. When an agent calls a tool, the script posts the input to com_ajax
 *    (`onAjaxWebmcp`), with the session's form token. The tool runs here, as the
 *    visitor: their session, their access levels, their cart.
 */
final class WebMcp extends CMSPlugin implements SubscriberInterface
{
	/**
	 * @var boolean
	 */
	protected $autoloadLanguage = true;

	/**
	 * The tools of this request, collected once.
	 */
	private ?ToolRegistry $tools = null;

	/**
	 * @param   array<string, mixed>  $config  The plugin's row, from PluginHelper::getPlugin().
	 * @param   DispatcherInterface   $events  For onWebMcpCollectTools: the application's dispatcher.
	 */
	public function __construct(
		array $config,
		private readonly BuiltInTools $builtInTools,
		private readonly InputValidator $validator,
		private readonly DispatcherInterface $events
	) {
		parent::__construct($config);
	}

	/**
	 * @return  array<string, string>
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onBeforeCompileHead' => 'offerTools',
			'onAjaxWebmcp'        => 'callTool',
		];
	}

	/**
	 * Put the tool definitions and the script into the page.
	 */
	public function offerTools(): void
	{
		$app = $this->getApplication();

		if (!$app instanceof SiteApplication) {
			return;
		}

		$document = $app->getDocument();

		if (!$document instanceof HtmlDocument) {
			return;
		}

		$tools = $this->tools()->all();

		if ($tools === []) {
			return;
		}

		$document->addScriptOptions('plg_system_webmcp', [
			'endpoint' => Uri::base(true) . '/index.php?option=com_ajax&plugin=webmcp&group=system&format=json',
			'token'    => Session::getFormToken(),
			'tools'    => array_map($this->definition(...), $tools),
		]);

		$assets = $document->getWebAssetManager();
		$assets->getRegistry()->addExtensionRegistryFile('plg_system_webmcp');
		$assets->useScript('plg_system_webmcp.webmcp');
	}

	/**
	 * Run one tool call: `tool` (its name) and `input` (JSON) in the POST body.
	 *
	 * The answer is always `{ok: true, result}` or `{ok: false, error}`, so the
	 * script can hand an error message to the agent instead of failing silently.
	 */
	public function callTool(AjaxEvent $event): void
	{
		$event->updateEventResult($this->run());
	}

	/**
	 * @return  array{ok: true, result: array<string, mixed>}|array{ok: false, error: string}
	 */
	private function run(): array
	{
		$app = $this->getApplication();

		if ($app === null || !$app->isClient('site')) {
			return ['ok' => false, 'error' => 'Tools run on the site only.'];
		}

		// The token ties the call to this visitor's session: another site cannot make
		// the visitor's browser call a tool (CSRF), even one that only reads.
		if (!Session::checkToken('post')) {
			return ['ok' => false, 'error' => 'The page has expired. Reload it and try again.'];
		}

		$input = $app->getInput()->post;
		$name  = $input->getString('tool', '');
		$tool  = $this->tools()->get($name);

		if ($tool === null) {
			return ['ok' => false, 'error' => \sprintf('There is no tool "%s".', $name)];
		}

		try {
			$arguments = json_decode($input->get('input', '{}', 'raw'), true, 8, JSON_THROW_ON_ERROR);
			$arguments = $this->validator->validate($tool->inputSchema(), $arguments);

			return ['ok' => true, 'result' => $tool->execute($arguments)];
		} catch (\JsonException) {
			return ['ok' => false, 'error' => 'The input is not valid JSON.'];
		} catch (ToolFailure $failure) {
			return ['ok' => false, 'error' => $failure->getMessage()];
		} catch (\Throwable $error) {
			// Not for the agent: a message from the database or a file path tells
			// a visitor more than they may know.
			Log::add(\sprintf('WebMCP tool %s failed: %s', $name, $error->getMessage()), Log::ERROR, 'plg_system_webmcp');

			return ['ok' => false, 'error' => 'The tool failed. Please try again later.'];
		}
	}

	/**
	 * This plugin's own tools, then those other plugins add.
	 */
	private function tools(): ToolRegistry
	{
		if ($this->tools !== null) {
			return $this->tools;
		}

		$this->tools = new ToolRegistry();
		$this->builtInTools->addTo($this->tools, $this->params);

		$this->events->dispatch(CollectToolsEvent::NAME, new CollectToolsEvent($this->tools));

		return $this->tools;
	}

	/**
	 * What the browser gets for one tool: everything registerTool() needs except
	 * execute(), which the script adds.
	 *
	 * @return  array<string, mixed>
	 */
	private function definition(Tool $tool): array
	{
		return [
			'name'        => $tool->name(),
			'title'       => Text::_($tool->title()),
			'description' => Text::_($tool->description()),
			'inputSchema' => $tool->inputSchema(),
			'annotations' => $tool->annotations(),
		];
	}
}
