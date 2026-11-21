<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Компактный генератор QR-кодов без внешних зависимостей.
 *
 * Поддерживает байтовый режим, версии 1–10, уровень коррекции L.
 * Этого достаточно для otpauth:// URL двухфакторной аутентификации.
 * Результат — SVG-строка (масштабируется без потери качества).
 */
final class QrCodeGenerator
{
    /** Общее число кодовых слов по версиям. */
    private const TOTAL = [
        1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134,
        6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346,
    ];

    /** Уровень L: [число блоков, контрольных слов в блоке]. */
    private const BLOCKS = [
        1 => [1, 7], 2 => [1, 10], 3 => [1, 15], 4 => [1, 20], 5 => [1, 26],
        6 => [2, 18], 7 => [2, 20], 8 => [2, 24], 9 => [2, 30], 10 => [4, 18],
    ];

    /** Позиции alignment-паттернов. */
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    private static array $exp = [];
    private static array $log = [];

    public static function svg(string $text, int $size = 200, string $class = ''): string
    {
        $matrix = self::build($text);
        $count = count($matrix);
        $quiet = 4;
        $total = $count + $quiet * 2;
        $cell = $size / $total;

        $rects = [];
        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $rects[] = sprintf(
                        '<rect x="%.3f" y="%.3f" width="%.3f" height="%.3f"/>',
                        ($x + $quiet) * $cell,
                        ($y + $quiet) * $cell,
                        $cell,
                        $cell,
                    );
                }
            }
        }

        $cls = $class !== '' ? ' class="'.e($class).'"' : '';

        return '<svg'.$cls.' xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" '
            .'viewBox="0 0 '.$size.' '.$size.'" shape-rendering="crispEdges" role="img" aria-label="QR">'
            .'<rect width="'.$size.'" height="'.$size.'" fill="#fff"/>'
            .'<g fill="#000">'.implode('', $rects).'</g></svg>';
    }

    /** @return array<int, array<int, bool>> */
    private static function build(string $text): array
    {
        self::initGf();
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = self::pickVersion(count($bytes));
        $size = 17 + 4 * $version;

        [$numBlocks, $ecPerBlock] = self::BLOCKS[$version];
        $totalData = self::TOTAL[$version] - $numBlocks * $ecPerBlock;

        $stream = self::bitStream($bytes, $version, $totalData);
        $codewords = self::interleave($stream, $numBlocks, $ecPerBlock);

        $matrix = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        self::placeFinders($matrix, $reserved, $size);
        self::placeTiming($matrix, $reserved, $size);
        self::placeAlignment($matrix, $reserved, $size, $version);
        self::placeFormatReserve($reserved, $size);
        if ($version >= 7) {
            self::placeVersionReserve($reserved, $size);
        }
        $matrix[4 * $version + 9][8] = true;
        $reserved[4 * $version + 9][8] = true;

        self::placeData($matrix, $reserved, $size, $codewords);

        // Выбираем наилучшую маску из 8 стандартных.
        $best = null;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $matrix;
            self::applyMask($candidate, $reserved, $size, $mask);
            self::writeFormat($candidate, $size, $mask);
            if ($version >= 7) {
                self::writeVersion($candidate, $size, $version);
            }
            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        return $best ?? $matrix;
    }

    private static function pickVersion(int $length): int
    {
        for ($v = 1; $v <= 10; $v++) {
            [$blocks, $ec] = self::BLOCKS[$v];
            $capacity = self::TOTAL[$v] - $blocks * $ec;
            $countBits = $v < 10 ? 8 : 16;
            // 4 бита индикатора + счётчик + данные + 4 бита терминатора
            if (4 + $countBits + $length * 8 + 4 <= $capacity * 8) {
                return $v;
            }
        }

        throw new \InvalidArgumentException('QR: данные слишком длинные (макс. версия 10)');
    }

    /** @param array<int, int> $bytes */
    private static function bitStream(array $bytes, int $version, int $capacity): array
    {
        $bits = [];
        $put = static function (int $value, int $len) use (&$bits): void {
            for ($i = $len - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };

        $put(0b0100, 4); // байтовый режим
        $put(count($bytes), $version < 10 ? 8 : 16);
        foreach ($bytes as $byte) {
            $put($byte, 8);
        }

        $limit = $capacity * 8;
        for ($i = 0; $i < 4 && count($bits) < $limit; $i++) {
            $bits[] = 0; // терминатор
        }
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $out = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            $value = 0;
            foreach ($chunk as $bit) {
                $value = ($value << 1) | $bit;
            }
            $out[] = $value;
        }

        $pad = [0xEC, 0x11];
        $index = 0;
        while (count($out) < $capacity) {
            $out[] = $pad[$index % 2];
            $index++;
        }

        return $out;
    }

    /** @param array<int, int> $data */
    private static function interleave(array $data, int $numBlocks, int $ecPerBlock): array
    {
        $totalData = count($data);
        $shortLen = intdiv($totalData, $numBlocks);
        $longCount = $totalData % $numBlocks;
        $longLen = $shortLen + 1;

        $blocks = [];
        $offset = 0;
        $shortCount = $numBlocks - $longCount;
        for ($i = 0; $i < $numBlocks; $i++) {
            $len = $i < $shortCount ? $shortLen : $longLen;
            $blocks[] = array_slice($data, $offset, $len);
            $offset += $len;
        }

        $ecBlocks = [];
        $generator = self::rsGenerator($ecPerBlock);
        foreach ($blocks as $block) {
            $ecBlocks[] = self::rsEncode($block, $generator);
        }

        $result = [];
        for ($i = 0; $i < $longLen; $i++) {
            foreach ($blocks as $index => $block) {
                if ($i < count($block)) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $ec) {
                $result[] = $ec[$i];
            }
        }

        return $result;
    }

    private static function initGf(): void
    {
        if (self::$exp !== []) {
            return;
        }
        self::$exp = array_fill(0, 512, 0);
        self::$log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if (($x & 0x100) !== 0) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /** @return array<int, int> */
    private static function rsGenerator(int $degree): array
    {
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= self::mul($coef, self::$exp[$i]);
            }
            $poly = $next;
        }

        return $poly;
    }

    /**
     * @param array<int, int> $data
     * @param array<int, int> $generator
     * @return array<int, int>
     */
    private static function rsEncode(array $data, array $generator): array
    {
        $degree = count($generator) - 1;
        $residue = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $residue[0];
            array_shift($residue);
            $residue[] = 0;
            for ($i = 0; $i < $degree; $i++) {
                $residue[$i] ^= self::mul($generator[$i + 1], $factor);
            }
        }

        return $residue;
    }

    /**
     * @param array<int, array<int, bool>> $matrix
     * @param array<int, array<int, bool>> $reserved
     */
    private static function placeFinders(array &$matrix, array &$reserved, int $size): void
    {
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x < 0 || $x >= $size || $y < 0 || $y >= $size) {
                        continue;
                    }
                    $dist = max(abs($dx), abs($dy));
                    $matrix[$y][$x] = $dist !== 2 && $dist !== 4;
                    $reserved[$y][$x] = true;
                }
            }
        }
    }

    /**
     * @param array<int, array<int, bool>> $matrix
     * @param array<int, array<int, bool>> $reserved
     */
    private static function placeTiming(array &$matrix, array &$reserved, int $size): void
    {
        for ($i = 0; $i < $size; $i++) {
            if (! $reserved[6][$i]) {
                $matrix[6][$i] = $i % 2 === 0;
                $reserved[6][$i] = true;
            }
            if (! $reserved[$i][6]) {
                $matrix[$i][6] = $i % 2 === 0;
                $reserved[$i][6] = true;
            }
        }
    }

    /**
     * @param array<int, array<int, bool>> $matrix
     * @param array<int, array<int, bool>> $reserved
     */
    private static function placeAlignment(array &$matrix, array &$reserved, int $size, int $version): void
    {
        $positions = self::ALIGN[$version];
        $last = count($positions) - 1;
        foreach ($positions as $iy => $cy) {
            foreach ($positions as $ix => $cx) {
                if (($ix === 0 && $iy === 0) || ($ix === 0 && $iy === $last) || ($ix === $last && $iy === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $x = $cx + $dx;
                        $y = $cy + $dy;
                        $matrix[$y][$x] = max(abs($dx), abs($dy)) !== 1;
                        $reserved[$y][$x] = true;
                    }
                }
            }
        }
    }

    /** @param array<int, array<int, bool>> $reserved */
    private static function placeFormatReserve(array &$reserved, int $size): void
    {
        for ($i = 0; $i <= 8; $i++) {
            $reserved[$i][8] = true;
            $reserved[8][$i] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[$size - 1 - $i][8] = true;
            $reserved[8][$size - 1 - $i] = true;
        }
    }

    /** @param array<int, array<int, bool>> $reserved */
    private static function placeVersionReserve(array &$reserved, int $size): void
    {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $reserved[$i][$size - 11 + $j] = true;
                $reserved[$size - 11 + $j][$i] = true;
            }
        }
    }

    /**
     * @param array<int, array<int, bool>> $matrix
     * @param array<int, array<int, bool>> $reserved
     * @param array<int, int> $codewords
     */
    private static function placeData(array &$matrix, array &$reserved, int $size, array $codewords): void
    {
        $inc = -1;
        $row = $size - 1;
        $bitIndex = 7;
        $byteIndex = 0;
        $total = count($codewords);
        $col = $size - 1;

        while ($col > 0) {
            if ($col === 6) {
                $col = 5;
            }
            for ($step = 0; $step < 2; $step++) {
                $x = $col - $step;
                if (! $reserved[$row][$x]) {
                    $byte = $byteIndex < $total ? $codewords[$byteIndex] : 0;
                    $matrix[$row][$x] = (($byte >> $bitIndex) & 1) === 1;
                    $bitIndex--;
                    if ($bitIndex < 0) {
                        $bitIndex = 7;
                        $byteIndex++;
                    }
                }
            }
            $row += $inc;
            if ($row < 0 || $row >= $size) {
                $row -= $inc;
                $inc = -$inc;
                $col -= 2;
            }
        }
    }

    /**
     * @param array<int, array<int, bool>> $matrix
     * @param array<int, array<int, bool>> $reserved
     */
    private static function applyMask(array &$matrix, array &$reserved, int $size, int $mask): void
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($reserved[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if ($invert) {
                    $matrix[$y][$x] = ! $matrix[$y][$x];
                }
            }
        }
    }

    /** @param array<int, array<int, bool>> $matrix */
    private static function writeFormat(array &$matrix, int $size, int $mask): void
    {
        // Уровень L = 01.
        $data = (1 << 3) | $mask;
        $remainder = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($remainder & (1 << $i)) !== 0) {
                $remainder ^= 0x537 << ($i - 10);
            }
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;

        // Биты нумеруются от MLB (14) к LSB (0): см. эталонную раскладку QR.
        for ($i = 0; $i < 6; $i++) {
            $matrix[8][$i] = (bool) (($bits >> (14 - $i)) & 1);
        }
        $matrix[8][7] = (bool) (($bits >> 8) & 1);
        $matrix[8][8] = (bool) (($bits >> 7) & 1);
        $matrix[7][8] = (bool) (($bits >> 6) & 1);
        for ($i = 9; $i < 15; $i++) {
            $matrix[14 - $i][8] = (bool) (($bits >> (14 - $i)) & 1);
        }
        for ($i = 0; $i < 7; $i++) {
            $matrix[$size - 1 - $i][8] = (bool) (($bits >> (14 - $i)) & 1);
        }
        for ($i = 7; $i < 15; $i++) {
            $matrix[8][$size - 15 + $i] = (bool) (($bits >> (14 - $i)) & 1);
        }
        $matrix[$size - 8][8] = true;
    }

    /** @param array<int, array<int, bool>> $matrix */
    private static function writeVersion(array &$matrix, int $size, int $version): void
    {
        $remainder = $version << 12;
        for ($i = 17; $i >= 12; $i--) {
            if (($remainder & (1 << $i)) !== 0) {
                $remainder ^= 0x1F25 << ($i - 12);
            }
        }
        $bits = ($version << 12) | $remainder;
        for ($i = 0; $i < 18; $i++) {
            $bit = (bool) (($bits >> $i) & 1);
            $matrix[intdiv($i, 3)][$size - 11 + ($i % 3)] = $bit;
            $matrix[$size - 11 + ($i % 3)][intdiv($i, 3)] = $bit;
        }
    }

    /**
     * @param array<int, array<int, bool>> $matrix
     */
    private static function penalty(array $matrix, int $size): int
    {
        $score = 0;

        // Правило 1: длинные ряды одного цвета.
        foreach ($matrix as $row) {
            $score += self::runPenalty($row);
        }
        for ($x = 0; $x < $size; $x++) {
            $column = array_column($matrix, $x);
            $score += self::runPenalty($column);
        }

        // Правило 2: блоки 2×2.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $v = $matrix[$y][$x];
                if ($v === $matrix[$y][$x + 1] && $v === $matrix[$y + 1][$x] && $v === $matrix[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // Правило 4: доля тёмных модулей.
        $dark = 0;
        foreach ($matrix as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $dark++;
                }
            }
        }
        $percent = $dark * 100 / ($size * $size);
        $score += (int) (abs($percent - 50) / 5) * 10;

        return $score;
    }

    /** @param array<int, bool> $line */
    private static function runPenalty(array $line): int
    {
        $score = 0;
        $run = 1;
        $previous = $line[0];
        $count = count($line);
        for ($i = 1; $i < $count; $i++) {
            if ($line[$i] === $previous) {
                $run++;
            } else {
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
                $run = 1;
                $previous = $line[$i];
            }
        }
        if ($run >= 5) {
            $score += 3 + ($run - 5);
        }

        return $score;
    }
}
