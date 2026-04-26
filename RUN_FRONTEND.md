# Running the Frontend - PowerShell Execution Policy Fix

## Quick Solutions

### Option 1: Run PowerShell as Administrator
1. Right-click PowerShell → "Run as Administrator"
2. Run: `Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser`
3. Type `Y` when prompted
4. Close and reopen PowerShell
5. Navigate to frontend: `cd frontend`
6. Run: `npm start`

### Option 2: Use Command Prompt (CMD) instead
1. Open Command Prompt (cmd.exe)
2. Navigate: `cd C:\capstone_project\frontend`
3. Run: `npm start`

### Option 3: Bypass for Current Session Only
In PowerShell, run:
```powershell
Set-ExecutionPolicy -ExecutionPolicy Bypass -Scope Process
cd frontend
npm start
```

### Option 4: Use npm.cmd directly
```powershell
cd frontend
& "C:\Program Files\nodejs\npm.cmd" start
```

## Recommended: Use Command Prompt
Since npm works, the easiest solution is to use CMD instead of PowerShell:

```cmd
cd C:\capstone_project\frontend
npm start
```

## For Backend (Laravel)
Use PowerShell or CMD:
```bash
cd C:\capstone_project\back-end
php artisan serve
```
