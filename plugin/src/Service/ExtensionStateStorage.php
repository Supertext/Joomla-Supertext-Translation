<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Service;

use Joomla\Database\DatabaseInterface;
use Supertext\Plugin\System\Supertext\Api\StateStorage;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Keeps the version reporter's state in the plugin's own row of `#__extensions`, column
 * `custom_data` (JSON). Joomla's plugin form doesn't touch that column (saving the settings
 * replaces `params` only), and installing an update over the plugin keeps it.
 *
 * Stored: what was reported last, when a report failed, the latest API key check (status,
 * time, HTTP status, Supertext's error code, the key's SHA-256 fingerprint) and the site URL.
 * Never the API key.
 */
final class ExtensionStateStorage implements StateStorage
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function load(): array
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName('custom_data'))
            ->from($this->db->quoteName('#__extensions'));
        $this->whereSelf($query);

        $value = $this->db->setQuery($query)->loadResult();
        $data  = \is_string($value) && $value !== '' ? json_decode($value, true) : null;

        return \is_array($data) ? $data : [];
    }

    public function save(array $data): void
    {
        $json  = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $query = $this->db->createQuery()
            ->update($this->db->quoteName('#__extensions'))
            ->set($this->db->quoteName('custom_data') . ' = :data')
            ->bind(':data', $json);
        $this->whereSelf($query);

        $this->db->setQuery($query)->execute();
    }

    private function whereSelf(\Joomla\Database\QueryInterface $query): void
    {
        $query->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('system'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('supertext'));
    }
}
