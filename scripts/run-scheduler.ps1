<#
  Runs Laravel's scheduler, but only when MySQL is actually up.

  Why this wrapper exists
  -----------------------
  MySQL on this machine is started by hand from the XAMPP Control Panel, by
  choice -- it is not a Windows service. The scheduler task fires every minute
  regardless, and `backup:run` reads its schedule from the `system_settings`
  table, so every tick taken while MySQL is down would throw a connection
  exception and write it to storage/logs. That is roughly 1,440 failures a day
  for a machine that is simply switched off, in a log file that is already
  several megabytes.

  So: check the port first and exit silently if nothing is listening. Nothing is
  lost by skipping. BackupService::dueForScheduledRun() is a catch-up gate -- it
  fires on the first tick at or after the configured time on a day that has not
  backed up yet -- so the backup simply happens the next time MySQL is running,
  which is the next time someone uses the system.

  The practical effect of running MySQL by hand is that the configured time is a
  "not before" rather than an appointment. With the default 00:01 (UTC, so 08:01
  Philippine time) the first session of each working day takes that day's
  backup. If the machine is never used on a given day, no backup is taken for
  that day -- which is unavoidable without the service, and is the trade being
  made deliberately.

  A heartbeat line is overwritten (never appended) at storage/logs/
  scheduler-heartbeat.txt so "is the task alive?" can be answered without
  trawling a log, and without the file ever growing.
#>

$ErrorActionPreference = 'Stop'

$Php       = 'C:\xampp\php\php.exe'
$Artisan   = 'C:\xampp\htdocs\Airking_Inventory\back-end\artisan'
$Heartbeat = 'C:\xampp\htdocs\Airking_Inventory\back-end\storage\logs\scheduler-heartbeat.txt'
$DbHost    = '127.0.0.1'
$DbPort    = 3306
$TimeoutMs = 1500

function Write-Heartbeat([string]$state) {
    try {
        # Set-Content, not Add-Content: one line, overwritten, so this file is
        # bounded no matter how long the task runs.
        Set-Content -Path $Heartbeat -Encoding utf8 -Value (
            "{0}  {1}" -f (Get-Date).ToString('yyyy-MM-dd HH:mm:ss zzz'), $state
        )
    } catch {
        # A heartbeat we cannot write must never stop the scheduler.
    }
}

# Is MySQL listening? TcpClient with an explicit wait rather than
# Test-NetConnection, which takes seconds and would overlap the next tick.
$up = $false
try {
    $client = New-Object System.Net.Sockets.TcpClient
    $connect = $client.BeginConnect($DbHost, $DbPort, $null, $null)
    $up = $connect.AsyncWaitHandle.WaitOne($TimeoutMs, $false) -and $client.Connected
    if ($up) { $client.EndConnect($connect) }
} catch {
    $up = $false
} finally {
    if ($client) { $client.Close() }
}

if (-not $up) {
    # Expected and routine: the PC is on but XAMPP is not started. Silent by
    # design -- this is the whole point of the wrapper.
    Write-Heartbeat "skipped: MySQL not listening on ${DbHost}:${DbPort}"
    exit 0
}

Write-Heartbeat 'running schedule:run'

& $Php $Artisan schedule:run
$code = $LASTEXITCODE

Write-Heartbeat "last schedule:run exited $code"

exit $code
