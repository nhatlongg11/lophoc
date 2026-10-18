param(
    [switch]$Scheduler,
    [int]$Port = 8000,
    [string]$Database
)

$projectRoot = $PSScriptRoot
$backend = Join-Path $projectRoot 'backend'
if ($Database) {
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = $Database
}
$phpIni = Join-Path $projectRoot '.tools\php.ini'
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpExecutable = if ($phpCommand) { $phpCommand.Source } else { $null }

if (-not $phpExecutable) {
    $packages = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages'
    $installation = Get-ChildItem -LiteralPath $packages -Directory -Filter 'PHP.PHP.8.4_*' -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($installation) {
        $candidate = Join-Path $installation.FullName 'php.exe'
        if (Test-Path -LiteralPath $candidate) { $phpExecutable = $candidate }
    }
}

if (-not $phpExecutable) {
    throw 'PHP 8.3+ was not found. Install PHP or add php.exe to PATH.'
}

$env:PATH = "$(Split-Path -Parent $phpExecutable);$env:PATH"
$phpOptions = @()
if (Test-Path -LiteralPath $phpIni) {
    $env:PHPRC = Split-Path -Parent $phpIni
    $phpOptions = @('-c', $phpIni)
}

$phpStartupOutput = (& $phpExecutable @phpOptions -v 2>&1 | ForEach-Object { $_.ToString() }) -join [Environment]::NewLine
if ($phpStartupOutput -match 'VCRUNTIME140\.dll.*not compatible') {
    throw 'PHP requires a newer Microsoft Visual C++ x64 runtime. Install https://aka.ms/vc14/vc_redist.x64.exe, then reopen PowerShell and run this launcher again. Database migrations were not started.'
}
if ($LASTEXITCODE -ne 0) {
    throw "PHP could not start. Database migrations were not started.$([Environment]::NewLine)$phpStartupOutput"
}

& $phpExecutable @phpOptions (Join-Path $backend 'artisan') migrate --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Database migration failed; the application was not started.' }

Push-Location $(if ($Scheduler) { $backend } else { Join-Path $backend 'public' })
try {
    if ($Scheduler) {
        Write-Host 'Running scheduled reminders. Press Ctrl+C to stop.'
        & $phpExecutable @phpOptions artisan schedule:work
    } else {
        Write-Host "Open http://127.0.0.1:$Port/app/ . Press Ctrl+C to stop."
        $router = Join-Path $backend 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
        & $phpExecutable @phpOptions -S "127.0.0.1:$Port" -t . $router
    }
} finally {
    Pop-Location
}
