# Database backup

Nightly `mysqldump` of the `airkingV5` database, run by Windows Task Scheduler.

| | |
|---|---|
| Script | `scripts/backup-db.ps1` |
| Scheduled task | `AirkingInventory-NightlyBackup` |
| Runs | daily at 12:30 AM |
| Output | `C:\AirkingBackups\airkingV5-<timestamp>.zip` (+ `.sha256`) |
| Retention | 14 dailies, 12 Sunday weeklies, 12 monthly (1st-of-month) copies |
| Log | `C:\AirkingBackups\backup.log` |

## Why these settings

- `--single-transaction` takes the dump inside one InnoDB transaction, so the
  file is a consistent snapshot and the app keeps working while it runs. All 73
  tables are InnoDB, so no table locking is needed.
- `--routines --triggers --events` — without these, stored procedures and
  triggers are silently missing from the dump and only turn up when you restore.
- The dump is rejected unless it ends with mysqldump's `Dump completed` trailer.
  A run killed halfway (disk full, power cut) produces a file that looks fine
  and restores as a half-empty database. This is the check that catches it.
- A `.sha256` is written beside every archive so you can prove at restore time
  that the file was not altered while it sat on disk.
- `StartWhenAvailable` on the task means a run missed because the PC was off
  (brownout, weekend) fires as soon as the machine comes back, instead of being
  skipped until the next night.
- The password is passed via the `MYSQL_PWD` environment variable rather than on
  the command line, where it would be visible in the process list.

## Retention, and why it is shaped this way

Dailies cover the ordinary case: someone deleted the wrong records this morning.

Weeklies and monthlies exist for the intrusion case. Attacks are typically
discovered weeks after they start, and the damage — altered records, a planted
account — is already inside every recent backup by the time anyone notices. If
you only keep 14 days and the compromise began on day 20, you have nothing
clean to go back to. The Sunday and 1st-of-month copies are what give you a
pre-intrusion restore point.

## Threat model — read this

**This protects you against:** disk failure, a bad migration, someone deleting
the wrong records, and a compromise discovered within a year.

**This does NOT yet protect you against a hack**, until `$MirrorDir` is set.
Backups sitting in `C:\AirkingBackups` are on the same machine, under the same
Windows account, fully writable. Ransomware running as your user encrypts them
along with the database — finding and destroying backups first is exactly how
ransomware makes money. A backup on the same machine as the thing it protects
is not really a backup.

Set `$MirrorDir` in `backup-db.ps1` to somewhere the machine cannot casually
destroy. In rough order of how well each resists an attacker:

1. **Cloud storage with versioning and MFA-protected delete** (Backblaze B2, S3
   with object lock, Google Drive). Strongest: even a copy deleted by the
   attacker can be rolled back.
2. **A synced cloud folder** (OneDrive, Google Drive). Version history usually
   permits recovery from ransomware, but the sync client runs as your user, so
   deletions propagate. Decent, not airtight.
3. **A USB/external drive that is unplugged between backups.** Unbeatable when
   unplugged, useless when left connected — an attached drive is just another
   writable folder to ransomware. Only works if someone actually unplugs it.

A monthly copy burned to a disc or kept on a drive stored elsewhere covers the
case where every online copy is lost at once.

**One more thing:** these dumps are plaintext customer, supplier, and pricing
data. A stolen backup is itself a data breach. If the mirror goes to cloud
storage, encrypt the archive or use a provider-side encrypted bucket.

## Check that it is still working

```powershell
Get-ScheduledTaskInfo -TaskName 'AirkingInventory-NightlyBackup' |
  Select-Object LastRunTime, LastTaskResult, NextRunTime
```

`LastTaskResult` of `0` means success. Or just look at the newest file in
`C:\AirkingBackups` — if it is older than a day, something is wrong.

Verify an archive is unaltered:

```powershell
$zip = 'C:\AirkingBackups\airkingV5-20260820-035930.zip'
(Get-FileHash $zip -Algorithm SHA256).Hash -eq (Get-Content "$zip.sha256")
```

## Restore

```powershell
# 1. unzip
Expand-Archive C:\AirkingBackups\airkingV5-20260820-035930.zip -DestinationPath $env:TEMP

# 2. restore into a SCRATCH database first, never straight over the live one
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE airking_restore_test"
C:\xampp\mysql\bin\mysql.exe -u root airking_restore_test < $env:TEMP\airkingV5-20260820-035930.sql

# 3. look around, confirm the data is what you expect, then repoint DB_DATABASE
#    in back-end/.env or restore over the real database once you are sure
```

Do a practice restore once now, and again every few months. A backup nobody has
ever restored is a guess, not a backup.

### Restoring after a suspected intrusion

Restoring the database alone does not evict an attacker. If the machine was
compromised, the application code, the XAMPP install, and any stored credentials
are suspect too — restore the database onto a **clean** machine or a rebuilt
install, not back onto the box that was breached, or you hand the same access
straight back. Rotate the database password, `APP_KEY`, and all user passwords
as part of recovery.

## Removing the task

```powershell
Unregister-ScheduledTask -TaskName 'AirkingInventory-NightlyBackup' -Confirm:$false
```
