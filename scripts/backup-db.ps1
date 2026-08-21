<#
  Nightly database backup for Airking Inventory.

  Dumps the MySQL database, verifies the dump is complete, zips it, records a
  SHA-256 so tampering is detectable, mirrors it off the machine, and prunes
  old copies on a grandfather-father-son schedule.

  Run by Windows Task Scheduler -- see scripts/README-backup.md
#>

$ErrorActionPreference = 'Stop'

# --- settings ----------------------------------------------------------------
$MysqlDump  = 'C:\xampp\mysql\bin\mysqldump.exe'
$DbHost     = '127.0.0.1'
$DbPort     = '3306'
$DbName     = 'airkingV5'
$DbUser     = 'root'
$DbPassword = ''                      # set this if you ever give root a password
$BackupDir  = 'C:\AirkingBackups'     # local working copy (fast restores)
$MirrorDir  = "$env:USERPROFILE\OneDrive\AirkingBackups"   # synced off this machine.
                                      # Swap for a Backblaze B2 / S3 path at deploy
                                      # time -- see the threat-model note in README.

# Retention. Dailies age out fast; weeklies and monthlies are what save you when
# an intrusion is discovered long after it started.
$KeepDailyDays    = 14
$KeepWeeklyWeeks  = 12                # Sunday dumps
$KeepMonthlyMonths = 12               # 1st-of-month dumps
# -----------------------------------------------------------------------------

$now     = Get-Date
$stamp   = $now.ToString('yyyyMMdd-HHmmss')
$logFile = Join-Path $BackupDir 'backup.log'

function Write-Log($message) {
    $line = "$($now.ToString('s'))  $message"
    Write-Output $line
    if (Test-Path $BackupDir) { Add-Content -Path $logFile -Value $line -Encoding utf8 }
}

# A dump is worth keeping only if mysqldump wrote its completion trailer. A
# truncated file (disk full, killed mid-run) looks perfectly valid otherwise.
function Test-DumpComplete($path) {
    $tail = Get-Content -Path $path -Tail 5 -ErrorAction Stop
    return ($tail -join "`n") -match 'Dump completed'
}

# Keep a daily for N days, but keep Sunday copies for weeks and 1st-of-month
# copies for months. Anything outside all three windows is expendable.
function Test-ShouldKeep($file) {
    $age = $now - $file.LastWriteTime
    if ($age.TotalDays -le $KeepDailyDays) { return $true }
    if ($file.LastWriteTime.DayOfWeek -eq 'Sunday' -and $age.TotalDays -le ($KeepWeeklyWeeks * 7)) { return $true }
    if ($file.LastWriteTime.Day -eq 1 -and $file.LastWriteTime -ge $now.AddMonths(-$KeepMonthlyMonths)) { return $true }
    return $false
}

try {
    if (-not (Test-Path $BackupDir)) { New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null }
    if (-not (Test-Path $MysqlDump)) { throw "mysqldump not found at $MysqlDump" }

    $sqlPath = Join-Path $BackupDir "$DbName-$stamp.sql"
    $zipPath = Join-Path $BackupDir "$DbName-$stamp.zip"

    # Password goes through the environment, not the command line, so it does not
    # show up in the process list for every other user on the machine.
    $env:MYSQL_PWD = $DbPassword

    Write-Log "Dumping $DbName ..."
    & $MysqlDump `
        --host=$DbHost --port=$DbPort --user=$DbUser `
        --single-transaction `
        --routines --triggers --events `
        --default-character-set=utf8mb4 `
        --result-file=$sqlPath `
        $DbName

    if ($LASTEXITCODE -ne 0) { throw "mysqldump exited with code $LASTEXITCODE" }

    $sizeKb = [math]::Round((Get-Item $sqlPath).Length / 1KB)
    if ($sizeKb -lt 1)              { throw 'dump is empty -- refusing to keep it' }
    if (-not (Test-DumpComplete $sqlPath)) { throw 'dump is truncated -- refusing to keep it' }

    Compress-Archive -Path $sqlPath -DestinationPath $zipPath -Force
    Remove-Item $sqlPath -Force

    # Record the hash so a later restore can prove the file was not altered
    # while it sat on disk.
    $hash = (Get-FileHash -Path $zipPath -Algorithm SHA256).Hash
    Set-Content -Path "$zipPath.sha256" -Value $hash -Encoding utf8
    Write-Log "OK  $([System.IO.Path]::GetFileName($zipPath))  (${sizeKb} KB uncompressed)  sha256=$($hash.Substring(0,16))..."

    # Copy off this machine. Without this step the backups die with the PC --
    # ransomware encrypts anything the logged-in account can write.
    if ($MirrorDir) {
        try {
            if (-not (Test-Path $MirrorDir)) { New-Item -ItemType Directory -Path $MirrorDir -Force | Out-Null }
            Copy-Item $zipPath, "$zipPath.sha256" -Destination $MirrorDir -Force
            Write-Log "mirrored to $MirrorDir"
        } catch {
            # A missing USB drive should not lose the local backup we just made.
            Write-Log "WARNING  mirror to $MirrorDir failed: $($_.Exception.Message)"
        }
    } else {
        Write-Log 'WARNING  $MirrorDir is not set -- backups exist only on this machine'
    }

    foreach ($file in (Get-ChildItem -Path $BackupDir -Filter "$DbName-*.zip")) {
        if (-not (Test-ShouldKeep $file)) {
            Remove-Item $file.FullName -Force
            Remove-Item "$($file.FullName).sha256" -Force -ErrorAction SilentlyContinue
            Write-Log "pruned $($file.Name)"
        }
    }
}
catch {
    Write-Log "FAILED  $($_.Exception.Message)"
    exit 1
}
finally {
    Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
}
