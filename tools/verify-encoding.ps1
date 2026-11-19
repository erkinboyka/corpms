$ErrorActionPreference = 'Stop'
$utf8 = [System.Text.UTF8Encoding]::new($false, $true)
$roots = @('app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'tests', 'docs', 'public/assets/js', 'public/assets/css')
$extensions = @('.php', '.js', '.css', '.md', '.json')
$bad = $false
$count = 0
foreach ($root in $roots) {
    foreach ($file in (Get-ChildItem -LiteralPath $root -Recurse -File)) {
        if ($file.Extension -notin $extensions) { continue }
        $count++
        try { $content = $utf8.GetString([System.IO.File]::ReadAllBytes($file.FullName)) }
        catch { Write-Output "INVALID-UTF8: $($file.FullName)"; $bad = $true; continue }
        if ($content.Contains([string][char]0xFFFD) -or $content.Contains(([string][char]0x0432 + [char]0x0402))) {
            Write-Output "DAMAGED-TEXT: $($file.FullName)"
            $bad = $true
        }
    }
}
Write-Output "FILES-CHECKED: $count"
Write-Output "ALL-OK: $(-not $bad)"
if ($bad) { exit 1 }
