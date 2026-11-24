<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use ZipArchive;

/**
 * RAG-01: best-effort извлечение текста из вложений документа.
 *
 * DOCX — XML word/document.xml внутри ZIP (абзацы, табы, переносы).
 * PDF — потоки /FlateDecode с операторами Tj/TJ. Если текстового слоя нет,
 * подключается OcrEngine: PDF rasterизируется pdftoppm, изображения и
 * встроенные в DOCX картинки распознаются Tesseract. Отсутствие OCR-бинарников
 * или повреждённый файл не прерывают индексацию title/body документа.
 */
class AttachmentTextExtractor
{
    public const TEXT = 'text';

    public const OCR = 'ocr';

    public const EMPTY = 'empty';

    public const UNSUPPORTED = 'unsupported';

    public const FAILED = 'failed';

    public function __construct(protected OcrEngine $ocr) {}

    /**
     * @return array{text:string,status:string,source:?string,error:?string}
     */
    public function extract(DocumentVersion $version): array
    {
        $path = (string) $version->file_path;
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return $this->result('', self::FAILED, null, 'Файл версии отсутствует в хранилище');
        }

        $absolute = Storage::disk('local')->path($path);
        $maxBytes = max(1, (int) config('corpms.ai.rag.max_file_bytes', 20 * 1024 * 1024));
        $size = @filesize($absolute);

        if ($size === false || $size > $maxBytes) {
            return $this->result('', self::UNSUPPORTED, null, 'Файл слишком большой для индексации');
        }

        $format = $this->format($version);
        if ($format === null) {
            return $this->result('', self::UNSUPPORTED, null, 'Формат вложения не поддерживается');
        }

        try {
            $bytes = (string) @file_get_contents($absolute, false, null, 0, $maxBytes);
            if ($bytes === '') {
                return $this->result('', self::FAILED, 'file:'.$format, 'Файл версии пуст');
            }

            if ($format === 'pdf' && preg_match('~/Encrypt\b~', substr($bytes, 0, 4096)) === 1) {
                return $this->result('', self::UNSUPPORTED, 'file:pdf', 'PDF защищён паролем');
            }

            if ($format === 'docx' || $format === 'pdf') {
                $text = $this->normalize($format === 'docx' ? $this->fromDocx($bytes) : $this->fromPdf($bytes));
                if ($this->hasText($text)) {
                    return $this->limited($text, self::TEXT, 'file:'.$format);
                }

                $ocr = $format === 'pdf' ? $this->ocr->pdf($absolute) : $this->ocr->docxImages($absolute);
            } else {
                $ocr = $this->ocr->image($absolute, 'file:image+ocr');
            }
        } catch (Throwable $e) {
            return $this->result('', self::FAILED, 'file:'.$format, mb_substr($e->getMessage(), 0, 255));
        }

        if ($this->hasText($ocr['text'])) {
            return $this->limited($ocr['text'], self::OCR, $ocr['source'] ?? 'file:'.$format.'+ocr');
        }

        return $this->result('', $ocr['status'], $ocr['source'] ?? 'file:'.$format.'+ocr', $ocr['error']);
    }

    /** @return array{text:string,status:string,source:?string,error:?string} */
    private function result(string $text, string $status, ?string $source, ?string $error): array
    {
        return [
            'text' => $text,
            'status' => $status,
            'source' => $source,
            'error' => $error,
        ];
    }

    private function hasText(string $text): bool
    {
        return $text !== '' && preg_match('/\p{L}|\p{N}/u', $text) === 1;
    }

    /** @return array{text:string,status:string,source:?string,error:?string} */
    private function limited(string $text, string $status, string $source): array
    {
        $maxChars = max(1000, (int) config('corpms.ai.rag.max_extract_chars', 500000));

        return $this->result(mb_substr($text, 0, $maxChars), $status, $source, null);
    }

    private function format(DocumentVersion $version): ?string
    {
        $mime = mb_strtolower((string) $version->mime_type);
        $name = mb_strtolower((string) $version->file_name);

        if (str_contains($mime, 'wordprocessingml.document') || str_ends_with($name, '.docx')) {
            return 'docx';
        }

        if ($mime === 'application/pdf' || str_ends_with($name, '.pdf')) {
            return 'pdf';
        }

        foreach (['png', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp', 'webp'] as $image) {
            if (str_ends_with($name, '.'.$image) || str_contains($mime, '/'.$image)) {
                return 'image';
            }
        }

        return null;
    }

    private function fromDocx(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'corpms_rag_docx_');
        if ($tmp === false) {
            throw new RuntimeException('Не удалось создать временный файл для DOCX');
        }

        try {
            file_put_contents($tmp, $bytes);

            $zip = new ZipArchive;
            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('DOCX не является корректным ZIP-архивом');
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if (! is_string($xml) || $xml === '') {
                throw new RuntimeException('В DOCX отсутствует word/document.xml');
            }

            return $this->docxXmlToText($xml);
        } finally {
            @unlink($tmp);
        }
    }

    private function docxXmlToText(string $xml): string
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
            if ($document === false) {
                throw new RuntimeException('Не удалось разобрать XML документа DOCX');
            }

            $document->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $paragraphs = $document->xpath('//w:p');
            if ($paragraphs === false) {
                return '';
            }

            $lines = [];
            foreach ($paragraphs as $paragraph) {
                $nodes = $paragraph->xpath('.//w:t | .//w:tab | .//w:br | .//w:cr');
                $line = '';

                foreach ($nodes ?: [] as $node) {
                    $tag = $node->getName();
                    if ($tag === 't') {
                        $line .= (string) $node;
                    } elseif ($tag === 'tab') {
                        $line .= ' ';
                    } else {
                        $line .= "\n";
                    }
                }

                if (trim($line) !== '') {
                    $lines[] = $line;
                }
            }

            return implode("\n", $lines);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function fromPdf(string $bytes): string
    {
        if (! str_starts_with($bytes, '%PDF-')) {
            throw new RuntimeException('Файл не является PDF');
        }

        preg_match_all('~stream\r?\n(.*?)\r?\nendstream~s', $bytes, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $text = '';
        foreach ($matches as $match) {
            $raw = (string) $match[1][0];
            $offset = (int) $match[0][1];
            $dictionary = substr($bytes, max(0, $offset - 300), 300);

            // image-потоки не содержат текста
            if (preg_match('~/(DCTDecode|JPXDecode|CCITTFaxDecode|JBIG2Decode)~', $dictionary) === 1) {
                continue;
            }

            if (preg_match('~/FlateDecode~', $dictionary) === 1) {
                $decoded = @gzuncompress($raw);
                if ($decoded === false) {
                    $decoded = @gzinflate($raw);
                }
                if ($decoded === false) {
                    continue;
                }
                $content = $decoded;
            } else {
                $content = $raw;
            }

            $text .= $this->pdfTextOperands($content);
        }

        return $text;
    }

    private function pdfTextOperands(string $content): string
    {
        $out = '';

        preg_match_all(
            '~((?:\((?:\\\\.|[^()\\\\])*\)|<[0-9A-Fa-f\\s]+>|\])\s*)(Tj|TJ)~s',
            $content,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($matches as $match) {
            $operator = (string) $match[2][0];
            $operand = trim((string) $match[1][0]);

            if ($operator === 'Tj') {
                $out .= $this->decodePdfString($operand).' ';

                continue;
            }

            $position = (int) $match[0][1];
            $open = strrpos(substr($content, 0, $position), '[');
            if ($open === false) {
                continue;
            }

            $array = substr($content, $open, $position - $open + 1);
            preg_match_all('~\((?:\\\\.|[^()\\\\])*\)|<[0-9A-Fa-f\\s]+>~s', $array, $strings);

            foreach ($strings[0] as $string) {
                $out .= $this->decodePdfString((string) $string);
            }

            $out .= ' ';
        }

        return $out;
    }

    private function decodePdfString(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        if ($token[0] === '<') {
            $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', trim($token, '<>'));
            if (strlen($hex) % 2 === 1) {
                $hex .= '0';
            }
            $bytes = $hex === '' ? '' : (string) hex2bin($hex);
        } else {
            $body = substr($token, 1, -1);
            $body = (string) preg_replace_callback(
                '~\\\\([nrtbf()\\\\]|[0-7]{1,3}|\r\n|\n)~',
                static function (array $match): string {
                    $escape = $match[1];

                    return match ($escape) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        'b' => "\x08",
                        'f' => "\x0C",
                        '(', ')', '\\' => $escape,
                        "\r\n", "\n" => '',
                        default => chr(octdec($escape)),
                    };
                },
                $body,
            );
            $bytes = $body;
        }

        $text = mb_check_encoding($bytes, 'UTF-8')
            ? $bytes
            : (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
    }

    private function normalize(string $text): string
    {
        $text = (string) preg_replace('/[ \t\x00-\x08\x0B\x0C\x0E-\x1F]+/u', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }
}
