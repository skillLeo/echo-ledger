package com.echoledger.mobile.ui.v2.shell

import android.content.res.Resources
import com.echoledger.mobile.R
import com.echoledger.mobile.core.SourceApp

/** A recording's source in the screen's language, from the server's `source_app` key (never its English label). */
fun sourceName(res: Resources, wire: String?): String = res.getString(
    when (SourceApp.from(wire)) {
        SourceApp.WHATSAPP -> R.string.v2_source_whatsapp
        SourceApp.WHATSAPP_BUSINESS -> R.string.v2_source_whatsapp_business
        SourceApp.WHATSAPP_WEB -> R.string.v2_source_whatsapp_web
        SourceApp.GOOGLE_MEET -> R.string.v2_source_google_meet
        SourceApp.ZOOM -> R.string.v2_source_zoom
        SourceApp.MICROSOFT_TEAMS -> R.string.v2_source_microsoft_teams
        SourceApp.TELEGRAM -> R.string.v2_source_telegram
        SourceApp.SIGNAL -> R.string.v2_source_signal
        SourceApp.MESSENGER -> R.string.v2_source_messenger
        SourceApp.INSTAGRAM -> R.string.v2_source_instagram
        SourceApp.SKYPE -> R.string.v2_source_skype
        SourceApp.SLACK -> R.string.v2_source_slack
        SourceApp.DISCORD -> R.string.v2_source_discord
        SourceApp.PHONE_CALL -> R.string.v2_source_phone_call
        SourceApp.VOICE_NOTE -> R.string.v2_source_voice_note
        SourceApp.OTHER -> R.string.v2_source_other
    }
)

/**
 * The view model's one-line messages in the screen's language. The view model speaks English; each sentence it can say
 * maps to a key here (strings_v2_core.xml, `v2_vm_*`). A sentence with no key (a server's own words) shows as it is.
 */
object VmMessages {
    private val exact = mapOf(
        "Can't reach the server. Try again." to R.string.v2_vm_cant_reach,
        "Could not sign in" to R.string.v2_vm_could_not_sign_in,
        "Could not start the capture helper through Shizuku." to R.string.v2_vm_shizuku_start_failed,
        "Device revoked" to R.string.v2_vm_device_revoked,
        "Erased" to R.string.v2_vm_erased,
        "It is uploading now, so it was not deleted. It will be in your Library in a moment." to R.string.v2_vm_uploading_not_deleted,
        "Key removed" to R.string.v2_vm_key_removed,
        "Key saved. New recordings are transcribed with it." to R.string.v2_vm_key_saved,
        "Offline — showing the last synced library" to R.string.v2_vm_offline,
        "Rename it in the console: Account → Devices." to R.string.v2_vm_rename_in_console,
        "Renamed" to R.string.v2_vm_renamed,
        "Restored — it uploads now" to R.string.v2_vm_restored_uploads,
        "Restored" to R.string.v2_vm_restored,
        "Saved to your library" to R.string.v2_vm_saved_to_library,
        "Sent to 1 person" to R.string.v2_vm_sent_one,
        "Sent to your admin" to R.string.v2_vm_sent_admin,
        "Settings imported" to R.string.v2_vm_settings_imported,
        "Settings saved" to R.string.v2_vm_settings_saved,
        "Shizuku is not running. Start it (Wireless debugging) and try again." to R.string.v2_vm_shizuku_not_running,
        "Signed in" to R.string.v2_vm_signed_in,
        "Signing in failed" to R.string.v2_vm_signing_in_failed,
        "Stop the recording first, then run the test." to R.string.v2_vm_stop_recording_first,
        "Stopped sharing" to R.string.v2_vm_stopped_sharing,
        "That sign-in link is not for this sign-in. Start again." to R.string.v2_vm_wrong_sign_in_link,
        "The audio could not be downloaded. Check the connection and try again." to R.string.v2_vm_audio_download_failed,
        "The change could not be saved." to R.string.v2_vm_change_not_saved,
        "The device could not be revoked." to R.string.v2_vm_device_not_revoked,
        "The export failed." to R.string.v2_vm_export_failed,
        "The file could not be read." to R.string.v2_vm_file_unreadable,
        "The link could not be made." to R.string.v2_vm_link_not_made,
        "The meeting pack could not be made." to R.string.v2_vm_pack_not_made,
        "The project could not be created." to R.string.v2_vm_project_not_created,
        "The project could not be updated." to R.string.v2_vm_project_not_updated,
        "The recording could not be erased." to R.string.v2_vm_not_erased,
        "The recording could not be re-transcribed." to R.string.v2_vm_not_retranscribed,
        "The recording could not be restored." to R.string.v2_vm_not_restored,
        "The settings could not be saved." to R.string.v2_vm_settings_not_saved,
        "The term could not be added." to R.string.v2_vm_term_not_added,
        "The views could not be started." to R.string.v2_vm_views_not_started,
        "This phone was signed out on the server. Sign in again." to R.string.v2_vm_signed_out,
        "This recording has no audio on the server." to R.string.v2_vm_no_audio_on_server,
        "Welcome to EchoLedger" to R.string.v2_vm_welcome
    )

    private val patterns = listOf(
        Regex("""Signed in as (.+)""") to R.string.v2_vm_signed_in_as,
        Regex("""Sent to (\d+) people""") to R.string.v2_vm_sent_many,
        Regex("""Added “(.+)” to the vocabulary""") to R.string.v2_vm_term_added,
        Regex("""Re-transcribing in (.+)\. The current transcript stays until the new one is ready\.""") to R.string.v2_vm_retranscribing
    )

    /** The English sentences that have a key (the test checks each against the view model's source). */
    val known: Set<String> get() = exact.keys

    fun localize(res: Resources, english: String): String {
        exact[english]?.let { return res.getString(it) }
        for ((re, id) in patterns) {
            re.matchEntire(english)?.let { m -> return res.getString(id, m.groupValues[1]) }
        }
        return english
    }
}
