<?php

/**
 * @package     Supertext Translation for Joomla — demo setup
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Supertext\Plugin\Console\SupertextDemo\Extension\SupertextDemo;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            fn () => new SupertextDemo((array) PluginHelper::getPlugin('console', 'supertextdemo'))
        );
    }
};
