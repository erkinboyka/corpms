<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Whitelist-санитайзер HTML для тел документов (редактор TinyMCE).
 *
 * Только ext-dom, без внешних зависимостей: список разрешённых тегов и
 * атрибутов, запрет опасных схем (javascript:, data:) в ссылках, удаление
 * обработчиков событий. Используется при выводе тела документа на странице
 * карточки: нетекстовые (с тегами) тела рендерятся как разметка, обычный
 * текст остаётся экранированным (контракт DocumentsHttpTest).
 */
final class HtmlSanitizer
{
    /** @var array<string, list<string>> разрешённый тег => атрибуты */
    private const ALLOWED_TAGS = [
        'p' => [],
        'br' => [],
        'hr' => [],
        'strong' => [], 'b' => [],
        'em' => [], 'i' => [],
        'u' => [], 'ins' => [],
        's' => [], 'strike' => [], 'del' => [],
        'sub' => [], 'sup' => [],
        'span' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'ul' => [], 'ol' => ['start'], 'li' => [],
        'blockquote' => [],
        'pre' => [], 'code' => [],
        'a' => ['href', 'title', 'target'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
    ];

    /** Теги, удаляемые вместе с содержимым: исполняемый/внедряемый контент. */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet',
        'noscript', 'template', 'svg', 'math', 'form', 'input',
        'button', 'textarea', 'select', 'option', 'link', 'meta', 'base',
    ];

    /** Текст содержит теги → его можно выводить через sanitize(). */
    public static function looksLikeHtml(?string $value): bool
    {
        return $value !== null && preg_match('/<\s*\/?\s*[a-zA-Z!][^>]*>/', $value) === 1;
    }

    /** Возвращает безопасный HTML: разрешённые теги сохранены, остальное вычищено. */
    public static function sanitize(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // PI-префикс фиксирует UTF-8, иначе DOMDocument «съедает» кириллицу
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        /* Снимок всех элементов (порок документа — родители раньше детей),
           чтобы safely мутировать дерево во время обхода. */
        $xpath = new DOMXPath($doc);
        /** @var list<DOMElement> $elements */
        $elements = [];
        foreach ($xpath->query('//*') ?: [] as $element) {
            if ($element instanceof DOMElement && strtolower($element->tagName) !== 'body') {
                $elements[] = $element;
            }
        }

        foreach ($elements as $element) {
            $tag = strtolower($element->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $element->parentNode?->removeChild($element);

                continue;
            }

            if (! array_key_exists($tag, self::ALLOWED_TAGS)) {
                self::unwrap($element);

                continue;
            }

            $allowed = self::ALLOWED_TAGS[$tag];

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                if (! in_array($name, $allowed, true)) {
                    $element->removeAttribute($attribute->name);

                    continue;
                }
                if ($name === 'href') {
                    $safe = self::safeHref($attribute->value);
                    if ($safe === null) {
                        $element->removeAttribute($attribute->name);
                    } else {
                        $element->setAttribute('href', $safe);
                    }
                }
            }

            if ($tag === 'a' && $element->getAttribute('target') !== '') {
                if ($element->getAttribute('target') !== '_blank') {
                    $element->removeAttribute('target');
                } else {
                    $element->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }

        /* Сериализуем узлы <body> поштучно (без обёрток html/head/body). */
        $out = '';
        foreach ($body->childNodes as $node) {
            $out .= $doc->saveHTML($node);
        }

        return $out;
    }

    /** Заменяет элемент его детьми: тег неизвестен, текст остаётся. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }
        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    /** Разрешает только http(s), mailto, якоря и относительные пути. */
    private static function safeHref(string $href): ?string
    {
        // Управляющие символы позволяют обфусцировать схему (java\tscript:)
        $href = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($href)) ?? '';
        if ($href === '') {
            return null;
        }
        if (preg_match('#^(https?:|mailto:)#i', $href) === 1) {
            return $href;
        }
        // Протокол-относительный //evil.com и экранированный вид /\evil.com
        // (браузер нормализует '\' в '/') уводят ссылку на чужой хост — отказ.
        if (str_starts_with($href, '//') || str_contains($href, '\\')) {
            return null;
        }
        if (preg_match('{^(#|/|\./|\.\./|\?)}', $href) === 1) {
            return $href;
        }

        // Без схемы — относительная ссылка; с любой другой схемой — отказ
        return preg_match('/^[a-z][a-z0-9+.\-]*:/i', $href) === 1 ? null : $href;
    }
}
