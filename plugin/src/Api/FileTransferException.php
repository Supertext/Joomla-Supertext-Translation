<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Api;

/**
 * An export or import of a translation file that can't go ahead. `$reason` is shown as
 * PLG_SYSTEM_SUPERTEXT_FILE_<REASON> with `$args` (see Service\Messages):
 * nothing_to_translate, invalid_request, invalid_file, missing_metadata, too_large,
 * other_system (args: the file's plugin), not_found, no_language (args: the language tag).
 */
final class FileTransferException extends \RuntimeException
{
    /** @param list<string> $args */
    public function __construct(public readonly string $reason, public readonly array $args = [])
    {
        parent::__construct($reason . ($args ? ' (' . implode(', ', $args) . ')' : ''));
    }
}
