<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * RAG-01: OCR-движок для скан-PDF и изображений.
 *
 * Tesseract распознаёт растровые изображения; pdftoppm (Poppler) превращает
 * страницы скан-PDF в PNG. Бинарники ищутся в PATH, в стандартных каталогах
 * Windows и в путях, заданных в corpms.ai.rag.*. Команды запускаются массивом
 * аргументов без shell, поэтому путь к файлу не может стать командой.
 * Отсутствие бинарников — это unsupported, а не падение индексации.
 */
class OcrEngine
{
    public const OCR = 'ocr';

    public const EMPTY = 'empty';

    public const UNSUPPORTED = 'unsupported';

    public const FAILED = 'failed';

    public function supportsImages(): bool
    {
        return $this->tesseractBinary() !== null;
    }

    public function supportsPdf(): bool
    {
        return $this->tesseractBinary() !== null && $this->popplerBinary() !== null;
    }

    /**
     * @return array{text:string,status:string,source:string,error:?string}
     */
    public function image(string $absolute, string $source = 'file:image+ocr'): array
    {
        $binary = $this->tesseractBinary();
        if ($binary === null) {
            return $this->result('', self::UNSUPPORTED, $source, 'Tesseract не найден');
        }

        return $this->ocrFile($absolute, $binary, $source);
    }

    /**
     * @return array{text:string,status:string,source:string,error:?string}
     */
    public function pdf(string $absolute): array
    {
        if (! $this->supportsPdf()) {
            return $this->result('', self::UNSUPPORTED, 'file:pdf+ocr', 'Tesseract или pdftoppm не найден');
        }

        $prefix = $this->tempPath('corpms_rag_pdf_');
        if (! mkdir($prefix, 0700, true) && ! is_dir($prefix)) {
            return $this->result('', self::FAILED, 'file:pdf+ocr', 'Не удалось создать временный каталог для OCR');
        }

        try {
            $dpi = max(72, (int) config('corpms.ai.rag.ocr_dpi', 200));
            $maxPages = max(1, (int) config('corpms.ai.rag.ocr_max_pages', 10));
            $renderPrefix = $prefix.DIRECTORY_SEPARATOR.'page';

            $render = $this->run([
                (string) $this->popplerBinary(),
                '-r', (string) $dpi,
                '-png',
                '-gray',
                '-f', '1',
                '-l', (string) $maxPages,
                $absolute,
                $renderPrefix,
            ]);

            if (! $render['ok']) {
                return $this->result('', self::FAILED, 'file:pdf+ocr', $render['error'] ?? 'Не удалось отрендерить PDF');
            }

            $images = glob($renderPrefix.'*.png') ?: [];
            sort($images);

            if ($images === []) {
                return $this->result('', self::EMPTY, 'file:pdf+ocr', null);
            }

            return $this->ocrImages($images, 'file:pdf+ocr');
        } finally {
            $this->deleteDirectory($prefix);
        }
    }

    /**
     * OCR изображений, встроенных в DOCX (например, вставленный скан).
     *
     * @return array{text:string,status:string,source:string,error:?string}
     */
    public function docxImages(string $absolute): array
    {
        $binary = $this->tesseractBinary();
        if ($binary === null) {
            return $this->result('', self::UNSUPPORTED, 'file:docx+ocr', 'Tesseract не найден');
        }

        $zip = new ZipArchive;
        if ($zip->open($absolute) !== true) {
            return $this->result('', self::FAILED, 'file:docx+ocr', 'DOCX не является корректным ZIP-архивом');
        }

        $dir = $this->tempPath('corpms_rag_docx_');
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $zip->close();

            return $this->result('', self::FAILED, 'file:docx+ocr', 'Не удалось создать временный каталог для OCR');
        }

        $images = [];
        $max = max(1, (int) config('corpms.ai.rag.ocr_max_pages', 10));

        try {
            for ($i = 0; $i < $zip->numFiles && count($images) < $max; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('~\.(png|jpe?g|tiff?|bmp|webp)$~i', $name) !== 1) {
                    continue;
                }

                $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
                $local = $dir.DIRECTORY_SEPARATOR.count($images).'.'.$extension;
                $bytes = $zip->getFromIndex($i);
                if (! is_string($bytes) || file_put_contents($local, $bytes) === false) {
                    continue;
                }
                $images[] = $local;
            }
        } finally {
            $zip->close();
        }

        try {
            if ($images === []) {
                return $this->result('', self::EMPTY, 'file:docx+ocr', null);
            }

            return $this->ocrImages($images, 'file:docx+ocr', $binary);
        } finally {
            $this->deleteDirectory($dir);
        }
    }

    /**
     * @param  array<int,string>  $images
     * @return array{text:string,status:string,source:string,error:?string}
     */
    private function ocrImages(array $images, string $source, ?string $binary = null): array
    {
        $binary ??= $this->tesseractBinary();
        if ($binary === null) {
            return $this->result('', self::UNSUPPORTED, $source, 'Tesseract не найден');
        }

        $parts = [];
        $firstError = null;
        $sawFailure = false;
        $sawUnsupported = false;

        foreach ($images as $image) {
            $result = $this->ocrFile($image, $binary, $source);
            if ($result['text'] !== '') {
                $parts[] = $result['text'];
            }
            $firstError ??= $result['error'];
            $sawFailure = $sawFailure || $result['status'] === self::FAILED;
            $sawUnsupported = $sawUnsupported || $result['status'] === self::UNSUPPORTED;
        }

        $text = $this->normalize(implode("\n", $parts));
        if ($text !== '') {
            return $this->result($text, self::OCR, $source, null);
        }

        $status = $sawUnsupported ? self::UNSUPPORTED : ($sawFailure ? self::FAILED : self::EMPTY);

        return $this->result('', $status, $source, $firstError);
    }

    /**
     * @return array{text:string,status:string,source:string,error:?string}
     */
    private function ocrFile(string $absolute, string $binary, string $source): array
    {
        $languages = $this->languages($binary);
        $psm = max(3, min(13, (int) config('corpms.ai.rag.ocr_psm', 6)));

        $process = $this->run([
            $binary,
            $absolute,
            'stdout',
            '-l', $languages,
            '--psm', (string) $psm,
            '-c', 'preserve_interword_spaces=1',
        ]);

        if (! $process['ok']) {
            return $this->result('', self::FAILED, $source, $process['error'] ?? 'Tesseract завершился с ошибкой');
        }

        $text = $this->normalize((string) ($process['output'] ?? ''));
        if ($text === '' || preg_match('/\p{L}|\p{N}/u', $text) !== 1) {
            return $this->result('', self::EMPTY, $source, null);
        }

        return $this->result($text, self::OCR, $source, null);
    }

    /** @return array{ok:bool,output:string,error:?string} */
    private function run(array $command): array
    {
        $timeout = max(5.0, (float) config('corpms.ai.rag.ocr_timeout', 120));

        try {
            $process = new Process($command, null, ['OMP_THREAD_LIMIT' => '1'], $timeout);
            $process->run();
        } catch (Throwable $e) {
            return ['ok' => false, 'output' => '', 'error' => $this->cleanError($e->getMessage())];
        }

        if (! $process->isSuccessful()) {
            return [
                'ok' => false,
                'output' => $process->getOutput(),
                'error' => $this->cleanError($process->getErrorOutput() !== ''
                    ? $process->getErrorOutput()
                    : 'Команда завершилась с кодом '.$process->getExitCode()),
            ];
        }

        return ['ok' => true, 'output' => $process->getOutput(), 'error' => null];
    }

    private function tesseractBinary(): ?string
    {
        return $this->binary('corpms.ai.rag.tesseract_path', [
            'tesseract',
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
            (string) env('LOCALAPPDATA', '').'\\Programs\\Tesseract-OCR\\tesseract.exe',
            '/usr/bin/tesseract',
            '/usr/local/bin/tesseract',
        ], '--version');
    }

    private function popplerBinary(): ?string
    {
        $local = (string) env('LOCALAPPDATA', '');

        return $this->binary('corpms.ai.rag.pdftoppm_path', [
            'pdftoppm',
            $local.'\\Microsoft\\WinGet\\Packages\\oschwartz10612.Poppler_*\\poppler-*\\Library\\bin\\pdftoppm.exe',
            $local.'\\Microsoft\\WinGet\\Links\\pdftoppm.exe',
            'C:\\Program Files\\poppler\\Library\\bin\\pdftoppm.exe',
            'C:\\Program Files\\poppler\\bin\\pdftoppm.exe',
            '/usr/bin/pdftoppm',
            '/usr/local/bin/pdftoppm',
        ], '-v');
    }

    private function binary(string $configKey, array $candidates, string $probeArgument): ?string
    {
        $configured = trim((string) config($configKey, ''));
        if ($configured !== '') {
            return $this->isExecutable($configured, $probeArgument) ? $configured : null;
        }

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '*')) {
                $matches = glob($candidate) ?: [];
                sort($matches);
                foreach ($matches as $match) {
                    if ($this->isExecutable($match, $probeArgument)) {
                        return $match;
                    }
                }

                continue;
            }

            if ($this->isExecutable($candidate, $probeArgument)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isExecutable(string $candidate, string $probeArgument): bool
    {
        $looksLikePath = str_contains($candidate, '/')
            || str_contains($candidate, '\\')
            || preg_match('/^[A-Za-z]:/', $candidate) === 1;

        if ($looksLikePath) {
            return is_file($candidate)
                && (is_executable($candidate) || DIRECTORY_SEPARATOR === '\\');
        }

        $process = $this->run([$candidate, $probeArgument]);

        return $process['ok'];
    }

    private function languages(string $binary): string
    {
        $requested = array_values(array_unique(array_filter(
            preg_split('/[+,\s]+/', (string) config('corpms.ai.rag.ocr_lang', 'eng+rus'), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $lang): bool => preg_match('/^[a-z_]{2,16}$/i', $lang) === 1,
        )));

        if ($requested === []) {
            return 'eng';
        }

        $listed = $this->run([$binary, '--list-langs']);
        if (! $listed['ok']) {
            return implode('+', $requested);
        }

        $available = [];
        foreach (preg_split('/\R/u', (string) $listed['output'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $lang = trim($line);
            if (preg_match('/^[a-z_]{2,16}$/i', $lang) === 1) {
                $available[] = $lang;
            }
        }

        $intersection = array_values(array_intersect($requested, $available));

        return $intersection === [] ? 'eng' : implode('+', $intersection);
    }

    /** @return array{text:string,status:string,source:string,error:?string} */
    private function result(string $text, string $status, string $source, ?string $error): array
    {
        return [
            'text' => $text,
            'status' => $status,
            'source' => $source,
            'error' => $error,
        ];
    }

    private function cleanError(string $error): string
    {
        $error = (string) preg_replace('/\s+/u', ' ', trim($error));

        return mb_substr($error, 0, 255);
    }

    private function normalize(string $text): string
    {
        $text = (string) preg_replace('/[ \t\x00-\x08\x0B\x0C\x0E-\x1F]+/u', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);

        return trim($text);
    }

    private function tempPath(string $prefix): string
    {
        try {
            $suffix = bin2hex(random_bytes(6));
        } catch (RuntimeException) {
            $suffix = (string) getmypid();
        }

        return sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.$suffix;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }
}
