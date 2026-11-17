package com.echoledger.mobile.ui.v2.settings

import com.echoledger.mobile.R
import com.echoledger.mobile.calls.PhoneAppNote
import com.echoledger.mobile.calls.PhoneAppNote.Kind

/** A last-call note as words to say (a string resource) and what goes in them. */
data class NoteCode(val words: Int, val args: List<Any> = emptyList())

/**
 * The Phone app import's last-call note (PhoneAppRecordings.lastNote, a [PhoneAppNote]) as words in the screen's
 * language. Every kind has its own words: the `when` is exhaustive, so a new kind does not build without them.
 */
object LastCallNote {
    fun code(note: PhoneAppNote): NoteCode {
        val seconds = listOf<Any>(note.seconds ?: 0)
        return when (note.kind) {
            Kind.IMPORTED -> NoteCode(R.string.v2_calls_note_imported, seconds)

            Kind.IMPORTED_ONE_SIDE -> NoteCode(R.string.v2_calls_note_imported_one_side, seconds)

            Kind.IMPORTED_AS_CHOSEN -> NoteCode(R.string.v2_calls_note_imported_chosen, seconds)

            Kind.WAITING_FOR_OWN -> NoteCode(R.string.v2_calls_note_wait)

            Kind.OWN_KEPT_APART -> NoteCode(R.string.v2_calls_note_own_apart)

            Kind.OWN_KEPT_ONE_TRACK -> NoteCode(R.string.v2_calls_note_own_one_track)

            Kind.HEARD_BOTH_SIDES -> NoteCode(R.string.v2_calls_note_heard_both)

            Kind.SHORTER_THAN_SETTING -> NoteCode(R.string.v2_calls_note_short_setting, seconds)

            Kind.SHORTER_THAN_MINIMUM -> NoteCode(R.string.v2_calls_note_short, seconds)

            Kind.OWN_DISCARDED -> NoteCode(R.string.v2_calls_note_discarded)

            Kind.AUTO_RECORD_OFF -> NoteCode(R.string.v2_calls_note_auto_off)

            Kind.PHONE_CALLS_NOT_ALWAYS -> NoteCode(R.string.v2_calls_note_not_always)

            Kind.PHONE_CALLS_OFF -> NoteCode(R.string.v2_calls_note_phone_calls_off)

            Kind.APP_AUTO_RECORD_OFF -> note.app?.let { NoteCode(R.string.v2_calls_note_app_auto_off, listOf(it)) }
                ?: NoteCode(R.string.v2_calls_note_auto_off)

            Kind.RECORD_NOT_CHOSEN -> NoteCode(R.string.v2_calls_note_record_not_chosen)

            Kind.NOT_WANTED -> NoteCode(R.string.v2_calls_note_not_wanted)

            Kind.UNKNOWN_MAKER -> NoteCode(R.string.v2_calls_note_unknown_maker)

            Kind.OTHER_APP -> note.owner?.let { NoteCode(R.string.v2_calls_note_other_app_named, listOf(it)) }
                ?: NoteCode(R.string.v2_calls_note_other_app)

            Kind.TOO_LARGE -> NoteCode(R.string.v2_calls_note_too_large)

            Kind.FILE_GONE -> NoteCode(R.string.v2_calls_note_gone)

            Kind.FILE_CHANGED -> NoteCode(R.string.v2_calls_note_changed)

            Kind.FILE_CHANGED_WHILE_READ -> NoteCode(R.string.v2_calls_note_changed_while_read)

            Kind.UNSUPPORTED -> NoteCode(R.string.v2_calls_note_unsupported)

            Kind.NO_LONGER_WAITING -> NoteCode(R.string.v2_calls_note_no_longer_waiting)

            Kind.NO_LONGER_FITS -> NoteCode(R.string.v2_calls_note_no_longer_fits)

            Kind.UNREADABLE -> NoteCode(R.string.v2_calls_note_unreadable)

            Kind.STILL_SAVING -> NoteCode(R.string.v2_calls_note_saving)

            Kind.COPY_DID_NOT_COME -> NoteCode(R.string.v2_calls_note_copy_did_not_come)

            Kind.TOOK_THE_PLACE -> NoteCode(R.string.v2_calls_note_took_place)

            Kind.NO_PERMISSION -> NoteCode(R.string.v2_calls_note_no_permission)
        }
    }
}
