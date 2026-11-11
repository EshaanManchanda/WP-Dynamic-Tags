# Performance baseline: seeds N tags into the disposable clone `local_perf` and measures fresh-process samples.
#
#   .\run.ps1 -Label baseline -Count 5000 -Seed      # seed + measure (clone must exist, see README)
#   .\run.ps1 -Label after    -Count 5000            # measure only
#
# Output: <Label>.json (per-sample results + medians). Never run against the live database: the scripts refuse.
param(
    [string]$Label = 'current',
    [int]$Count = 5000,
    [int]$Samples = 5,
    [switch]$Seed,
    [string]$Php = "$env:APPDATA\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe",
    [int]$MysqlPort = 10006
)
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$ext  = Join-Path (Split-Path $Php) 'ext'
$a = @('-n', '-d', "extension_dir=$ext")
foreach ($e in 'curl', 'openssl', 'mbstring', 'mysqli', 'fileinfo', 'gd', 'exif', 'intl', 'zip', 'sodium') { $a += @('-d', "extension=$e") }
$a += @('-d', 'memory_limit=1G', '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-d', 'date.timezone=UTC',
        '-d', "mysqli.default_port=$MysqlPort", '-d', 'mysqli.default_host=127.0.0.1')
$env:WP_DB_NAME = 'local_perf'

if ($Seed) { & $Php @a (Join-Path $here 'seed.php') $Count; if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE } }

$runs = @()
for ($i = 1; $i -le $Samples; $i++) {
    $out = Join-Path $env:TEMP "dt-perf-$Label-$i.json"
    $msg = (& $Php @a (Join-Path $here 'measure.php') 20 $out 2>&1 | Out-String).Trim()
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $out)) { Write-Error "sample $i failed: $msg"; exit 1 }
    $runs += (Get-Content $out -Raw | ConvertFrom-Json)
    Remove-Item $out
}
function Med($v) { $s = @($v | Sort-Object); $s[[int][math]::Floor(($s.Count - 1) / 2)] }
$med = [ordered]@{
    label = $Label; php = $runs[0].php; tag_rows = $runs[0].tag_rows; samples = $Samples
    boot_ms = Med ($runs | ForEach-Object { $_.boot.ms }); boot_peak_mb = Med ($runs | ForEach-Object { $_.boot.peak_mb })
    boot_queries_total = Med ($runs | ForEach-Object { $_.boot.queries.total }); boot_selects = Med ($runs | ForEach-Object { $_.boot.queries.select })
    boot_writes = Med ($runs | ForEach-Object { $_.boot.queries.writes }); boot_transient_writes = Med ($runs | ForEach-Object { $_.boot.queries.transient_writes })
    boot_schema_checks = Med ($runs | ForEach-Object { $_.boot.queries.schema_checks }); perf_shortcodes_registered = Med ($runs | ForEach-Object { $_.boot.perf_shortcodes_registered })
    render_calls = $runs[0].render.calls; render_ms = Med ($runs | ForEach-Object { $_.render.ms }); render_mem_growth_kb = Med ($runs | ForEach-Object { $_.render.mem_growth_kb })
    render_queries_total = Med ($runs | ForEach-Object { $_.render.queries.total }); render_writes = Med ($runs | ForEach-Object { $_.render.queries.writes })
    render_rendered_non_empty = Med ($runs | ForEach-Object { $_.render.rendered_non_empty })
}
$doc = [ordered]@{ medians = $med; runs = $runs }
$doc | ConvertTo-Json -Depth 8 | Set-Content (Join-Path $here "$Label.json") -Encoding utf8
$med | ConvertTo-Json | Write-Output
