<#
.SYNOPSIS
  Looks for secrets inside built files: the desktop web bundle and executable, and Android APKs.
.DESCRIPTION
  Provider keys must stay on the server, so a release build must contain none of the patterns below.
  Usage: powershell -File tools/scan-secrets.ps1 -Targets <folder|file|apk> [...]
  Prints one line per hit (pattern, count, file, first characters) and "done". No output before "done" = clean.
#>param([string[]]$Targets)
Add-Type -AssemblyName System.IO.Compression.FileSystem
$patterns = [ordered]@{
  'elevenlabs key'      = 'sk_[0-9a-f]{40,}'
  'private key block'   = '-----BEGIN (RSA |EC |OPENSSH |ENCRYPTED )?PRIV' + 'ATE KEY-----'
  'minisign secret'     = 'untrusted comment: [^\r\n]*secret key'
  'aws key id'          = 'AKIA[0-9A-Z]{16}'
  'jwt'                 = 'eyJ[A-Za-z0-9_-]{15,}\.eyJ[A-Za-z0-9_-]{15,}\.'
  'webhook secret'      = 'whsec_[A-Za-z0-9]{8,}'
  'laravel app key'     = 'base64:[A-Za-z0-9+/]{43}='
  'recordings key name' = 'ECHOLEDGER_RECORDINGS_KEY'
  'provider key var'    = '(ASSEMBLYAI|ELEVENLABS|AZURE_SPEECH)_(API_)?KEY'
  'sanctum token'       = '\b\d{1,6}\|[A-Za-z0-9]{40}\b'
  'long bearer literal' = 'Bearer [A-Za-z0-9._-]{24,}'
}
function Scan-Bytes([byte[]]$bytes, [string]$label) {
  $text = [Text.Encoding]::GetEncoding(28591).GetString($bytes)
  foreach ($name in $patterns.Keys) {
    $m = [regex]::Matches($text, $patterns[$name])
    if ($m.Count -gt 0) { "  HIT [$name] x$($m.Count) in $label  (first: " + ($m[0].Value.Substring(0, [Math]::Min(12, $m[0].Value.Length))) + '...)' }
  }
}
foreach ($t in $Targets) {
  "== $t"
  if ($t -like '*.apk') {
    $zip = [IO.Compression.ZipFile]::OpenRead($t)
    try {
      foreach ($e in $zip.Entries) {
        if ($e.Length -eq 0 -or $e.Length -gt 80MB) { continue }
        $ms = New-Object IO.MemoryStream
        $s = $e.Open(); $s.CopyTo($ms); $s.Dispose()
        Scan-Bytes $ms.ToArray() $e.FullName
      }
    } finally { $zip.Dispose() }
  } elseif (Test-Path $t -PathType Container) {
    Get-ChildItem $t -Recurse -File | % { Scan-Bytes ([IO.File]::ReadAllBytes($_.FullName)) $_.FullName.Replace($t, '') }
  } else {
    Scan-Bytes ([IO.File]::ReadAllBytes($t)) (Split-Path $t -Leaf)
  }
}
'done'
