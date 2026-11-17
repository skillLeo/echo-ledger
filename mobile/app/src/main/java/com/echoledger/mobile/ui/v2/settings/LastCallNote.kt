package com.echoledger.mobile.ui.v2.settings

import com.echoledger.mobile.R

/** A last-call note as a code: the words to say (a string resource) and what goes in them. */
data class NoteCode(val words: Int, val args: List<Any> = emptyList())

/**
 * The Phone app import's last-call note (PhoneAppRecordings.lastNote, today an English sentence) as a code each page
 * says in its own language. TODO(phone core): have lastNote() return this code itself; until then the core's sentences
 * are read back here, one pattern each. A note that matches none (a free-form reason) has no code: the page then shows
 * no note rather than English words in another language.
 */
object LastCallNote {
    private val PATTERNS: List<Pair<Regex, (MatchResult) -> NoteCode>> = listOf(
        r("Imported the Phone app's recording \\((\\d+) s\\)\\.") { NoteCode(R.string.v2_calls_note_imported, listOf(it.int(1))) },
        r("Imported the Phone app's recording \\((\\d+) s\\); EchoLedger's own has one side only\\.") {
            NoteCode(R.string.v2_calls_note_imported_one_side, listOf(it.int(1)))
        },
        r("Imported the Phone app's recording \\((\\d+) s\\), as you chose\\.") {
            NoteCode(R.string.v2_calls_note_imported_chosen, listOf(it.int(1)))
        },
        r("Waiting: EchoLedger is still saving its own recording of this call\\.") { NoteCode(R.string.v2_calls_note_wait) },
        r("EchoLedger's own recording kept \\(both sides apart\\); the Phone app's copy stays in its folder\\.") {
            NoteCode(R.string.v2_calls_note_own_apart)
        },
        r("EchoLedger's own recording kept \\(both voices on one track\\); the Phone app's copy stays in its folder\\.") {
            NoteCode(R.string.v2_calls_note_own_one_track)
        },
        r("EchoLedger heard both sides itself; the Phone app's copy stays in its folder\\.") {
            NoteCode(R.string.v2_calls_note_heard_both)
        },
        r("Not imported, shorter than (\\d+) s \\(Skip calls shorter than\\)\\.") {
            NoteCode(R.string.v2_calls_note_short_setting, listOf(it.int(1)))
        },
        r("Not imported, shorter than (\\d+) s\\.") { NoteCode(R.string.v2_calls_note_short, listOf(it.int(1))) },
        r("Not imported: EchoLedger's recording of this call was discarded\\.") { NoteCode(R.string.v2_calls_note_discarded) },
        r("Not imported: auto-record was off\\.") { NoteCode(R.string.v2_calls_note_auto_off) },
        r("Not imported: Record phone calls was not set to Always\\.") { NoteCode(R.string.v2_calls_note_not_always) },
        r("Waiting for you: the phone does not say which app made the file; import it in Settings if it is this call's\\.") {
            NoteCode(R.string.v2_calls_note_unknown_maker)
        },
        r("Not imported: another app made the file in the call folder\\.") { NoteCode(R.string.v2_calls_note_other_app) },
        r("Not imported: another app \\((.+)\\) made the file in the call folder\\.") {
            NoteCode(R.string.v2_calls_note_other_app_named, listOf(it.groupValues[1]))
        },
        r("Not imported: the file is larger than a recording of this call can be\\.") { NoteCode(R.string.v2_calls_note_too_large) },
        r("The Phone app's copy did not come: EchoLedger's own recording \\(your voice only\\) is kept as usual\\.") {
            NoteCode(R.string.v2_calls_note_copy_did_not_come)
        },
        r("Not imported: it is no longer waiting\\.") { NoteCode(R.string.v2_calls_note_no_longer_waiting) },
        r("Not imported: the file is gone\\.") { NoteCode(R.string.v2_calls_note_gone) },
        r("Not imported: the file no longer fits the call\\.") { NoteCode(R.string.v2_calls_note_no_longer_fits) },
        r("Not imported yet: the file could not be read\\.") { NoteCode(R.string.v2_calls_note_unreadable) },
        r("Not imported: the file changed after the call\\.") { NoteCode(R.string.v2_calls_note_changed) },
        r("Not imported: EchoLedger can't take this kind of audio file\\.") { NoteCode(R.string.v2_calls_note_unsupported) },
        r("Not imported yet: the Phone app is still saving the file\\.") { NoteCode(R.string.v2_calls_note_saving) },
        r("Not imported: no permission to read audio files or the call log\\.") { NoteCode(R.string.v2_calls_note_no_permission) },
        r("The Phone app's copy took the place of EchoLedger's own \\(your voice only\\)\\.") {
            NoteCode(R.string.v2_calls_note_took_place)
        }
    )

    private fun r(pattern: String, code: (MatchResult) -> NoteCode) = Regex(pattern, RegexOption.IGNORE_CASE) to code

    private fun MatchResult.int(group: Int): Int = groupValues[group].toInt()

    /** The note's code, or null when it is none of the core's sentences. */
    fun code(text: String): NoteCode? {
        val t = text.trim()
        for ((pattern, code) in PATTERNS) pattern.matchEntire(t)?.let { return code(it) }
        return null
    }
}
