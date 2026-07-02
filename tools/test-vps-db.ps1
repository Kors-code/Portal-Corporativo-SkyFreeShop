param(
    [switch] $VerboseErrors
)

$ErrorActionPreference = "Stop"

$Root = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $Root "Backend"

if (-not (Test-Path -LiteralPath (Join-Path $Backend ".env"))) {
    throw "Backend\.env does not exist. Copy Backend\.env.local.example to Backend\.env and fill the VPS database values first."
}

if ($VerboseErrors) {
    $env:LOCAL_DB_VERBOSE_ERRORS = "1"
} else {
    Remove-Item Env:\LOCAL_DB_VERBOSE_ERRORS -ErrorAction SilentlyContinue
}

Push-Location $Backend
try {
    php artisan config:clear | Out-Host
    php (Join-Path $Root "tools\test-vps-db.php")
} finally {
    Pop-Location
}
