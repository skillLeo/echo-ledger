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
        "1 recording updated" to R.string.v2_vm_one_updated,
        "Sign in on this phone first." to R.string.v2_vm_sign_in_first,
        // The workspace calls' own failure sentences (when the server gave no plain one).
        "It could not be removed." to R.string.v2_vm_ws_it_could_not_be_removed,
        "It could not be saved." to R.string.v2_vm_ws_it_could_not_be_saved,
        "It could not be sent." to R.string.v2_vm_ws_it_could_not_be_sent,
        "It could not be stopped." to R.string.v2_vm_ws_it_could_not_be_stopped,
        "Shared with me could not be loaded." to R.string.v2_vm_ws_shared_with_me_could_not_be_loaded,
        "Signing out failed." to R.string.v2_vm_ws_signing_out_failed,
        "The AI assistant could not answer right now. Try again in a minute." to R.string.v2_vm_ws_the_ai_assistant_could_not_answer_right,
        "The account could not be deleted." to R.string.v2_vm_ws_the_account_could_not_be_deleted,
        "The account could not be loaded." to R.string.v2_vm_ws_the_account_could_not_be_loaded,
        "The brief could not be made again." to R.string.v2_vm_ws_the_brief_could_not_be_made_again,
        "The brief could not be saved." to R.string.v2_vm_ws_the_brief_could_not_be_saved,
        "The export could not be checked." to R.string.v2_vm_ws_the_export_could_not_be_checked,
        "The export could not be started." to R.string.v2_vm_ws_the_export_could_not_be_started,
        "The file could not be removed." to R.string.v2_vm_ws_the_file_could_not_be_removed,
        "The files could not be added." to R.string.v2_vm_ws_the_files_could_not_be_added,
        "The filing rules could not be loaded." to R.string.v2_vm_ws_the_filing_rules_could_not_be_loaded,
        "The instructions could not be saved." to R.string.v2_vm_ws_the_instructions_could_not_be_saved,
        "The line could not be added." to R.string.v2_vm_ws_the_line_could_not_be_added,
        "The line could not be removed." to R.string.v2_vm_ws_the_line_could_not_be_removed,
        "The line could not be saved." to R.string.v2_vm_ws_the_line_could_not_be_saved,
        "The links could not be loaded." to R.string.v2_vm_ws_the_links_could_not_be_loaded,
        "The notes could not be started." to R.string.v2_vm_ws_the_notes_could_not_be_started,
        "The project could not be deleted." to R.string.v2_vm_ws_the_project_could_not_be_deleted,
        "The project could not be moved." to R.string.v2_vm_ws_the_project_could_not_be_moved,
        "The project could not be renamed." to R.string.v2_vm_ws_the_project_could_not_be_renamed,
        "The project's workspace could not be loaded." to R.string.v2_vm_ws_the_project_s_workspace_could_not_be_loa,
        "The report could not be sent." to R.string.v2_vm_ws_the_report_could_not_be_sent,
        "The request link could not be made." to R.string.v2_vm_ws_the_request_link_could_not_be_made,
        "The request link could not be turned off." to R.string.v2_vm_ws_the_request_link_could_not_be_turned_off,
        "The request links could not be loaded." to R.string.v2_vm_ws_the_request_links_could_not_be_loaded,
        "The rule could not be added." to R.string.v2_vm_ws_the_rule_could_not_be_added,
        "The rule could not be removed." to R.string.v2_vm_ws_the_rule_could_not_be_removed,
        "The setting could not be saved." to R.string.v2_vm_ws_the_setting_could_not_be_saved,
        "The suggestion could not be saved." to R.string.v2_vm_ws_the_suggestion_could_not_be_saved,
        "The text could not be made." to R.string.v2_vm_ws_the_text_could_not_be_made,
        "Project deleted" to R.string.v2_vm_ws_project_deleted,
        "Welcome to EchoLedger" to R.string.v2_vm_welcome
    )

    /**
     * The view model's sentences with a value in them: the exact Kotlin template in MainViewModel.kt (the test finds
     * each there), the pattern that reads it back, and the key that says it in the screen's language.
     */
    class Template(val source: String, val pattern: Regex, val key: Int, val sample: String? = null)

    val templates = listOf(
        Template("Signed in to \${payload.url.removePrefix(\"https://\")}", Regex("""Signed in to (.+)"""), R.string.v2_vm_signed_in_to),
        Template("Signed in as \${email.trim()}", Regex("""Signed in as (.+)"""), R.string.v2_vm_signed_in_as),
        Template("Signed in as \$email", Regex("""Signed in as (.+)"""), R.string.v2_vm_signed_in_as),
        Template("Sent to \$sent people", Regex("""Sent to (\d+) people"""), R.string.v2_vm_sent_many),
        Template(
            " · \${countLabel(answer.movedSessions)} moved to the Inbox",
            Regex("""Project deleted · (\d+ recordings?) moved to the Inbox"""),
            R.string.v2_vm_project_deleted_moved,
            sample = "Project deleted · 3 recordings moved to the Inbox"
        ),
        Template("\$changed deleted", Regex("""(\d+) deleted"""), R.string.v2_vm_many_deleted),
        Template("\$changed recordings updated", Regex("""(\d+) recordings updated"""), R.string.v2_vm_many_updated),
        Template("Added “\${term.trim()}” to the vocabulary", Regex("""Added “(.+)” to the vocabulary"""), R.string.v2_vm_term_added),
        Template(
            "Re-transcribing in \${Languages.label(language)}. The current transcript stays until the new one is ready.",
            Regex("""Re-transcribing in (.+)\. The current transcript stays until the new one is ready\."""),
            R.string.v2_vm_retranscribing
        )
    )

    /**
     * Sentences the view model can say that stay in English on purpose. A new sentence must get a key or be listed
     * here (V2WordsTest). The transcription-key refusal names an engine, which the owner's screens no longer do; it
     * stays English until the view model's own words change.
     */
    val englishOnly: Set<String> = setOf("Groq did not accept that key. Copy it again from console.groq.com.")

    /** The English sentences that have a key (the test checks each against the view model's source). */
    val known: Set<String> get() = exact.keys

    fun localize(res: Resources, english: String): String {
        exact[english]?.let { return res.getString(it) }
        for (t in templates) {
            t.pattern.matchEntire(english)?.let { m -> return res.getString(t.key, m.groupValues[1]) }
        }
        return english
    }
}
