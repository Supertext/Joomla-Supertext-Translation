<?php

/**
 * @package     Supertext Translation for Joomla
 * @copyright   (C) Supertext AG
 * @license     GNU General Public License version 2 or later
 */

namespace Supertext\Plugin\System\Supertext\Api;

/**
 * Any failure talking to Supertext. The message is safe to show to editors.
 *
 * The message is English. $reason (e.g. "limit_exceeded") lets the CMS show its own translation
 * of it: the translated text gets $args (sprintf) and, if set, " ($detail)" appended.
 */
final class SupertextException extends \RuntimeException
{
    /** @param list<string|int> $args */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly string $reason = '',
        public readonly array $args = [],
        public readonly string $detail = '',
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @param string           $message English text, with %s placeholders for $args
     * @param list<string|int> $args
     */
    public static function because(string $reason, string $message, array $args = [], int $code = 0, string $detail = '', ?\Throwable $previous = null): self
    {
        $message = $args ? sprintf($message, ...$args) : $message;

        return new self($detail !== '' ? $message . ' (' . $detail . ')' : $message, $code, $previous, $reason, $args, $detail);
    }
}
