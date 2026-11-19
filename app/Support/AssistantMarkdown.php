<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class AssistantMarkdown
{
    public static function render(string $text): string
    {
        $html = Str::markdown($text, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'renderer' => ['soft_break' => "<br>\n"],
        ]);

        // Ответы не должны загружать сторонние картинки или встраивать элементы формы.
        return strip_tags($html, '<p><br><strong><em><del><ul><ol><li><pre><code><blockquote><a><h1><h2><h3><h4><h5><h6><hr><table><thead><tbody><tr><th><td>');
    }
}
