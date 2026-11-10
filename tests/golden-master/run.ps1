# Run the golden master: renders every fixture x invocation in a fresh PHP process per path/context.
#
#   .\run.ps1 -Label baseline -Paths post,table      # pre-refactor commit only (needs both registrars)
#   .\run.ps1 -Label current  -Paths unified         # after the table-only refactor
#
# -Php / -MysqlPort default to this Local site (PHP 8.2.29, MySQL port 10006). Output goes to <Label>/.
param(
    [string]$Label = 'current',
    [string]$Paths = 'post,table',
    [string]$Php = "$env:APPDATA\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe",
    [int]$MysqlPort = 10006
)
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$ext  = Join-Path (Split-Path $Php) 'ext'
$args0 = @('-n', '-d', "extension_dir=$ext")
foreach ($e in 'curl', 'openssl', 'mbstring', 'mysqli', 'fileinfo', 'gd', 'exif', 'intl', 'zip', 'sodium') { $args0 += @('-d', "extension=$e") }
$args0 += @('-d', 'memory_limit=1G', '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-d', 'date.timezone=UTC',
            '-d', "mysqli.default_port=$MysqlPort", '-d', 'mysqli.default_host=127.0.0.1')

$dir = Join-Path $here $Label
New-Item -ItemType Directory -Force $dir | Out-Null
$failed = 0
foreach ($p in $Paths.Split(',')) {
    foreach ($c in 'out_singular', 'in_singular', 'out_archive') {
        $out = Join-Path $dir "${p}__${c}.json"
        $r = (& $Php @args0 (Join-Path $here 'render.php') $p $c $out 2>&1 | Out-String).Trim()
        $r
        if ($r -notmatch '^OK ') { $failed++ }
    }
}
exit $failed
