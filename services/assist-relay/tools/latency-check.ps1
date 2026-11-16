# Live Assist relay: which VPS region is closest to this network?
#
# Measures the TCP connect time and the TLS handshake to each candidate region (median of 5 tries), then prints a
# table sorted by TCP time and the top 3. Takes about a minute. It only opens TLS connections to the providers'
# public speed-test, looking-glass and API hosts listed below, and sends nothing anywhere else.
#
#   Unblock-File .\latency-check.ps1        (once, when the file was downloaded)
#   powershell -ExecutionPolicy RemoteSigned -File .\latency-check.ps1
#   powershell -ExecutionPolicy RemoteSigned -File .\latency-check.ps1 -Tries 9
#
# Works in Windows PowerShell 5.1 (built into Windows 10 and 11) and PowerShell 7 (Windows, macOS, Linux).

param(
    [ValidateRange(1, 50)]
    [int]$Tries = 5,
    [int]$TimeoutMs = 4000
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2

$targets = @(
    @('Hetzner', 'Falkenstein, DE (fsn1)', 'fsn1-speed.hetzner.com'),
    @('Hetzner', 'Nuremberg, DE (nbg1)', 'nbg1-speed.hetzner.com'),
    @('Hetzner', 'Helsinki, FI (hel1)', 'hel1-speed.hetzner.com'),
    @('Hetzner', 'Singapore (sin)', 'sin-speed.hetzner.com'),
    @('DigitalOcean', 'Frankfurt, DE (fra1)', 'fra1.digitaloceanspaces.com'),
    @('DigitalOcean', 'Amsterdam, NL (ams3)', 'ams3.digitaloceanspaces.com'),
    @('DigitalOcean', 'London, UK (lon1)', 'lon1.digitaloceanspaces.com'),
    @('DigitalOcean', 'Bangalore, IN (blr1)', 'blr1.digitaloceanspaces.com'),
    @('DigitalOcean', 'Singapore (sgp1)', 'sgp1.digitaloceanspaces.com'),
    @('Vultr', 'Frankfurt, DE', 'fra-de-ping.vultr.com'),
    @('Vultr', 'Amsterdam, NL', 'ams-nl-ping.vultr.com'),
    @('Vultr', 'London, UK', 'lon-gb-ping.vultr.com'),
    @('Vultr', 'Mumbai, IN', 'bom-in-ping.vultr.com'),
    @('Vultr', 'Bangalore, IN', 'blr-in-ping.vultr.com'),
    @('Vultr', 'Delhi NCR, IN', 'del-in-ping.vultr.com'),
    @('Vultr', 'Singapore', 'sgp-ping.vultr.com'),
    @('Vultr', 'Tel Aviv, IL', 'tlv-il-ping.vultr.com'),
    @('Akamai (Linode)', 'Frankfurt, DE (de-fra)', 'de-fra-1.linodeobjects.com'),
    @('Akamai (Linode)', 'Mumbai, IN (in-bom)', 'in-bom-1.linodeobjects.com'),
    @('Akamai (Linode)', 'Chennai, IN (in-maa)', 'in-maa-1.linodeobjects.com'),
    @('Akamai (Linode)', 'Singapore (sg-sin)', 'sg-sin-1.linodeobjects.com'),
    @('AWS', 'Frankfurt, DE (eu-central-1)', 'ec2.eu-central-1.amazonaws.com'),
    @('AWS', 'UAE (me-central-1)', 'ec2.me-central-1.amazonaws.com'),
    @('AWS', 'Bahrain (me-south-1)', 'ec2.me-south-1.amazonaws.com'),
    @('AWS', 'Mumbai, IN (ap-south-1)', 'ec2.ap-south-1.amazonaws.com')
)

function Get-Median([double[]]$values) {
    if ($null -eq $values -or $values.Count -eq 0) { return $null }
    $sorted = @($values | Sort-Object)
    $count = $sorted.Count
    if ($count % 2 -eq 1) { return [double]$sorted[($count - 1) / 2] }
    return ([double]$sorted[$count / 2 - 1] + [double]$sorted[$count / 2]) / 2
}

# One try: TCP connect, then the TLS handshake, timed separately. Returns @(tcpMs, tlsMs) or $null.
function Measure-Once([System.Net.IPAddress]$address, [string]$hostName) {
    $client = New-Object System.Net.Sockets.TcpClient($address.AddressFamily)
    try {
        $watch = [System.Diagnostics.Stopwatch]::StartNew()
        $connect = $client.ConnectAsync($address, 443)
        if (-not $connect.Wait($TimeoutMs) -or -not $client.Connected) { return $null }
        $tcpMs = $watch.Elapsed.TotalMilliseconds
        $tlsMs = $null
        $tls = New-Object System.Net.Security.SslStream($client.GetStream(), $false)
        try {
            $watch.Restart()
            # SslProtocols None lets the system pick (TLS 1.2 or 1.3).
            $handshake = $tls.AuthenticateAsClientAsync($hostName, $null, [System.Security.Authentication.SslProtocols]::None, $false)
            if ($handshake.Wait($TimeoutMs)) { $tlsMs = $watch.Elapsed.TotalMilliseconds }
        }
        catch { $tlsMs = $null }
        finally { $tls.Dispose() }
        return , @($tcpMs, $tlsMs)
    }
    catch { return $null }
    finally { $client.Dispose() }
}

function Format-Ms($value) {
    if ($null -eq $value) { return '-' }
    return ('{0:0.0}' -f $value).Replace(',', '.')
}

Write-Output "Live Assist relay: latency from this network to each VPS region (median of $Tries tries)"
Write-Output ("Started {0}. This takes about a minute." -f (Get-Date -Format 'yyyy-MM-dd HH:mm'))
Write-Output ''

$results = foreach ($target in $targets) {
    $provider, $region, $hostName = $target
    $note = ''
    $tcp = @()
    $tls = @()
    $address = $null
    try {
        $address = [System.Net.Dns]::GetHostAddresses($hostName) |
            Where-Object { $_.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork } |
            Select-Object -First 1
    }
    catch { $address = $null }
    if ($null -eq $address) {
        $note = 'does not resolve'
    }
    else {
        for ($i = 0; $i -lt $Tries; $i++) {
            $sample = Measure-Once $address $hostName
            if ($null -eq $sample) { continue }
            $tcp += $sample[0]
            if ($null -ne $sample[1]) { $tls += $sample[1] }
        }
        if ($tcp.Count -eq 0) { $note = 'no answer' }
        elseif ($tls.Count -eq 0) { $note = 'no TLS answer' }
    }
    New-Object PSObject -Property @{
        Provider = $provider
        Region   = $region
        Host     = $hostName
        Tcp      = Get-Median $tcp
        Tls      = Get-Median $tls
        Note     = $note
    }
}

$reachable = @($results | Where-Object { $null -ne $_.Tcp } | Sort-Object Tcp)
$unreachable = @($results | Where-Object { $null -eq $_.Tcp })

Write-Output ('{0,-3}  {1,-16} {2,-29} {3,7} {4,7}  {5}' -f '#', 'Provider', 'Region', 'TCP ms', 'TLS ms', 'Host')
$rank = 0
foreach ($row in $reachable) {
    $rank++
    $suffix = if ($row.Note) { "  ($($row.Note))" } else { '' }
    Write-Output ('{0,-3}  {1,-16} {2,-29} {3,7} {4,7}  {5}{6}' -f $rank, $row.Provider, $row.Region, (Format-Ms $row.Tcp), (Format-Ms $row.Tls), $row.Host, $suffix)
}
foreach ($row in $unreachable) {
    Write-Output ('{0,-3}  {1,-16} {2,-29} {3,7} {4,7}  {5}  ({6})' -f '-', $row.Provider, $row.Region, '-', '-', $row.Host, $row.Note)
}

Write-Output ''
Write-Output 'Top 3:'
$top = @($reachable | Select-Object -First 3)
for ($i = 0; $i -lt $top.Count; $i++) {
    Write-Output ('  {0}. {1} {2} ({3} ms)' -f ($i + 1), $top[$i].Provider, $top[$i].Region, (Format-Ms $top[$i].Tcp))
}
Write-Output ''
Write-Output 'TCP ms is about one round trip to the region; TLS ms is the handshake on top of it.'
Write-Output 'Lower is better. Each suggestion pays about one round trip on top of the model time.'
