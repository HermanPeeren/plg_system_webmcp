<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * Install script for the WebMCP system plugin, as a service provider.
 *
 * - Refuses a site it cannot run on.
 * - Enables the plugin on a fresh install. It then offers the read-only article tools,
 *   and the shop tools when J2Commerce is installed; both can be switched off in the
 *   plugin's options.
 */
return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->set(
			InstallerScriptInterface::class,
			static fn (Container $container): InstallerScriptInterface => new class (
				$container->get(DatabaseInterface::class)
			) implements InstallerScriptInterface {
				/**
				 * The oldest Joomla this runs on: the provider uses lazy plugin loading
				 * ($container->lazy()), which came in Joomla 6.1.
				 */
				private const MINIMUM_JOOMLA = '6.1';

				private const MINIMUM_PHP = '8.3';

				private ?CMSApplicationInterface $app = null;

				public function __construct(private readonly DatabaseInterface $db)
				{
				}

				public function setApplication(CMSApplicationInterface $app): void
				{
					$this->app = $app;
				}

				public function install(InstallerAdapter $adapter): bool
				{
					return true;
				}

				public function update(InstallerAdapter $adapter): bool
				{
					return true;
				}

				public function uninstall(InstallerAdapter $adapter): bool
				{
					return true;
				}

				public function preflight(string $type, InstallerAdapter $adapter): bool
				{
					if ($type === 'uninstall') {
						return true;
					}

					if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<')) {
						$this->app?->enqueueMessage(\sprintf('The WebMCP plugin needs PHP %s or later.', self::MINIMUM_PHP), 'error');

						return false;
					}

					if (version_compare(JVERSION, self::MINIMUM_JOOMLA, '<')) {
						$this->app?->enqueueMessage(\sprintf('The WebMCP plugin needs Joomla %s or later.', self::MINIMUM_JOOMLA), 'error');

						return false;
					}

					return true;
				}

				public function postflight(string $type, InstallerAdapter $adapter): bool
				{
					if ($type !== 'install') {
						return true;
					}

					$enabled = 1;
					$kind    = 'plugin';
					$folder  = 'system';
					$element = 'webmcp';

					$query = $this->db->createQuery()
						->update($this->db->quoteName('#__extensions'))
						->set($this->db->quoteName('enabled') . ' = :enabled')
						->where($this->db->quoteName('type') . ' = :type')
						->where($this->db->quoteName('folder') . ' = :folder')
						->where($this->db->quoteName('element') . ' = :element')
						->bind(':enabled', $enabled, ParameterType::INTEGER)
						->bind(':type', $kind)
						->bind(':folder', $folder)
						->bind(':element', $element);

					$this->db->setQuery($query)->execute();

					return true;
				}
			}
		);
	}
};
