# Diagnoses why `php artisan data:transfer` cannot reach Aiven.
#
# Run it in PowerShell:   .\scripts\check-aiven.ps1
#
# Tests the connection in four widening steps and stops at the first that fails,
# so the output names the layer that is broken instead of "timeout expired".
# It also prints which network the traffic actually left by -- the point of a
# mobile-hotspot test is lost if Windows quietly kept using Wi-Fi.
#
# Reads PG_HOST and PG_PORT from back-end/.env. Sends no password and writes
# nothing: the deepest it goes is PostgreSQL's 8-byte SSLRequest, which happens
# before authentication.

$ErrorActionPreference = 'Continue'

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

$pgHost = $settings['PG_HOST']
$pgPort = $settings['PG_PORT']

if (-not $pgHost -or -not $pgPort) {
    Write-Host 'PG_HOST or PG_PORT is missing from back-end/.env' -ForegroundColor Red
    exit 1
}

Write-Host ''
Write-Host "Target: ${pgHost}:${pgPort}" -ForegroundColor Cyan
Write-Host ''

# --- 0. Which network is this actually leaving by? -------------------------
Write-Host '[0] Network in use'
try {
    $publicIp = (Invoke-WebRequest -Uri 'https://api.ipify.org' -UseBasicParsing -TimeoutSec 20).Content
    Write-Host "    public IP: $publicIp"
} catch {
    Write-Host '    public IP: could not determine (no internet?)' -ForegroundColor Yellow
}

# --- 1. DNS ----------------------------------------------------------------
Write-Host ''
Write-Host '[1] DNS'
$addresses = @()
try {
    $addresses = @(Resolve-DnsName $pgHost -Type A -ErrorAction Stop |
        Where-Object { $_.IPAddress } | ForEach-Object { $_.IPAddress })
} catch { }

if (-not $addresses) {
    Write-Host "    FAIL - $pgHost does not resolve." -ForegroundColor Red
    Write-Host '    PG_HOST is wrong, or this network has no DNS.'
    exit 1
}

$ip = $addresses[0]
Write-Host "    OK - resolves to $ip" -ForegroundColor Green

$route = Find-NetRoute -RemoteIPAddress $ip -ErrorAction SilentlyContinue | Select-Object -First 1
if ($route) {
    $adapter = Get-NetAdapter -InterfaceIndex $route.InterfaceIndex -ErrorAction SilentlyContinue
    Write-Host ("    leaving via: " + $adapter.Name + "  (local " + $route.IPAddress + ")")
    Write-Host '    ^ on a phone hotspot this should NOT say Wi-Fi with a 10.x address' -ForegroundColor DarkGray
}

# --- 2. TCP ----------------------------------------------------------------
Write-Host ''
Write-Host '[2] TCP connection'
$client = New-Object System.Net.Sockets.TcpClient
$connected = $false
try {
    $connected = $client.ConnectAsync($ip, [int]$pgPort).Wait(10000)
} catch { }

if (-not $connected) {
    Write-Host "    FAIL - nothing accepted a connection on port $pgPort." -ForegroundColor Red
    Write-Host '    Either PG_PORT is wrong (Aiven does not use 5432), or this'
    Write-Host '    network blocks the port outright. Try a different network.'
    $client.Close()
    exit 1
}

Write-Host '    OK - port is open and accepted the connection' -ForegroundColor Green

# --- 3. PostgreSQL SSLRequest ----------------------------------------------
# The first thing a client sends. The server answers with a single byte: 'S'
# meaning "yes, start TLS", or 'N' meaning "TLS not available". Anything else --
# especially silence -- means whatever accepted the TCP connection above is not
# actually PostgreSQL, or is not passing the traffic through.
Write-Host ''
Write-Host '[3] PostgreSQL handshake'
try {
    $stream = $client.GetStream()
    $stream.ReadTimeout = 15000
    $stream.Write([byte[]](0, 0, 0, 8, 4, 210, 22, 47), 0, 8)
    $stream.Flush()

    $buffer = New-Object byte[] 1
    $read = $stream.Read($buffer, 0, 1)

    if ($read -eq 1) {
        $reply = [char]$buffer[0]

        if ($reply -eq 'S') {
            Write-Host "    OK - server answered 'S'; PostgreSQL is there and wants TLS" -ForegroundColor Green
            Write-Host ''
            Write-Host 'The network path is fine. If data:transfer still fails, the' -ForegroundColor Cyan
            Write-Host 'problem is the credentials or the PHP driver, not the network.' -ForegroundColor Cyan
        } elseif ($reply -eq 'N') {
            Write-Host "    Server answered 'N' - it refuses TLS." -ForegroundColor Yellow
            Write-Host '    Unexpected for Aiven, which requires it.'
        } else {
            Write-Host "    Server answered '$reply' - that is not a PostgreSQL reply." -ForegroundColor Red
        }
    } else {
        Write-Host '    FAIL - the server closed the connection without replying.' -ForegroundColor Red
    }
} catch {
    Write-Host '    FAIL - no reply within 15 seconds.' -ForegroundColor Red
    Write-Host ''
    Write-Host '    The connection was accepted and then went silent. That is the' -ForegroundColor Yellow
    Write-Host '    signature of a firewall or proxy answering on the server''s behalf' -ForegroundColor Yellow
    Write-Host '    and then dropping the traffic - it is not a password problem, and' -ForegroundColor Yellow
    Write-Host '    no password was sent.' -ForegroundColor Yellow
    Write-Host ''
    Write-Host '    Re-run this on a genuinely different network and compare the' -ForegroundColor Yellow
    Write-Host '    public IP printed in step 0. If it did not change, the hotspot' -ForegroundColor Yellow
    Write-Host '    was not actually in use.' -ForegroundColor Yellow
} finally {
    $client.Close()
}

Write-Host ''
