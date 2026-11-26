<?php

/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Yepr\Plugin\System\WebMcp\Content\ArticleRepository;
use Yepr\Plugin\System\WebMcp\Content\JoomlaArticleRepository;
use Yepr\Plugin\System\WebMcp\Extension\WebMcp;
use Yepr\Plugin\System\WebMcp\Shop\J2CommerceShop;
use Yepr\Plugin\System\WebMcp\Shop\ShopTools;
use Yepr\Plugin\System\WebMcp\Tool\BuiltInTools;
use Yepr\Plugin\System\WebMcp\Tool\InputValidator;

/**
 * The composition root: the only place the plugin's services are put together.
 *
 * The plugin itself is a lazy proxy, and the repositories are built only when a tool
 * list is made: a system plugin is loaded on every request, also on the many that
 * never offer or run a tool.
 */
return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->share(InputValidator::class, static fn (): InputValidator => new InputValidator());

		$container->share(
			BuiltInTools::class,
			static fn (Container $container): BuiltInTools => new BuiltInTools(
				static function () use ($container): ArticleRepository {
					$app = Factory::getApplication();

					return new JoomlaArticleRepository(
						$container->get(DatabaseInterface::class),
						$app->getIdentity() ?? $container->get(UserFactoryInterface::class)->loadUserById(0),
						$app->getLanguage()->getTag()
					);
				},
				static function () use ($container): ?ShopTools {
					if (!ComponentHelper::isEnabled('com_j2commerce')) {
						return null;
					}

					return new ShopTools(new J2CommerceShop($container->get(DatabaseInterface::class), Factory::getApplication()));
				}
			)
		);

		$container->set(
			PluginInterface::class,
			$container->lazy(WebMcp::class, function (Container $container) {
				$plugin = new WebMcp(
					(array) PluginHelper::getPlugin('system', 'webmcp'),
					$container->get(BuiltInTools::class),
					$container->get(InputValidator::class),
					$container->get(DispatcherInterface::class)
				);
				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			})
		);
	}
};
