$ErrorActionPreference = 'Stop'
$bad = $false
foreach ($layout in @('resources/views/layouts/tabler.blade.php', 'resources/views/layouts/auth.blade.php')) {
    $content = Get-Content -LiteralPath $layout -Raw -Encoding utf8
    foreach ($required in @('name="viewport"', 'name="csrf-token"', '<html lang=')) {
        if (-not $content.Contains($required)) { Write-Output "MISSING-METADATA: $layout $required"; $bad = $true }
    }
    foreach ($match in [regex]::Matches($content, '(?:href|src)="(/assets/[^"?]+)')) {
        $asset = Join-Path 'public' $match.Groups[1].Value.TrimStart('/')
        if (-not (Test-Path -LiteralPath $asset -PathType Leaf)) { Write-Output "MISSING-ASSET: $asset"; $bad = $true }
    }
}
Write-Output "LAYOUT-STRUCTURE-OK: $(-not $bad)"
Write-Output 'Browser appearance and accessibility require separate verification.'
if ($bad) { exit 1 }
