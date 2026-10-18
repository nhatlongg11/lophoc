[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$environmentPath = Join-Path $projectRoot '.env'

if (Test-Path -LiteralPath $environmentPath) {
    Write-Host '.env already exists; keeping its keys and passwords. Run docker compose config --quiet to validate it.'
    return
}

function Read-EnvironmentFile([string]$Path) {
    $values = @{}
    if (Test-Path -LiteralPath $Path) {
        foreach ($line in [IO.File]::ReadAllLines($Path)) {
            if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
                $key = $Matches[1]
                $value = $Matches[2].Trim()
                if ($value.Length -ge 2 -and $value.StartsWith("'") -and $value.EndsWith("'")) {
                    $value = $value.Substring(1, $value.Length - 2).Replace("\'", "'")
                } elseif ($value.Length -ge 2 -and $value.StartsWith('"') -and $value.EndsWith('"')) {
                    $value = $value.Substring(1, $value.Length - 2)
                }
                $values[$key] = $value
            }
        }
    }
    return $values
}

function New-RandomValue([int]$Length = 32, [switch]$Base64) {
    $bytes = New-Object byte[] $Length
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
    } finally {
        $generator.Dispose()
    }
    if ($Base64) { return [Convert]::ToBase64String($bytes) }
    return [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
}

$saved = Read-EnvironmentFile (Join-Path $projectRoot '.tools/compose.env')
$backend = Read-EnvironmentFile (Join-Path $projectRoot 'backend/.env')
$savedRole = Read-EnvironmentFile (Join-Path $projectRoot '.tools/postgres-app.env')
$values = @{}

# Reuse the credentials belonging to this repository's existing Docker volumes.
foreach ($key in @('POSTGRES_PASSWORD', 'APP_DB_USERNAME', 'APP_DB_PASSWORD', 'S3_ACCESS_KEY', 'S3_SECRET_KEY')) {
    if ($saved[$key]) { $values[$key] = $saved[$key] }
}
if (-not $values['APP_DB_USERNAME']) { $values['APP_DB_USERNAME'] = 'eduquest_app' }
if (-not $values['APP_DB_PASSWORD'] -and $savedRole['DB_PASSWORD']) {
    $values['APP_DB_PASSWORD'] = $savedRole['DB_PASSWORD']
}
if (-not $values['APP_DB_PASSWORD'] -and $backend['DB_CONNECTION'] -eq 'pgsql' -and $backend['DB_USERNAME'] -eq $values['APP_DB_USERNAME']) {
    $values['APP_DB_PASSWORD'] = $backend['DB_PASSWORD']
}
foreach ($key in @('POSTGRES_PASSWORD', 'APP_DB_PASSWORD', 'S3_ACCESS_KEY', 'S3_SECRET_KEY')) {
    if (-not $values[$key]) { $values[$key] = New-RandomValue }
}
$values['APP_KEY'] = if ($backend['APP_KEY']) { $backend['APP_KEY'] } elseif ($saved['APP_KEY']) { $saved['APP_KEY'] } else { 'base64:' + (New-RandomValue -Base64) }

$lines = foreach ($line in [IO.File]::ReadAllLines((Join-Path $projectRoot '.env.example'))) {
    if ($line -match '^([A-Z_][A-Z0-9_]*)=' -and $values.ContainsKey($Matches[1])) {
        $key = $Matches[1]
        $value = $values[$key]
        if ($value -match '[\r\n]') { throw "Invalid multiline value for $key." }
        "${key}='" + $value.Replace("'", "\'") + "'"
    } else {
        $line
    }
}

# CreateNew prevents concurrent invocations from overwriting an existing .env.
$stream = [IO.File]::Open($environmentPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write)
$writer = New-Object IO.StreamWriter($stream, (New-Object Text.UTF8Encoding($false)))
try {
    $writer.Write(($lines -join "`n") + "`n")
} finally {
    $writer.Dispose()
}
Write-Host 'Created .env with saved credentials where available and random values for missing secrets.'
Write-Host 'Start: docker compose up -d --build --wait'
Write-Host 'Open:  http://localhost:8080/app/'
Write-Host 'Stop:  docker compose down (keeps database and upload volumes)'
