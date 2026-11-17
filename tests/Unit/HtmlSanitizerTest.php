<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * S7 (спринт по аудиту): safeHref не пропускает фишинговые ссылки вида /\evil.com.
 */
class HtmlSanitizerTest extends TestCase
{
    public function test_blocks_protocol_relative_and_backslash_urls(): void
    {
        foreach (['//evil.com/x', '/\\evil.com', '\\/evil.com', '\\\\evil.com'] as $href) {
            $html = HtmlSanitizer::sanitize('<a href="'.$href.'">link</a>');

            $this->assertStringNotContainsString('evil.com', $html, 'Фишинговый URL прошёл: '.$href);
        }
    }

    public function test_blocks_dangerous_schemes(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,<script>', 'vbscript:msgbox(1)'] as $href) {
            $html = HtmlSanitizer::sanitize('<a href="'.$href.'">link</a>');

            $this->assertStringNotContainsString('href', $html, 'Опасная схема прошла: '.$href);
        }
    }

    public function test_allows_safe_targets(): void
    {
        foreach (['https://example.com/a', 'http://example.com', 'mailto:a@b.c', '/documents/5', './rel', '../up', '#anchor', '?q=1', 'plain-rel'] as $href) {
            $html = HtmlSanitizer::sanitize('<a href="'.$href.'">link</a>');

            $this->assertStringContainsString('href', $html, 'Безопасная ссылка отброшена: '.$href);
        }
    }

    public function test_strips_event_handlers_and_unknown_tags(): void
    {
        $html = HtmlSanitizer::sanitize('<span onclick="steal()">a</span><script>alert(1)</script><b>ok</b>');

        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringContainsString('<b>ok</b>', $html);
    }
}
