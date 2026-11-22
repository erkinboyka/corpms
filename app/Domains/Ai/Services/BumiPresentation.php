<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use RuntimeException;
use ZipArchive;

final class BumiPresentation
{
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Fill an empty widescreen Office package with editable shapes and text. */
    public function render(array $context): string
    {
        $pages = [['section' => 'cover', 'rows' => []]];
        foreach ($context['edition']['sections'] as $section) {
            $rows = match ($section) {
                'extremes' => [$context['ranked'][0], $context['ranked'][count($context['ranked']) - 1]],
                'focus' => [$context['rows'][0]],
                'rank' => $context['ranked'],
                default => $context['rows'],
            };
            foreach (array_chunk($rows, 6) as $i => $chunk) {
                $pages[] = ['section' => $section, 'rows' => $chunk, 'offset' => $i * 6];
            }
        }
        $pages[] = ['section' => 'sources', 'rows' => []];
        $temporary = tmpfile();
        if (! $temporary) {
            throw new RuntimeException(__('Не удалось создать временный файл презентации.'));
        }
        $path = stream_get_meta_data($temporary)['uri'];
        // ZipArchive needs exclusive access to replace the file on Windows.
        $work = $path.'.pptx';
        $zip = new ZipArchive;
        try {
            if (! copy(resource_path('ai/blank.pptx'), $work) || $zip->open($work) !== true) {
                throw new RuntimeException(__('Не удалось открыть шаблон презентации.'));
            }
            $manifest = simplexml_load_string($zip->getFromName('[Content_Types].xml'));
            $rels = simplexml_load_string($zip->getFromName('ppt/_rels/presentation.xml.rels'));
            $presentation = simplexml_load_string($zip->getFromName('ppt/presentation.xml'));
            $slideList = $presentation->xpath('//p:sldIdLst')[0];
            $layoutRels = $zip->getFromName('ppt/slides/_rels/slide1.xml.rels');
            foreach ($pages as $i => $page) {
                $n = $i + 1;
                $filename = 'ppt/slides/slide'.$n.'.xml';
                $slideRels = simplexml_load_string($layoutRels);
                $shapes = $this->shapes($page, $context, $n, count($pages));
                foreach ($shapes as $id => $shape) {
                    if (empty($shape['url'])) {
                        continue;
                    }
                    $link = $slideRels->addChild('Relationship');
                    $link->addAttribute('Id', 'link'.$id);
                    $link->addAttribute('Type', self::REL.'/hyperlink');
                    $link->addAttribute('Target', url($shape['url']));
                    $link->addAttribute('TargetMode', 'External');
                }
                $zip->addFromString($filename, view('ai.slide', compact('shapes'))->render());
                $zip->addFromString('ppt/slides/_rels/slide'.$n.'.xml.rels', $slideRels->asXML());
                if ($i === 0) {
                    continue;
                }
                $part = $manifest->addChild('Override');
                $part->addAttribute('PartName', '/'.$filename);
                $part->addAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.presentationml.slide+xml');
                $rel = $rels->addChild('Relationship');
                $rel->addAttribute('Id', 'slide'.$n);
                $rel->addAttribute('Type', self::REL.'/slide');
                $rel->addAttribute('Target', 'slides/slide'.$n.'.xml');
                $entry = $slideList->addChild('p:sldId', null, 'http://schemas.openxmlformats.org/presentationml/2006/main');
                $entry->addAttribute('id', (string) (256 + $i));
                $entry->addAttribute('r:id', 'slide'.$n, self::REL);
            }
            $zip->addFromString('[Content_Types].xml', $manifest->asXML());
            $zip->addFromString('ppt/presentation.xml', $presentation->asXML());
            $zip->addFromString('ppt/_rels/presentation.xml.rels', $rels->asXML());
            if (! $zip->close() || ($bytes = file_get_contents($work)) === false) {
                throw new RuntimeException(__('Не удалось сохранить слайды.'));
            }

            return $bytes;
        } finally {
            unset($zip);
            if (is_file($work)) {
                unlink($work);
            }
            fclose($temporary);
        }
    }

    /** Shape coordinates in inches on a 13⅓ × 7½ slide. */
    private function shapes(array $page, array $ctx, int $n, int $count): array
    {
        $edition = $ctx['edition'];
        $ink = substr($edition['ink'], 1);
        $paper = substr($edition['paper'], 1);
        $shapes = [];
        $box = function (float $x, float $y, float $w, float $h, string $text = '', int $size = 20,
            string $color = '183139', ?string $fill = null, bool $bold = false, ?string $url = null) use (&$shapes) {
            $shapes[] = compact('x', 'y', 'w', 'h', 'text', 'size', 'color', 'fill', 'bold', 'url');
        };
        $section = $page['section'];
        if ($section === 'cover') {
            $box(0, 0, 13.333, 7.5, fill: $ink);
            $box(.75, .55, 11.8, .5, 'BM Systems  /  '.$edition['name'], 18, 'FFFFFF');
            $box(.75, 2.05, 11.7, 2.55, $ctx['title'], 50, 'FFFFFF', bold: true);
            $box(.8, 5.9, 11.7, .9, $ctx['source']['context'], 17, $paper);

            return $shapes;
        }
        $titles = ['focus' => __('Первый показатель выборки'), 'chart' => __('Сопоставление значений'), 'table' => __('Исходные значения'),
            'extremes' => __('Границы значений'), 'cards' => __('Показатели'), 'rank' => __('По убыванию значения'), 'sources' => __('Источники данных')];
        $box(0, 0, 13.333, 7.5, fill: 'FFFFFF');
        $box(0, 0, 13.333, .11, fill: $ink);
        $box(.65, .25, 11.8, .42, $ctx['title'], 15, $ink);
        $box(.65, .85, 12, .73, $titles[$section], 34, $ink, bold: true);
        $box(.65, 6.9, 10.7, .38, $edition['name'], 12, $ink);
        $box(11.35, 6.9, 1.4, .38, $n.' / '.$count, 12, $ink);
        if ($section === 'sources') {
            foreach ($ctx['source']['items'] as $i => $item) {
                $box(.65, 1.85 + .8 * $i, 12, .63, $item['label'], 22, $ink, $paper, url: $item['url']);
            }
            $box(.65, 5.8, 12, .72, $ctx['source']['context'], 15);

            return $shapes;
        }
        $values = array_column($ctx['rows'], 'value');
        $min = min(0, min($values));
        $span = (max(0, max($values)) - $min) ?: 1;
        foreach ($page['rows'] as $i => $row) {
            $value = (string) $row['value'];
            if ($section === 'focus' || $section === 'extremes') {
                $w = $section === 'focus' ? 12 : 5.8;
                $x = .65 + $i * 6.15;
                $box($x, 2, $w, 4.2, fill: $paper);
                if ($section === 'extremes') {
                    $box($x + .25, 2.25, $w - .5, .5, $i === 0 ? __('Максимум') : __('Минимум'), 17, $ink);
                }
                $box($x + .25, 3.1, $w - .5, 1.1, $value, 44, $ink, bold: true);
                $box($x + .25, 4.55, $w - .5, 1.15, $row['label'], 25);
            } elseif ($section === 'cards') {
                $x = .65 + ($i % 3) * 4.05;
                $y = 1.95 + intdiv($i, 3) * 2.22;
                $box($x, $y, 3.89, 2.06, fill: $paper);
                $box($x + .12, $y + .18, 3.65, .76, $value, 31, $ink, bold: true);
                $box($x + .12, $y + 1, 3.65, .96, $row['label'], 17);
            } else {
                $y = 1.84 + .78 * $i;
                if ($section === 'table' && $i % 2 === 0) {
                    $box(.65, $y, 12, .72, fill: $paper);
                }
                $label = $section === 'rank' ? ($page['offset'] + $i + 1).'.   '.$row['label'] : $row['label'];
                $box(.75, $y, $section === 'chart' ? 4.3 : 9.2, .7, $label, 18);
                if ($section === 'chart') {
                    $zero = 5.3 + 5.4 * (0 - $min) / $span;
                    $end = 5.3 + 5.4 * ($row['value'] - $min) / $span;
                    $box(5.3, $y + .30, 5.4, .11, fill: $paper);
                    $box(min($end, $zero), $y + .23, max(.018, abs($end - $zero)), .25, fill: $ink);
                }
                $box(10.95, $y, 1.7, .7, $value, 19, $ink, bold: true);
            }
        }

        return $shapes;
    }
}
