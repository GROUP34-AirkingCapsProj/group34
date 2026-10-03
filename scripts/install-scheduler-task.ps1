<#
  Registers the single Windows task that drives every Laravel scheduled job for
  Airking Inventory.

  Laravel's scheduler does not run itself. It needs the OS to call
  `schedule:run` once a minute; Laravel then decides what is actually due.
  Without this task every Schedule:: entry in back-end/routes/console.php is
  dead code -- which is how this install ended up with six scheduled jobs that
  had never run once.

  One task covers all of them:
    backup:run           every minute, gated by the Backup page's own schedule
    warranties:expire    daily 01:00
    archive:auto         daily 02:00
    ledger:drift-check   daily 02:30
    archive:cleanup      weekly Sunday 03:00
    analytics:restock    daily 06:00

  Hidden, so no console window ever appears and nothing can be closed
  mid-dump. The retired AirkingInventory-NightlyBackup task ran Interactive and
  visible, which is why a terminal used to flash on screen -- and one of its
  runs was killed by the window being closed (result 0xC000013A,
  STATUS_CONTROL_C_EXIT).

  Run from an ELEVATED PowerShell:
    powershell -ExecutionPolicy Bypass -File scripts\install-scheduler-task.ps1

  Optional: -AsCurrentUser  (see "Which account" below)
  Optional: -WhatIf         (show what would be registered, change nothing)
#>

[CmdletBinding(SupportsShouldProcess = $true)]
param(
    # Run the task as the logged-in user instead of SYSTEM.
    #
    # Use this if the Backup page's "storage path" points somewhere SYSTEM
    # cannot reach -- a OneDrive/Google Drive folder under C:\Users\<you>, a
    # mapped network drive. SYSTEM has no user profile and no mapped drives, so
    # a backup written there would fail every night with a permissions error.
    # The cost is that the task only runs while that account is logged on.
    [switch]$AsCurrentUser
)

$ErrorActionPreference = 'Stop'

$TaskName = 'AirkingInventory-Scheduler'
$Php      = 'C:\xampp\php\php.exe'
$Artisan  = 'C:\xampp\htdocs\Airking_Inventory\back-end\artisan'
$Wrapper  = 'C:\xampp\htdocs\Airking_Inventory\scripts\run-scheduler.ps1'

# --- preflight ---------------------------------------------------------------

if (-not (Test-Path $Php))     { throw "php.exe not found at $Php" }
if (-not (Test-Path $Artisan)) { throw "artisan not found at $Artisan" }
if (-not (Test-Path $Wrapper)) { throw "run-scheduler.ps1 not found at $Wrapper" }

# Registering a task for SYSTEM needs elevation. Fail with a clear message
# rather than a raw Access Denied from Register-ScheduledTask.
$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this from an elevated PowerShell (right-click > Run as administrator).'
}

# The scheduler is useless if the database is not up when it fires. MySQL here
# is started by hand from the XAMPP Control Panel unless it was installed as a
# service, so warn rather than fail -- the task is still worth registering.
$mysqlService = Get-Service | Where-Object { $_.Name -match 'mysql|maria' }
if (-not $mysqlService) {
    Write-Output @'
MySQL is started by hand (no Windows service) -- this is supported.

run-scheduler.ps1 checks port 3306 before invoking Laravel and exits silently
when nothing is listening, so ticks taken while XAMPP is closed cost nothing and
write nothing to the log.

What it means in practice: the Backup page's time becomes a "not before" rather
than an appointment. The catch-up gate takes that day's backup on the first tick
at or after the configured time WHILE MYSQL IS RUNNING -- so the backup happens
the next time you open the system. On a day the system is never opened, no
backup is taken.

Install MySQL as a service only if you want backups on days nobody uses the PC:
close the XAMPP Control Panel, reopen it as Administrator, tick the checkbox in
the Service column on the MySQL row.
'@
} elseif ($mysqlService.StartType -ne 'Automatic') {
    Write-Warning "MySQL service '$($mysqlService.Name)' exists but StartType is '$($mysqlService.StartType)', not Automatic. Scheduled backups will be skipped after a reboot until someone starts it."
}

# --- task definition ---------------------------------------------------------

# Goes through run-scheduler.ps1 rather than straight to php.exe, so a tick taken
# while MySQL is down is skipped silently instead of booting Laravel to throw a
# connection exception into the log once a minute.
$action = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$Wrapper`""

# Every minute, indefinitely. Laravel gates what actually runs, so a tick that
# has nothing to do exits immediately.
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration ([TimeSpan]::MaxValue)

$settings = New-ScheduledTaskSettingsSet `
    -Hidden `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2)

if ($AsCurrentUser) {
    # Interactive: runs only while this account is logged on, but can reach the
    # user profile, OneDrive, and mapped drives.
    $principalArg = New-ScheduledTaskPrincipal `
        -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Highest
    $accountNote = "as $env:USERDOMAIN\$env:USERNAME (only while logged on)"
} else {
    # SYSTEM: runs whether or not anyone is logged on, and has no window to show.
    $principalArg = New-ScheduledTaskPrincipal `
        -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $accountNote = 'as SYSTEM (runs whether or not anyone is logged on)'
}

if ($PSCmdlet.ShouldProcess($TaskName, 'Register scheduled task')) {
    Register-ScheduledTask -TaskName $TaskName `
        -Action $action -Trigger $trigger -Settings $settings -Principal $principalArg `
        -Description 'Drives all Laravel scheduled jobs for Airking Inventory (runs every minute, hidden). See scripts/README-backup.md' `
        -Force | Out-Null

    Write-Output "Registered '$TaskName' -- every minute, hidden, $accountNote."
    Write-Output ''
    Write-Output 'Verify in two minutes:'
    Write-Output "  Get-ScheduledTaskInfo -TaskName '$TaskName' | Select-Object LastRunTime, LastTaskResult, NextRunTime"
    Write-Output ''
    Write-Output 'LastTaskResult 0 means the tick is firing. Then read the heartbeat, which'
    Write-Output 'is overwritten every minute and says whether MySQL was up:'
    Write-Output '  Get-Content C:\xampp\htdocs\Airking_Inventory\back-end\storage\logs\scheduler-heartbeat.txt'
    Write-Output ''
    Write-Output '  "skipped: MySQL not listening"  -> tick alive, XAMPP closed (expected)'
    Write-Output '  "last schedule:run exited 0"    -> tick alive, Laravel reached'
    Write-Output ''
    Write-Output 'With MySQL running, confirm Laravel is being reached:'
    Write-Output '  Get-Content C:\xampp\htdocs\Airking_Inventory\back-end\storage\logs\backup-run.log -Tail 5'
    Write-Output ''
    Write-Output 'It should contain "Not due - skipping." lines until the Backup page'
    Write-Output 'schedule is enabled and its time arrives.'
}
