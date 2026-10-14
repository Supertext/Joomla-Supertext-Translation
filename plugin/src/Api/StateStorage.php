<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Api;

/**
 * Where the plugin keeps the version reporter's state (JSON) and the site URL it last saw.
 * In Joomla: the plugin's `custom_data` in `#__extensions` (Service\ExtensionStateStorage).
 */
interface StateStorage
{
    /** @return array{reporter?: mixed, siteUrl?: string} */
    public function load(): array;

    /** @param array{reporter?: mixed, siteUrl?: string} $data */
    public function save(array $data): void;
}
