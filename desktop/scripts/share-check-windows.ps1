# Runs the built Windows app's Share Check (src-tauri/src/privacy/check.rs, `--smoke-test --share-check`): a protected
# magenta window and an unprotected green control open on the primary monitor, the monitor is captured with
# Windows.Graphics.Capture and DXGI Desktop Duplication, and the colours are counted. Passes when no magenta pixel and
# the green control are in the captures; fails when a magenta pixel is. When the runner has no capture session (no
# desktop to duplicate, a service session), nothing can be measured: the step says so as a warning with the reason and
# is marked skipped, never a silent pass.
#   pwsh -File desktop/scripts/share-check-windows.ps1 path\to\echoledger-desktop.exe [seconds]
param(
  [Parameter(Mandatory = $true)][string]$Exe,
  [int]$Limit = 180
)
$ErrorActionPreference = 'Stop'
$version = [System.Environment]::OSVersion.Version
"Windows build $($version.Build), $env:PROCESSOR_ARCHITECTURE, session $([System.Diagnostics.Process]::GetCurrentProcess().SessionId)"
$temp = if ($env:RUNNER_TEMP) { $env:RUNNER_TEMP } else { [System.IO.Path]::GetTempPath() }
$out = Join-Path $temp 'share-check-out.txt'
$err = Join-Path $temp 'share-check-err.txt'
$process = Start-Process -FilePath $Exe -ArgumentList '--smoke-test', '--share-check' -PassThru -RedirectStandardOutput $out -RedirectStandardError $err
$null = $process.Handle
if (-not $process.WaitForExit($Limit * 1000)) {
  $process.Kill()
  Get-Content $err -ErrorAction SilentlyContinue
  throw "Share Check failed: the app was still running after $Limit s."
}
Get-Content $out -ErrorAction SilentlyContinue
$log = Get-Content $err -ErrorAction SilentlyContinue
$log
switch ($process.ExitCode) {
  0 {
    if (-not ($log -match 'share check: pass')) { throw 'Share Check failed: the app ended without a result.' }
    if (-not ($log -match 'wgc pass') -or -not ($log -match 'dxgi pass')) {
      $reason = ($log | Select-String -Pattern '(wgc|dxgi) error.*').Matches.Value -join '; '
      "::warning title=Share Check::One capture method measured nothing on this runner: $reason"
    }
    'Share Check passed: no magenta pixel, the green control present.'
  }
  4 { throw 'Share Check failed: the protected test window was in a screen capture.' }
  5 {
    $reason = ($log | Select-String -Pattern 'SKIPPED.*').Matches.Value -join ' '
    "::warning title=Share Check skipped::This runner has no capture session, so nothing could be measured. $reason"
    'SKIPPED: no capture session on this runner (see the reason above).'
  }
  default { throw "Share Check failed: the app exited with $($process.ExitCode)." }
}
