# Dumps the live Aiven PostgreSQL database to a local file.
#
# Run it in PowerShell:   .\scripts\backup-aiven.ps1
#                         .\scripts\backup-aiven.ps1 -OutFile C:\path\to\airking.dump
#
# Why this exists: BackupService (the in-app backup feature) shells out to
# mysqldump and only ever covered the local MySQL database. Production runs on
# PostgreSQL, so until that is ported this script is the only backup the live
# data has.
#
# Reads PG_HOST/PG_PORT/PG_DATABASE/PG_USERNAME/PG_PASSWORD from back-end/.env --
# the same block check-aiven.ps1 reads. The password is passed to pg_dump through
# the PGPASSWORD environment variable of the child process only: it is never put
# on a command line (where it would be visible to every other process on the
# machine) and never written to the console.
#
# Output is pg_dump's custom format (-Fc): compressed, and restorable selectively
# with pg_restore rather than all-or-nothing.
#
# THE DUMP CONTAINS REAL DATA, including password hashes. .gitignore blocks
# *.dump so it cannot be committed by accident. Keep it off file-sharing sites.

[CmdletBinding()]
param(
    [string] $OutFile,
    [string] $PgDump
)

$ErrorActionPreference = 'Stop'

$envPath = Join-Path $PSScriptRoot '..\.env'

if (-not (Test-Path $envPath)) {
    Write-Host "Cannot find $envPath" -ForegroundColor Red
    exit 1
}

$settings = @{}
foreach ($line in Get-Content $envPath) {
    if ($line -match '^\s*(PG_[A-Z_]+)\s*=\s*(.*)$') {
        $settings[$Matches[1]] = $Matches[2].Trim().Trim('"')
    }
}

foreach ($key in @('PG_HOST', 'PG_PORT', 'PG_DATABASE', 'PG_USERNAME', 'PG_PASSWORD')) {
    if (-not $settings[$key]) {
        Write-Host "$key is not set in .env" -ForegroundColor Red
        exit 1
    }
}

# pg_dump refuses to dump a server newer than itself, so the binary has to be at
# least the server's major version. Prefer an explicit -PgDump, then PATH, then
# the extracted EDB binaries this project was set up with.
if (-not $PgDump) {
    $candidates = @()
    $onPath = Get-Command pg_dump -ErrorAction SilentlyContinue
    if ($onPath) { $candidates += $onPath.Source }
    $candidates += Get-ChildItem -Path "$env:USERPROFILE\Downloads" -Filter 'pg_dump.exe' -Recurse -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -like '*\pgsql\bin\pg_dump.exe' } |
        Select-Object -ExpandProperty FullName
    $candidates += 'C:\pgsql\bin\pg_dump.exe'

    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path $candidate)) { $PgDump = $candidate; break }
    }
}

if (-not $PgDump -or -not (Test-Path $PgDump)) {
    Write-Host 'Cannot find pg_dump.exe. Pass -PgDump "C:\path\to\pg_dump.exe".' -ForegroundColor Red
    exit 1
}

if (-not $OutFile) {
    $stamp = Get-Date -Format 'yyyy-MM-dd-HHmmss'
    $dir = Join-Path $PSScriptRoot '..\..\backups'
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }
    $OutFile = Join-Path $dir "aiven-$stamp.dump"
}

Write-Host "pg_dump : $PgDump"
Write-Host "version : $(& $PgDump --version)"
Write-Host "source  : $($settings['PG_USERNAME'])@$($settings['PG_HOST']):$($settings['PG_PORT'])/$($settings['PG_DATABASE'])"
Write-Host "target  : $OutFile"
Write-Host ''
Write-Host 'Dumping...' -ForegroundColor Cyan

$env:PGPASSWORD = $settings['PG_PASSWORD']
$env:PGSSLMODE = if ($settings['PG_SSLMODE']) { $settings['PG_SSLMODE'] } else { 'require' }

try {
    # Windows PowerShell 5.1 turns a native command's stderr into ErrorRecords,
    # and with $ErrorActionPreference = 'Stop' that aborts the script even when
    # the command succeeds. pg_dump writes progress to stderr, so the preference
    # has to be relaxed across the call; $LASTEXITCODE below is the real verdict.
    # --verbose is deliberately omitted for the same reason: it is pure stderr
    # noise that makes every run look like a failure when the output is piped.
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    & $PgDump `
        --host=$($settings['PG_HOST']) `
        --port=$($settings['PG_PORT']) `
        --username=$($settings['PG_USERNAME']) `
        --dbname=$($settings['PG_DATABASE']) `
        --format=custom `
        --no-owner `
        --no-privileges `
        --file=$OutFile
    $code = $LASTEXITCODE

    $ErrorActionPreference = $previous
}
finally {
    # Clear it even if pg_dump threw, so the password does not linger in the
    # environment of anything else this session runs.
    Remove-Item Env:\PGPASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:\PGSSLMODE -ErrorAction SilentlyContinue
}

if ($code -ne 0) {
    Write-Host ''
    Write-Host "pg_dump exited with $code -- the dump is NOT usable." -ForegroundColor Red
    exit $code
}

if (-not (Test-Path $OutFile)) {
    Write-Host 'pg_dump reported success but wrote no file.' -ForegroundColor Red
    exit 1
}

$size = (Get-Item $OutFile).Length

if ($size -lt 1024) {
    Write-Host ''
    Write-Host "Dump is only $size bytes -- almost certainly empty. Treat it as failed." -ForegroundColor Red
    exit 1
}

Write-Host ''
Write-Host 'OK' -ForegroundColor Green
Write-Host "  file : $OutFile"
Write-Host "  size : $([math]::Round($size / 1MB, 2)) MB"
Write-Host ''
Write-Host 'Verify it is readable before trusting it:' -ForegroundColor Yellow
Write-Host "  & '$([System.IO.Path]::GetDirectoryName($PgDump))\pg_restore.exe' --list '$OutFile' | Select-Object -First 20"
