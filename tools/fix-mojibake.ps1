# Repair double-encoding (UTF-8 -> CP1251 -> UTF-8) in blade files. ASCII-only script.
$utf8 = [System.Text.UTF8Encoding]::new($false)
$cp1251 = [System.Text.Encoding]::GetEncoding(1251)

# mojibake markers: 'v' cyr (U+0432) + 'Dje' (U+0402) = "v Dje" from em-dash/quotes,
# and Cyrillic Er (U+0420) / Es (U+0421) followed by Latin-1 supplement specials.
$ve = [char]0x0432
$dje = [char]0x0402
$er = [char]0x0420
$es = [char]0x0421
$specials = ""
foreach ($code in @(0xB0, 0xB5, 0x86, 0x87, 0xAC, 0x82, 0x83, 0xB6, 0xAE, 0xB9)) {
    $specials += [char]$code
}
$pattern = [regex]::Escape("$ve$dje") + "|" + "[" + $er + $es + "]" + "[" + $specials + "]"
$marker = [regex]$pattern

$fixed = 0
$skipped = 0
Get-ChildItem resources\views -Recurse -File -Filter *.blade.php |
    Where-Object { $_.FullName -notmatch '\\vendor\\' } |
    ForEach-Object {
        $path = $_.FullName
        $corrupted = [System.IO.File]::ReadAllText($path, $utf8)
        if (-not $marker.IsMatch($corrupted)) {
            $skipped++
            return
        }
        try {
            $bytes = $cp1251.GetBytes($corrupted)
            $repaired = $utf8.GetString($bytes)
        } catch {
            Write-Output "FAIL-DECODE: $path"
            return
        }
        $recheck = $cp1251.GetString($utf8.GetBytes($repaired))
        if ($recheck -ceq $corrupted) {
            [System.IO.File]::WriteAllText($path, $repaired, $utf8)
            $fixed++
        } else {
            Write-Output "SKIP-MISMATCH: $path"
        }
    }
Write-Output "repaired: $fixed  skipped(no-marker): $skipped"
