# Runs the WordPress core test suite for the plugin against an extracted WordPress and a disposable database.
#
#   .\run.ps1 -Core 7.1.4 [-Filter name]      # uses scratch\wp-core-7.1.4 and database local_wpunit71
#   .\run.ps1 -Core 7.0
#
# -CoreRoot is the folder that holds wp-core-<version>\wordpress (default: the scratch folder of this machine's session).
param(
    [string]$Core = '7.1.4',
    [ValidateSet('front', 'admin')][string]$Suite = 'front',   # admin = bootstrapped as an admin request (WP_ADMIN), for AJAX/admin tests
    [string]$Filter = '',
    [string]$CoreRoot = $env:DT_WP_CORE_ROOT,
    [string]$Php = "$env:APPDATA\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe",
    [string]$PhpIniDir = $env:DT_PHP_INI_DIR
)
if (-not $CoreRoot) { throw 'Set DT_WP_CORE_ROOT (folder containing wp-core-<version>\wordpress) or pass -CoreRoot.' }
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$env:WP_CORE_DIR = Join-Path $CoreRoot "wp-core-$Core\wordpress"
$env:WP_TEST_DB = if ($Core -like '7.0*') { 'local_wpunit70' } else { 'local_wpunit71' }
$env:WP_PHP_BINARY = $Php
if ($PhpIniDir) { $env:PHPRC = $PhpIniDir }   # child processes spawned by the WP installer need the same extensions
$a = @()
if ($PhpIniDir) { $a += @('-c', (Join-Path $PhpIniDir 'php.ini')) } else { $a += '-n' }
$config = if ($Suite -eq 'admin') { 'phpunit-admin.xml.dist' } else { 'phpunit.xml.dist' }
$cmd = $a + @((Join-Path $here 'vendor\phpunit\phpunit\phpunit'), '-c', (Join-Path $here $config))
if ($Filter) { $cmd += @('--filter', $Filter) }
Write-Host "WP $Core  db=$($env:WP_TEST_DB)"
& $Php @cmd
exit $LASTEXITCODE
