param(
    [switch] $WithTunnel,
    [switch] $NoBackend,
    [switch] $NoFrontend,
    [switch] $Queue,
    [int] $BackendPort = 8000,
    [int] $FrontendPort = 5173
)

$ErrorActionPreference = "Stop"

$Root = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $Root "Backend"
$Frontend = Join-Path $Root "Front-React"
$BackendEnv = Join-Path $Backend ".env"
$BackendEnvExample = Join-Path $Backend ".env.local.example"
$FrontendEnv = Join-Path $Frontend ".env.local"
$FrontendEnvExample = Join-Path $Frontend ".env.local.example"

function Copy-TemplateIfMissing {
    param(
        [string] $Source,
        [string] $Target
    )

    if (-not (Test-Path -LiteralPath $Target)) {
        Copy-Item -LiteralPath $Source -Destination $Target
        Write-Host "Created $Target from template. Fill credentials before continuing." -ForegroundColor Yellow
    }
}

function Read-DotEnv {
    param([string] $Path)

    $values = @{}
    if (-not (Test-Path -LiteralPath $Path)) {
        return $values
    }

    foreach ($line in Get-Content -LiteralPath $Path) {
        $trimmed = $line.Trim()
        if ($trimmed -eq "" -or $trimmed.StartsWith("#") -or -not $trimmed.Contains("=")) {
            continue
        }

        $parts = $trimmed.Split("=", 2)
        $key = $parts[0].Trim()
        $value = $parts[1].Trim().Trim('"').Trim("'")
        $values[$key] = $value
    }

    return $values
}

function Start-DevWindow {
    param(
        [string] $Title,
        [string] $WorkingDirectory,
        [string] $Command
    )

    $escapedTitle = $Title.Replace("'", "''")
    $escapedDirectory = $WorkingDirectory.Replace("'", "''")
    $escapedCommand = $Command.Replace("'", "''")
    $psCommand = "`$Host.UI.RawUI.WindowTitle = '$escapedTitle'; Set-Location -LiteralPath '$escapedDirectory'; $escapedCommand"

    Start-Process -FilePath "powershell.exe" -ArgumentList @("-NoExit", "-Command", $psCommand)
}

Copy-TemplateIfMissing -Source $BackendEnvExample -Target $BackendEnv
Copy-TemplateIfMissing -Source $FrontendEnvExample -Target $FrontendEnv

if (-not (Test-Path -LiteralPath (Join-Path $Backend "vendor"))) {
    Write-Host "Backend/vendor is missing. Run composer install in Backend before starting local development." -ForegroundColor Yellow
}

if (-not (Test-Path -LiteralPath (Join-Path $Frontend "node_modules"))) {
    Write-Host "Front-React/node_modules is missing. Run npm install in Front-React before starting local development." -ForegroundColor Yellow
}

$envValues = Read-DotEnv -Path $BackendEnv

if ($WithTunnel) {
    foreach ($required in @("VPS_SSH_HOST", "VPS_SSH_USER")) {
        if (-not $envValues.ContainsKey($required) -or [string]::IsNullOrWhiteSpace($envValues[$required])) {
            throw "$required is required in Backend\.env to start the SSH tunnel."
        }
    }

    $sshPort = $envValues["VPS_SSH_PORT"]
    if ([string]::IsNullOrWhiteSpace($sshPort)) { $sshPort = "22" }
    $localDbPort = $envValues["LOCAL_DB_PORT"]
    if ([string]::IsNullOrWhiteSpace($localDbPort)) { $localDbPort = "3306" }
    $vpsDbHost = $envValues["VPS_DB_HOST"]
    if ([string]::IsNullOrWhiteSpace($vpsDbHost)) { $vpsDbHost = "127.0.0.1" }
    $vpsDbPort = $envValues["VPS_DB_PORT"]
    if ([string]::IsNullOrWhiteSpace($vpsDbPort)) { $vpsDbPort = "3306" }

    $sshTarget = "$($envValues['VPS_SSH_USER'])@$($envValues['VPS_SSH_HOST'])"
    $sshCommand = "ssh -N -L ${localDbPort}:${vpsDbHost}:${vpsDbPort} -p $sshPort $sshTarget"
    Start-DevWindow -Title "VPS MySQL tunnel" -WorkingDirectory $Root -Command $sshCommand
}

if (-not $NoBackend) {
    Start-DevWindow -Title "Laravel local API" -WorkingDirectory $Backend -Command "php artisan config:clear; php artisan serve --host=127.0.0.1 --port=$BackendPort"
}

if ($Queue) {
    Start-DevWindow -Title "Laravel queue local" -WorkingDirectory $Backend -Command "php artisan queue:listen --tries=1"
}

if (-not $NoFrontend) {
    Start-DevWindow -Title "Vite local frontend" -WorkingDirectory $Frontend -Command "npm run dev -- --host 127.0.0.1 --port $FrontendPort"
}

Write-Host ""
Write-Host "Local development URLs:" -ForegroundColor Cyan
Write-Host "  Backend:  http://127.0.0.1:$BackendPort"
Write-Host "  Frontend: http://127.0.0.1:$FrontendPort/panel/"
Write-Host ""
Write-Host "Database stays on the VPS. Use tools/test-vps-db.ps1 to verify Laravel can reach it." -ForegroundColor Cyan
