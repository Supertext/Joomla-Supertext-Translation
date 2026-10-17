<?php

namespace Supertext\Plugin\System\Supertext\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supertext\Plugin\System\Supertext\Api\Languages;
use Supertext\Plugin\System\Supertext\Api\SupertextException;

/** Content languages → Supertext codes: the mapping, else an exact Supertext code, else the SDK's default, else unsupported_language. */
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
        yield 'default: bare de' => ['de', 'de-DE'];
        yield 'default: region of a bare target' => ['nl-NL', 'nl'];
        yield 'default: sl-SI' => ['sl-SI', 'sl'];
        yield 'default: zh-TW' => ['zh-TW', 'zh-Hant'];
    }

    #[DataProvider('targets')]
    public function testTarget(string $tag, string $expected): void
    {
        self::assertSame($expected, Languages::target($tag, Languages::mapping(self::SETTINGS)));
    }

    /** @return iterable<string, array{string}> */
    public static function unmapped(): iterable
    {
        // No default where none is obvious: these need a choice on the Languages tab.
        yield 'en-IE' => ['en-IE'];
        yield 'de-LI' => ['de-LI'];
        yield 'gsw' => ['gsw'];
        yield 'sr-RS' => ['sr-RS'];
        yield 'unknown' => ['xx-YY'];
    }

    #[DataProvider('unmapped')]
    public function testLanguagesWithoutDefaultNeedAMapping(string $tag): void
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

    public function testOneMappingServesBothDirections(): void
    {
        $mapping = Languages::mapping(['de' => ['code' => 'de-CH'], 'en' => ['code' => 'en-US']]);

        self::assertSame('de-CH', Languages::target('de', $mapping));
        self::assertSame('de', Languages::source('de', $mapping));
        self::assertSame('en-US', Languages::target('en', $mapping));
        self::assertSame('en', Languages::source('en', $mapping));
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

    public function testSuggestions(): void
    {
        self::assertSame('de-CH', Languages::suggest('de-CH'));
        self::assertSame('en-GB', Languages::suggest('en_gb'));
        self::assertSame('es', Languages::suggest('es-ES'));
        self::assertSame('zh-Hans', Languages::suggest('zh-CN'));
        self::assertSame('de-DE', Languages::suggest('de'));
        self::assertNull(Languages::suggest('en-IE'));
        self::assertNull(Languages::suggest('gsw'));
    }

    public function testTheListIsSortedByName(): void
    {
        $names = array_column(Languages::targets(), 'name');
        $sorted = $names;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names);
    }

    public function testSettingsReadTheCurrentFormat(): void
    {
        $stored = (object) ['es-ES' => (object) ['code' => 'es', 'politeness' => 'more'], 'fr-FR' => (object) ['code' => 'fr-CH']];

        self::assertSame(
            ['es-ES' => ['code' => 'es', 'politeness' => 'more'], 'fr-FR' => ['code' => 'fr-CH', 'politeness' => 'default']],
            Languages::settings($stored),
        );
        self::assertSame(['de-CH' => ['code' => 'de-CH', 'politeness' => 'less']], Languages::settings('{"de-CH":{"code":"de-CH","politeness":"less"}}'));
        self::assertSame([], Languages::settings(null));
        self::assertSame([], Languages::settings(''));
    }

    public function testSettingsMigrateTheRepeatableRowsOf010(): void
    {
        $stored = (object) [
            'languages0' => (object) ['language' => 'es-ES', 'code' => 'es', 'politeness' => 'default'],
            'languages1' => (object) ['language' => 'fr-FR', 'code' => 'fr-CH', 'politeness' => 'more'],
            'languages2' => (object) ['language' => '', 'code' => 'nl'],
            'languages3' => (object) ['language' => 'en-GB', 'code' => '', 'politeness' => 'bogus'],
        ];

        self::assertSame(
            [
                'es-ES' => ['code' => 'es', 'politeness' => 'default'],
                'fr-FR' => ['code' => 'fr-CH', 'politeness' => 'more'],
                'en-GB' => ['code' => '', 'politeness' => 'default'],
            ],
            Languages::settings($stored),
        );
    }

    public function testCleanKeepsOnlySupertextCodesAndTonesTheyOffer(): void
    {
        $posted = [
            'es-ES'  => ['code' => 'ES', 'politeness' => 'more'],
            'en-GB'  => ['code' => 'en-GB', 'politeness' => 'more'],
            'fr-FR'  => ['code' => 'fr-ch', 'politeness' => 'less'],
            'de-CH'  => ['code' => 'xx-YY', 'politeness' => 'more'],
            'en-IE'  => ['code' => '', 'politeness' => 'more'],
            'a b"<'  => ['code' => 'es'],
        ];

        self::assertSame(
            [
                'es-ES' => ['code' => 'es', 'politeness' => 'more'],
                'en-GB' => ['code' => 'en-GB', 'politeness' => 'default'],
                'fr-FR' => ['code' => 'fr-CH', 'politeness' => 'less'],
                'de-CH' => ['code' => '', 'politeness' => 'default'],
                'en-IE' => ['code' => '', 'politeness' => 'default'],
            ],
            Languages::clean($posted),
        );
    }
}
