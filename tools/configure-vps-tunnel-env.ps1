param(
    [Parameter(Mandatory = $true)] [string] $VpsSshHost,
    [Parameter(Mandatory = $true)] [string] $VpsSshUser,
    [int] $VpsSshPort = 22,
    [string] $VpsSshKey,
    [string] $VpsDbHost = "127.0.0.1",
    [int] $VpsDbPort = 3306,
    [int] $LocalDbPort = 3307,

    [Parameter(Mandatory = $true)] [string] $MainDbName,
    [Parameter(Mandatory = $true)] [string] $MainDbUser,
    [Parameter(Mandatory = $true)] [string] $MainDbPassword,

    [Parameter(Mandatory = $true)] [string] $BudgetDbName,
    [Parameter(Mandatory = $true)] [string] $BudgetDbUser,
    [Parameter(Mandatory = $true)] [string] $BudgetDbPassword,

    [Parameter(Mandatory = $true)] [string] $PersonalDbName,
    [Parameter(Mandatory = $true)] [string] $PersonalDbUser,
    [Parameter(Mandatory = $true)] [string] $PersonalDbPassword
)

$ErrorActionPreference = "Stop"

$Root = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $Root "Backend"
$EnvPath = Join-Path $Backend ".env"
$TemplatePath = Join-Path $Backend ".env.local.example"

if (-not (Test-Path -LiteralPath $EnvPath)) {
    Copy-Item -LiteralPath $TemplatePath -Destination $EnvPath
}

function Protect-EnvValue {
    param([string] $Value)

    if ($Value -match '[\s#"]') {
        return '"' + $Value.Replace('"', '\"') + '"'
    }

    return $Value
}

function Set-EnvValue {
    param(
        [string[]] $Lines,
        [string] $Key,
        [string] $Value
    )

    $escapedValue = Protect-EnvValue -Value $Value
    $pattern = "^\s*$([regex]::Escape($Key))="
    $replacement = "$Key=$escapedValue"
    $found = $false
    $next = foreach ($line in $Lines) {
        if ($line -match $pattern) {
            $found = $true
            $replacement
        } else {
            $line
        }
    }

    if (-not $found) {
        $next += $replacement
    }

    return $next
}

$lines = Get-Content -LiteralPath $EnvPath

$values = @{
    APP_ENV = "local"
    APP_DEBUG = "true"
    APP_URL = "http://127.0.0.1:8000"

    DB_CONNECTION = "mysql"
    DB_HOST = "127.0.0.1"
    DB_PORT = [string] $LocalDbPort
    DB_DATABASE = $MainDbName
    DB_USERNAME = $MainDbUser
    DB_PASSWORD = $MainDbPassword

    DB_SECOND_CONNECTION = "mysql_personal"
    DB_SECOND_HOST = "127.0.0.1"
    DB_SECOND_PORT = [string] $LocalDbPort
    DB_SECOND_DATABASE = $PersonalDbName
    DB_SECOND_USERNAME = $PersonalDbUser
    DB_SECOND_PASSWORD = $PersonalDbPassword

    DB_BUDGET_HOST = "127.0.0.1"
    DB_BUDGET_PORT = [string] $LocalDbPort
    DB_BUDGET_DATABASE = $BudgetDbName
    DB_BUDGET_USERNAME = $BudgetDbUser
    DB_BUDGET_PASSWORD = $BudgetDbPassword

    SESSION_DRIVER = "file"
    CACHE_STORE = "file"
    QUEUE_CONNECTION = "sync"

    VPS_SSH_HOST = $VpsSshHost
    VPS_SSH_USER = $VpsSshUser
    VPS_SSH_PORT = [string] $VpsSshPort
    VPS_DB_HOST = $VpsDbHost
    VPS_DB_PORT = [string] $VpsDbPort
    LOCAL_DB_PORT = [string] $LocalDbPort
}

if ($PSBoundParameters.ContainsKey("VpsSshKey")) {
    $values.VPS_SSH_KEY = $VpsSshKey
}

foreach ($key in $values.Keys) {
    $lines = Set-EnvValue -Lines $lines -Key $key -Value $values[$key]
}

Set-Content -LiteralPath $EnvPath -Value $lines

Push-Location $Backend
try {
    php artisan config:clear | Out-Host
} finally {
    Pop-Location
}

Write-Host "Backend\.env configured for SSH tunnel on 127.0.0.1:$LocalDbPort." -ForegroundColor Cyan
Write-Host "Start with: powershell.exe -ExecutionPolicy Bypass -File tools\dev-local.ps1 -WithTunnel" -ForegroundColor Cyan
