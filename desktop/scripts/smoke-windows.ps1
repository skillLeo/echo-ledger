# Runs the built Windows app's smoke test (src-tauri/src/smoke.rs): the app starts against a temporary folder with no
# account, opens its windows, waits until they are ready, quits and exits 0. The test fails on a non-zero exit, on a
# hang past the limit, and on a new "Application Error" for it in the Windows event log.
#   pwsh -File desktop/scripts/smoke-windows.ps1 path\to\echoledger-desktop.exe [seconds]
param(
  [Parameter(Mandatory = $true)][string]$Exe,
  [int]$Limit = 180
)
$ErrorActionPreference = 'Stop'
$version = [System.Environment]::OSVersion.Version
$caption = (Get-CimInstance Win32_OperatingSystem).Caption
"Windows: $caption, build $($version.Build), $env:PROCESSOR_ARCHITECTURE"
$webview = Get-ItemProperty -ErrorAction SilentlyContinue 'HKLM:\SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}'
"WebView2 runtime: $($webview.pv)"

# On a person's PC there is no runner's folder.
$temp = if ($env:RUNNER_TEMP) { $env:RUNNER_TEMP } else { [System.IO.Path]::GetTempPath() }
$out = Join-Path $temp 'smoke-out.txt'
$err = Join-Path $temp 'smoke-err.txt'
$started = Get-Date
$process = Start-Process -FilePath $Exe -ArgumentList '--smoke-test' -PassThru -RedirectStandardOutput $out -RedirectStandardError $err
$null = $process.Handle # keeps the exit code readable after it ended
$finished = $process.WaitForExit($Limit * 1000)
if (-not $finished) {
  $process.Kill()
  Get-Content $err -ErrorAction SilentlyContinue
  throw "Smoke test failed: the app was still running after $Limit s."
}
Get-Content $out -ErrorAction SilentlyContinue
$log = Get-Content $err -ErrorAction SilentlyContinue
$log

Start-Sleep -Seconds 5
$name = [System.IO.Path]::GetFileName($Exe)
$crashes = Get-WinEvent -FilterHashtable @{ LogName = 'Application'; ProviderName = 'Application Error'; StartTime = $started } -ErrorAction SilentlyContinue |
  Where-Object { $_.Message -match [regex]::Escape($name) }
if ($crashes) {
  $crashes | ForEach-Object { $_.Message }
  throw 'Smoke test failed: Windows logged an application error for it.'
}
if ($process.ExitCode -ne 0) { throw "Smoke test failed: the app exited with $($process.ExitCode)." }
if (-not ($log -match 'smoke test: ok')) { throw 'Smoke test failed: the app ended without saying its windows were ready.' }
"Smoke test passed in about $([int]((Get-Date) - $started).TotalSeconds) s."
