<?php

namespace Supertext\Plugin\System\Supertext\Tests;

use PHPUnit\Framework\TestCase;
use Supertext\Plugin\System\Supertext\Api\HtmlDocument;

final class HtmlDocumentTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $segments = [
            ['text' => 'Fish & chips <3', 'html' => false],
            ['text' => "Line one\nLine two", 'html' => false],
            ['text' => '<p>Every praline is made in <strong>Bern</strong>. <a href="https://example.com">More</a></p>', 'html' => true],
            ['text' => 'Grüezi', 'html' => false],
        ];

        $html = HtmlDocument::build($segments);
        self::assertStringContainsString('<div data-st-id="0">Fish &amp; chips &lt;3</div>', $html);
        self::assertStringContainsString('<div data-st-id="1">Line one<br>Line two</div>', $html);

        $parsed = HtmlDocument::parse($html, [false, false, true, false]);
        self::assertSame(array_column($segments, 'text'), $parsed);
    }

    public function testCollapsesWhitespaceInPlainText(): void
    {
        $parsed = HtmlDocument::parse("<div data-st-id=\"0\">\n  Bonjour\n  le monde </div>", [false]);

        self::assertSame('Bonjour le monde', $parsed[0]);
    }

    public function testProtectsPluginTags(): void
    {
        $source    = '<p>Hours: {loadmodule mod_custom,Opening hours} and {loadposition sidebar}.</p><p>{emailcloak=off}</p>';
        $protected = HtmlDocument::protect($source);

        self::assertStringContainsString('<span translate="no" class="notranslate" data-st-keep>{loadmodule mod_custom,Opening hours}</span>', $protected);
        self::assertStringContainsString('<span translate="no" class="notranslate" data-st-keep>{emailcloak=off}</span>', $protected);
        self::assertSame($source, HtmlDocument::unprotect($protected));

        // After a round trip through the DOM the bare attribute may come back as data-st-keep="".
        self::assertSame('<p>{loadposition x}</p>', HtmlDocument::unprotect('<p><span translate="no" class="notranslate" data-st-keep="">{loadposition x}</span></p>'));
    }

    public function testLeavesCssAndJsonBracesAlone(): void
    {
        $source = '<p style="color:red">A {b} c</p><script>var x = {"a": 1};</script>';

        self::assertStringContainsString('{"a": 1}', HtmlDocument::protect($source));
        self::assertStringNotContainsString('data-st-keep>{"a"', HtmlDocument::protect($source));
    }
}
