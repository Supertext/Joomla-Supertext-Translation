<?php

/**
 * @package     Supertext Translation for Joomla — demo setup
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\Console\SupertextDemo\Extension;

use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Supertext\Plugin\Console\SupertextDemo\Command\SetupCommand;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/** Registers `supertext:demo-setup` with the Joomla CLI. Installed only in the demo. */
final class SupertextDemo extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ApplicationEvents::BEFORE_EXECUTE => 'registerCommands'];
    }

    public function registerCommands(ApplicationEvent $event): void
    {
        $event->getApplication()->addCommand(new SetupCommand());
    }
}
