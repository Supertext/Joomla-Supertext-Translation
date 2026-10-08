<?php

namespace Supertext\Plugin\System\Supertext\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every language has every en-GB string, with the same placeholders, and the plugin's API messages are all covered. */
final class LanguageFilesTest extends TestCase
{
    private const LANGUAGES = ['de-DE', 'de-CH', 'fr-FR', 'it-IT'];
    private const FILES     = ['plg_system_supertext.ini', 'plg_system_supertext.sys.ini'];

    /** @return iterable<string, array{string, string}> */
    public static function files(): iterable
    {
        foreach (self::LANGUAGES as $language) {
            foreach (self::FILES as $file) {
                yield "$language/$file" => [$language, $file];
            }
        }
    }

    #[DataProvider('files')]
    public function testSameKeysAndPlaceholdersAsEnglish(string $language, string $file): void
    {
        $english = self::load('en-GB', $file);
        $strings = self::load($language, $file);

        self::assertSame(array_keys($english), array_keys($strings));

        foreach ($english as $key => $text) {
            self::assertSame(self::placeholders($text), self::placeholders($strings[$key]), "$language $key");
            self::assertSame(self::urls($text), self::urls($strings[$key]), "$language $key");
            self::assertStringNotContainsString('"', $strings[$key], "$language $key");
        }
    }

    public function testEveryApiReasonHasAString(): void
    {
        $client = (string) file_get_contents(__DIR__ . '/../plugin/src/Api/SupertextClient.php');
        preg_match_all("/(?:because\\(|=> \\[)'([a-z_]+)', '/", $client, $matches);
        $reasons = array_unique($matches[1]);
        $english = self::load('en-GB', 'plg_system_supertext.ini');

        self::assertNotEmpty($reasons);

        foreach ($reasons as $reason) {
            self::assertArrayHasKey('PLG_SYSTEM_SUPERTEXT_API_' . strtoupper($reason), $english, $reason);
        }
    }

    /** @return array<string, string> */
    private static function load(string $language, string $file): array
    {
        $path = __DIR__ . "/../plugin/language/$language/$file";
        self::assertFileExists($path);
        $strings = parse_ini_file($path, false, INI_SCANNER_RAW);
        self::assertIsArray($strings, $path);

        return $strings;
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%[sd]|<[a-z]+/', $text, $m);

        return $m[0];
    }

    /** @return list<string> */
    private static function urls(string $text): array
    {
        preg_match_all('#https://[^\s\'"<)]+#', $text, $m);

        return $m[0];
    }
}
