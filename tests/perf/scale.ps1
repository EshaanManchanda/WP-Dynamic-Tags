# Scale run (Phase 9c): re-seeds the disposable clone `local_perf` with N rows, then measures the admin data paths.
#
#   .\scale.ps1 -Count 5000 -Out C:\path\scale-5000.json
#
# Seeding is destructive for the perf_* rows and scale.php writes to the clone, so both scripts refuse any database
# but `local_perf`. Never run against the live database.
param(
    [int]$Count = 5000,
    [Parameter(Mandatory)][string]$Out,
    [string]$Php = "$env:APPDATA\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe",
    [int]$MysqlPort = 10006
)
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$ext  = Join-Path (Split-Path $Php) 'ext'
$a = @('-n', '-d', "extension_dir=$ext")
foreach ($e in 'curl', 'openssl', 'mbstring', 'mysqli', 'fileinfo', 'gd', 'exif', 'intl', 'zip', 'sodium') { $a += @('-d', "extension=$e") }
$a += @('-d', 'memory_limit=2G', '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-d', 'date.timezone=UTC',
        '-d', "mysqli.default_port=$MysqlPort", '-d', 'mysqli.default_host=127.0.0.1')
$env:WP_DB_NAME = 'local_perf'

& $Php @a (Join-Path $here 'seed.php') $Count
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& $Php @a (Join-Path $here 'scale.php') $Out
exit $LASTEXITCODE
