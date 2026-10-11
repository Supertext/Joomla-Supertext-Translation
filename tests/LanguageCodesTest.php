<?php

namespace Supertext\Plugin\System\Supertext\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supertext\Plugin\System\Supertext\Api\LanguageCodes;

final class LanguageCodesTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function sources(): iterable
    {
        yield 'bare source' => ['en', '', 'en'];
        yield 'Joomla tag => bare' => ['en-GB', '', 'en'];
        yield 'underscore and case' => ['DE_ch', '', 'de'];
        yield 'override wins, reduced to its language' => ['en-GB', 'fr-CH', 'fr'];
        yield 'empty' => ['', '', ''];
    }

    #[DataProvider('sources')]
    public function testSource(string $tag, string $override, string $expected): void
    {
        self::assertSame($expected, LanguageCodes::source($tag, $override));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function targets(): iterable
    {
        yield 'bare target => default region' => ['de', '', 'de-DE'];
        yield 'bare en => en-US' => ['en', '', 'en-US'];
        yield 'bare zh => zh-CN' => ['zh', '', 'zh-CN'];
        yield 'Joomla tag kept' => ['fr-CH', '', 'fr-CH'];
        yield 'underscore normalized' => ['de_CH', '', 'de-CH'];
        yield 'case normalized' => ['DE-ch', '', 'de-CH'];
        yield 'script kept' => ['zh-hant', '', 'zh-Hant'];
        yield 'numeric region kept' => ['es-419', '', 'es-419'];
        yield 'override wins' => ['de-DE', 'de-CH', 'de-CH'];
        yield 'override normalized' => ['de-DE', 'de_at', 'de-AT'];
        yield 'bare override gets default region' => ['it-CH', 'it', 'it-IT'];
        yield 'blank override ignored' => ['fr-FR', ' ', 'fr-FR'];
        yield 'unknown bare code unchanged' => ['rm', '', 'rm'];
        yield 'empty' => ['', '', ''];
    }

    #[DataProvider('targets')]
    public function testTarget(string $tag, string $override, string $expected): void
    {
        self::assertSame($expected, LanguageCodes::target($tag, $override));
    }

    public function testEveryDefaultIsRegional(): void
    {
        foreach (LanguageCodes::DEFAULT_REGIONS as $bare => $regional) {
            self::assertStringStartsWith($bare . '-', $regional);
            self::assertSame($regional, LanguageCodes::target($regional));
        }
    }
}
