package com.echoledger.mobile.calls

import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put

/**
 * What happened to a phone call's Phone app recording, as a code each screen says in its own language: a [kind] and
 * what goes in it ([seconds], the call [app] a setting was off for, or the [owner] package of another app's file).
 * [english] is the capture log's line (no names or numbers of people).
 */
data class PhoneAppNote(val kind: Kind, val seconds: Int? = null, val app: String? = null, val owner: String? = null) {
    enum class Kind(val wire: String) {
        /** Brought in ([seconds] long). */
        IMPORTED("imported"),

        /** Brought in ([seconds]); EchoLedger's own recording of the call had one side only. */
        IMPORTED_ONE_SIDE("imported_one_side"),

        /** Brought in ([seconds]) because the person chose it in Settings. */
        IMPORTED_AS_CHOSEN("imported_as_chosen"),

        /** EchoLedger is still saving its own recording of this call. */
        WAITING_FOR_OWN("waiting_for_own"),

        /** EchoLedger's own recording is kept, both sides apart; the Phone app's copy stays. */
        OWN_KEPT_APART("own_kept_apart"),

        /** EchoLedger's own recording is kept, both voices on one track; the Phone app's copy stays. */
        OWN_KEPT_ONE_TRACK("own_kept_one_track"),

        /** EchoLedger heard both sides itself (its file already sent); the Phone app's copy stays. */
        HEARD_BOTH_SIDES("heard_both_sides"),

        /** Shorter than [seconds], the person's Skip calls shorter than. */
        SHORTER_THAN_SETTING("shorter_than_setting"),

        /** Shorter than [seconds], the shortest call EchoLedger keeps. */
        SHORTER_THAN_MINIMUM("shorter_than_minimum"),

        /** EchoLedger's recording of this call was discarded. */
        OWN_DISCARDED("own_discarded"),

        /** Auto-record was off. */
        AUTO_RECORD_OFF("auto_record_off"),

        /** Record phone calls was not set to Always. */
        PHONE_CALLS_NOT_ALWAYS("phone_calls_not_always"),

        /** Phone calls were off when the call came. */
        PHONE_CALLS_OFF("phone_calls_off"),

        /** Auto-record was off for [app]. */
        APP_AUTO_RECORD_OFF("app_auto_record_off"),

        /** The person did not choose Record when asked. */
        RECORD_NOT_CHOSEN("record_not_chosen"),

        /** The call was not to be recorded, for a reason this build does not know by name. */
        NOT_WANTED("not_wanted"),

        /** The phone does not say which app made the file: it waits for the person in Settings. */
        UNKNOWN_MAKER("unknown_maker"),

        /** Another app ([owner], when known) made the file in the call folder. */
        OTHER_APP("other_app"),

        /** The file is larger than a recording of this call can be. */
        TOO_LARGE("too_large"),

        /** The file is gone. */
        FILE_GONE("file_gone"),

        /** The file changed after the call. */
        FILE_CHANGED("file_changed"),

        /** The file changed while it was read. */
        FILE_CHANGED_WHILE_READ("file_changed_while_read"),

        /** EchoLedger can't take this kind of audio file. */
        UNSUPPORTED("unsupported"),

        /** The file chosen in Settings is no longer waiting. */
        NO_LONGER_WAITING("no_longer_waiting"),

        /** The file chosen in Settings no longer fits the call. */
        NO_LONGER_FITS("no_longer_fits"),

        /** Not yet: the file could not be read (it is tried again). */
        UNREADABLE("unreadable"),

        /** Not yet: the Phone app is still saving the file. */
        STILL_SAVING("still_saving"),

        /** The Phone app's copy did not come: EchoLedger's own recording (one side) is kept. */
        COPY_DID_NOT_COME("copy_did_not_come"),

        /** The Phone app's copy took the place of EchoLedger's own (one side). */
        TOOK_THE_PLACE("took_the_place"),

        /** No permission to read audio files or the call log. */
        NO_PERMISSION("no_permission")
    }

    /** The capture log's line, starting lower-case as the log's other lines do. */
    val english: String
        get() = when (kind) {
            Kind.IMPORTED -> "imported the Phone app's recording ($seconds s)."

            Kind.IMPORTED_ONE_SIDE -> "imported the Phone app's recording ($seconds s); EchoLedger's own has one side only."

            Kind.IMPORTED_AS_CHOSEN -> "imported the Phone app's recording ($seconds s), as you chose."

            Kind.WAITING_FOR_OWN -> "waiting: EchoLedger is still saving its own recording of this call."

            Kind.OWN_KEPT_APART -> "EchoLedger's own recording kept (both sides apart); the Phone app's copy stays in its folder."

            Kind.OWN_KEPT_ONE_TRACK ->
                "EchoLedger's own recording kept (both voices on one track); the Phone app's copy stays in its folder."

            Kind.HEARD_BOTH_SIDES -> "EchoLedger heard both sides itself; the Phone app's copy stays in its folder."

            Kind.SHORTER_THAN_SETTING -> "not imported, shorter than $seconds s (Skip calls shorter than)."

            Kind.SHORTER_THAN_MINIMUM -> "not imported, shorter than $seconds s."

            Kind.OWN_DISCARDED -> "not imported: EchoLedger's recording of this call was discarded."

            Kind.AUTO_RECORD_OFF -> "not imported: auto-record was off."

            Kind.PHONE_CALLS_NOT_ALWAYS -> "not imported: Record phone calls was not set to Always."

            Kind.PHONE_CALLS_OFF -> "not imported: phone calls are off."

            Kind.APP_AUTO_RECORD_OFF -> "not imported: auto-record is off for ${app ?: "this app"}."

            Kind.RECORD_NOT_CHOSEN -> "not imported: Record was not chosen."

            Kind.NOT_WANTED -> "not imported: this call was not to be recorded."

            Kind.UNKNOWN_MAKER ->
                "waiting for you: the phone does not say which app made the file; import it in Settings if it is this call's."

            Kind.OTHER_APP -> "not imported: another app" + (owner?.let { " ($it)" } ?: "") + " made the file in the call folder."

            Kind.TOO_LARGE -> "not imported: the file is larger than a recording of this call can be."

            Kind.FILE_GONE -> "not imported: the file is gone."

            Kind.FILE_CHANGED -> "not imported: the file changed after the call."

            Kind.FILE_CHANGED_WHILE_READ -> "not imported: the file changed while it was read."

            Kind.UNSUPPORTED -> "not imported: EchoLedger can't take this kind of audio file."

            Kind.NO_LONGER_WAITING -> "not imported: it is no longer waiting."

            Kind.NO_LONGER_FITS -> "not imported: the file no longer fits the call."

            Kind.UNREADABLE -> "not imported yet: the file could not be read."

            Kind.STILL_SAVING -> "not imported yet: the Phone app is still saving the file."

            Kind.COPY_DID_NOT_COME -> "the Phone app's copy did not come: EchoLedger's own recording (your voice only) is kept as usual."

            Kind.TOOK_THE_PLACE -> "the Phone app's copy took the place of EchoLedger's own (your voice only)."

            Kind.NO_PERMISSION -> "not imported: no permission to read audio files or the call log."
        }

    /** How it is kept on the phone. */
    fun wire(): String = buildJsonObject {
        put("kind", kind.wire)
        seconds?.let { put("seconds", it) }
        app?.let { put("app", it) }
        owner?.let { put("owner", it) }
    }.toString()

    override fun toString(): String = "PhoneAppNote(${kind.wire})"

    companion object {
        fun of(kind: Kind) = PhoneAppNote(kind)

        /** A kept note, or null for one this build can't read (an older build's English sentence, or a newer kind). */
        fun fromWire(text: String?): PhoneAppNote? {
            val o = runCatching { com.echoledger.mobile.data.ApiJson.parseToJsonElement(text ?: return null).jsonObject }.getOrNull()
                ?: return null
            val kind = Kind.entries.firstOrNull { it.wire == (o["kind"] as? JsonPrimitive)?.contentOrNull } ?: return null
            return PhoneAppNote(
                kind,
                seconds = (o["seconds"] as? JsonPrimitive)?.intOrNull,
                app = (o["app"] as? JsonPrimitive)?.contentOrNull,
                owner = (o["owner"] as? JsonPrimitive)?.contentOrNull
            )
        }

        /**
         * Why the capture journal says a call was not to be recorded ([com.echoledger.mobile.recording.CallJournal.NotWanted]),
         * as a code; a reason it does not know (an older build's words) is [Kind.NOT_WANTED], never its free text.
         */
        fun notWanted(reason: String?): PhoneAppNote = with(com.echoledger.mobile.recording.CallJournal.NotWanted) {
            when {
                reason == PHONE_CALLS_OFF -> PhoneAppNote(Kind.PHONE_CALLS_OFF)

                reason == RECORD_NOT_CHOSEN -> PhoneAppNote(Kind.RECORD_NOT_CHOSEN)

                reason != null && reason.startsWith(AUTO_RECORD_OFF_FOR) ->
                    PhoneAppNote(Kind.APP_AUTO_RECORD_OFF, app = reason.removePrefix(AUTO_RECORD_OFF_FOR).trim().ifEmpty { null })

                else -> PhoneAppNote(Kind.NOT_WANTED)
            }
        }
    }
}
