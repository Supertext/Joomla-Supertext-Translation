<?php

namespace Supertext\Plugin\System\Supertext\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supertext\Plugin\System\Supertext\Api\Languages;
use Supertext\Plugin\System\Supertext\Api\SupertextException;

/** Content languages → Supertext codes: the mapping, else an exact Supertext code, else unsupported_language. No guessing. */
final class LanguagesTest extends TestCase
{
    private const SETTINGS = [
        'es-ES' => ['code' => 'es', 'politeness' => 'default'],
        'fr-FR' => ['code' => 'fr-CH', 'politeness' => 'more'],
        'en-GB' => ['code' => '', 'politeness' => 'more'],
        'zh-CN' => ['code' => 'zh-Hans'],
        'de-CH' => ['code' => '  '],
    ];

    public function testTheMappingLeavesOutRowsWithoutCode(): void
    {
        self::assertSame(['es-ES' => 'es', 'fr-FR' => 'fr-CH', 'zh-CN' => 'zh-Hans'], Languages::mapping(self::SETTINGS));
    }

    /** @return iterable<string, array{string, string}> */
    public static function targets(): iterable
    {
        yield 'Joomla tag that is a Supertext code' => ['de-CH', 'de-CH'];
        yield 'case and underscore ignored' => ['IT_it', 'it-IT'];
        yield 'en-GB is a Supertext code' => ['en-GB', 'en-GB'];
        yield 'mapping wins' => ['fr-FR', 'fr-CH'];
        yield 'mapped bare code' => ['es-ES', 'es'];
        yield 'mapped script' => ['zh-CN', 'zh-Hans'];
        yield 'mapping key case ignored' => ['ES_es', 'es'];
    }

    #[DataProvider('targets')]
    public function testTarget(string $tag, string $expected): void
    {
        self::assertSame($expected, Languages::target($tag, Languages::mapping(self::SETTINGS)));
    }

    /** @return iterable<string, array{string}> */
    public static function unmapped(): iterable
    {
        // The old default regions are gone: Supertext rejects es-ES, sl-SI, zh-CN, nl-NL.
        yield 'es-ES' => ['es-ES'];
        yield 'sl-SI' => ['sl-SI'];
        yield 'nl-NL' => ['nl-NL'];
        yield 'bare de' => ['de'];
        yield 'unknown' => ['xx-YY'];
    }

    #[DataProvider('unmapped')]
    public function testUnmappedLanguagesAreNotGuessed(string $tag): void
    {
        self::assertNull(Languages::targetOrNull($tag));

        try {
            Languages::target($tag);
            self::fail('Expected unsupported_language');
        } catch (SupertextException $e) {
            self::assertSame('unsupported_language', $e->reason);
            self::assertSame([$tag], $e->args);
            self::assertStringContainsString("The language $tag has no Supertext language yet", $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function sources(): iterable
    {
        yield 'Joomla tag → bare language' => ['en-GB', 'en'];
        yield 'mapped code → its language' => ['fr-FR', 'fr'];
        yield 'mapped script → its language' => ['zh-CN', 'zh'];
        yield 'unmapped regional' => ['es-ES', 'es'];
        yield 'empty: auto-detect' => ['', ''];
    }

    #[DataProvider('sources')]
    public function testSource(string $tag, string $expected): void
    {
        self::assertSame($expected, Languages::source($tag, Languages::mapping(self::SETTINGS)));
    }

    public function testASourceSupertextDoesNotKnowIsRefused(): void
    {
        $this->expectException(SupertextException::class);
        Languages::source('ar-AA');
    }

    public function testPoliteness(): void
    {
        self::assertSame('more', Languages::politeness('de-CH', 'more'));
        self::assertSame('less', Languages::politeness('fr-FR', 'less'));
        self::assertSame('default', Languages::politeness('en-US', 'default'));
        self::assertSame('default', Languages::politeness('de-CH', 'whatever'));
        self::assertSame('more', Languages::politeness('xx-new', 'more'), 'unknown (mapped) code: sent, Supertext decides');

        foreach (['en-US', 'en-GB', 'rm', 'sq', 'sr-Latn', 'gsw-u-sd-chzh'] as $target) {
            self::assertFalse(Languages::supportsPoliteness($target), $target);

            try {
                Languages::politeness($target, 'more');
                self::fail("Expected unsupported_feature for $target");
            } catch (SupertextException $e) {
                self::assertSame('unsupported_feature', $e->reason);
            }
        }
    }

    public function testExactTargetsAndTheList(): void
    {
        self::assertSame('fr-FR', Languages::exactTarget('fr_fr'));
        self::assertNull(Languages::exactTarget('es-ES'));
        self::assertContains('zh-Hant', array_column(Languages::targets(), 'code'));
    }
}
