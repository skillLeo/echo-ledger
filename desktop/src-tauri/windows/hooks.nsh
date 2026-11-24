; EchoLedger's installer hooks (tauri.windows.conf.json, bundle.windows.nsis.installerHooks).

; Uninstalling removes what EchoLedger added to Open with (FIX-12, taskbar.rs open_with_values), so Explorer never lists
; a dead entry: its ProgID, and its name under each audio type's OpenWithProgids. Only EchoLedger's own value is removed
; from a type's key; the key and the other apps' entries in it stay. The installed app writes them again at its next
; start (an update that runs this changes nothing for long). This user's (HKCU), as the app and the installer are.
!macro NSIS_HOOK_POSTUNINSTALL
  DeleteRegKey HKCU "Software\Classes\EchoLedger.Audio"
  DeleteRegValue HKCU "Software\Classes\.m4a\OpenWithProgids" "EchoLedger.Audio"
  DeleteRegValue HKCU "Software\Classes\.mp3\OpenWithProgids" "EchoLedger.Audio"
  DeleteRegValue HKCU "Software\Classes\.wav\OpenWithProgids" "EchoLedger.Audio"
  DeleteRegValue HKCU "Software\Classes\.aac\OpenWithProgids" "EchoLedger.Audio"
  DeleteRegValue HKCU "Software\Classes\.ogg\OpenWithProgids" "EchoLedger.Audio"
  DeleteRegValue HKCU "Software\Classes\.opus\OpenWithProgids" "EchoLedger.Audio"
  DeleteRegValue HKCU "Software\Classes\.amr\OpenWithProgids" "EchoLedger.Audio"
  DeleteRegValue HKCU "Software\Classes\.flac\OpenWithProgids" "EchoLedger.Audio"
  DeleteRegValue HKCU "Software\Classes\.webm\OpenWithProgids" "EchoLedger.Audio"
  ; SHCNE_ASSOCCHANGED, SHCNF_IDLIST: Explorer reads the associations again.
  System::Call 'shell32::SHChangeNotify(i 0x08000000, i 0, p 0, p 0)'
!macroend
