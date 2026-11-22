package com.echoledger.mobile.calls

import android.app.NotificationManager
import android.content.Context
import com.echoledger.mobile.core.Activity
import com.echoledger.mobile.data.AppSettings

/**
 * The call choice (docs/v2/copy/call-choice.md, 2.0.5): Settings › Recording › "After each call" and "When to ask", the
 * choice made on the recording pill during a call, and what a finished call becomes. Pure except [quietNow].
 *
 * - Ask me (the default): the after-call card, or with "At the start of the call" a short banner when the call
 *   connects; after it the call is kept as audio only.
 * - Transcribe every call / Save audio only: no question.
 * - Don't record calls: calls are not recorded automatically; Record and the pill still record one by hand.
 */
object CallChoice {
    const val ASK = "ask"
    const val TRANSCRIBE = "transcribe"
    const val AUDIO = "audio"
    const val OFF = "off"

    /** The four values of After each call, in the order Settings lists them. */
    val SETTINGS = listOf(ASK, TRANSCRIBE, AUDIO, OFF)

    const val WHEN_AFTER = "after"
    const val WHEN_START = "start"

    /** How long the card asks before the call is kept as audio only, and how long a confirmation stays. */
    const val CARD_MS = 8_000L
    const val DONE_MS = 5_000L

    /** The same choice this many times in a row on the card offers "Always do this?" once. */
    const val ALWAYS_AFTER = 3

    /** What was chosen for one call: on the pill during it, on the card after it, or by the setting. */
    enum class Pick(val wire: String) {
        TRANSCRIBE("transcribe"),
        AUDIO("audio"),
        DELETE("delete");

        companion object {
            fun of(wire: String?): Pick? = entries.firstOrNull { it.wire == wire }
        }
    }

    /** What a finished call becomes. */
    enum class Outcome {
        /** Waiting for the after-call card. */
        CARD,

        /** Kept and transcribed. */
        TRANSCRIBE,

        /** Kept as audio only (transcribed later from the Library if the person wants). */
        AUDIO,

        /** Kept as audio only because quiet hours or Do Not Disturb were on: no card, marked in the Library. */
        AUDIO_QUIET
    }

    fun setting(s: AppSettings): String = s.afterEachCall.takeIf { it in SETTINGS } ?: ASK

    fun whenToAsk(s: AppSettings): String = if (s.whenToAsk == WHEN_START) WHEN_START else WHEN_AFTER

    /** "At the start of the call": the banner when the call connects instead of the card after it. */
    fun asksAtStart(s: AppSettings): Boolean = setting(s) == ASK && whenToAsk(s) == WHEN_START

    /** Don't record calls stops automatic call recording only. */
    fun recordsCallsAutomatically(s: AppSettings): Boolean = setting(s) != OFF

    /**
     * The old "After a call" (until 2.0.5) as After each call: always Ask me, the plan's default, as on the desktop. An
     * old Keep automatically (the old default) is safe there too: nobody choosing on the card keeps the audio.
     */
    @Suppress("UNUSED_PARAMETER")
    fun fromOld(afterCall: String?): String = ASK

    /**
     * What a call that just ended becomes: a choice on the pill wins, then the setting, then quiet hours. With At the
     * start, only a call whose banner showed ([bannerShown]) was asked; any other is asked with the card.
     */
    fun outcome(s: AppSettings, duringCall: Pick?, quiet: Boolean, bannerShown: Boolean = false): Outcome = when {
        duringCall == Pick.TRANSCRIBE -> Outcome.TRANSCRIBE

        duringCall == Pick.AUDIO -> Outcome.AUDIO

        setting(s) == TRANSCRIBE -> Outcome.TRANSCRIBE

        setting(s) == AUDIO -> Outcome.AUDIO

        // Asked at the start (the banner), so nothing more after the call.
        asksAtStart(s) && bannerShown -> Outcome.AUDIO

        quiet -> Outcome.AUDIO_QUIET

        else -> Outcome.CARD
    }

    /** The stored state and transcribe flag of a call with [outcome]. */
    fun stored(outcome: Outcome): Pair<String, Boolean?> = when (outcome) {
        Outcome.CARD -> "pending_decision" to null
        Outcome.TRANSCRIBE -> "queued" to true
        Outcome.AUDIO, Outcome.AUDIO_QUIET -> "queued" to false
    }

    /** Quiet hours at [nowMinutes] (minutes after midnight), from Notifications › Quiet hours. */
    fun inQuietHours(s: AppSettings, nowMinutes: Int): Boolean = Activity.inQuietHours(s.quietHours, s.quietFrom, s.quietTo, nowMinutes)

    /** Quiet hours, or the phone's Do Not Disturb (any interruption filter but "all"). */
    fun quietNow(context: Context, s: AppSettings, nowMinutes: Int = minutesNow()): Boolean {
        if (inQuietHours(s, nowMinutes)) return true
        val filter = runCatching { context.getSystemService(NotificationManager::class.java)?.currentInterruptionFilter }.getOrNull()
        return filter != null && filter != NotificationManager.INTERRUPTION_FILTER_ALL &&
            filter != NotificationManager.INTERRUPTION_FILTER_UNKNOWN
    }

    private fun minutesNow(): Int = java.time.LocalTime.now().let { it.hour * 60 + it.minute }

    /** The settings after [pick] on the card: the run of identical choices grows or starts again. */
    fun afterPick(s: AppSettings, pick: Pick): AppSettings = if (s.callChoiceStreak == pick.wire) {
        s.copy(callChoiceStreakCount = s.callChoiceStreakCount + 1)
    } else {
        s.copy(callChoiceStreak = pick.wire, callChoiceStreakCount = 1)
    }

    /**
     * The choice "Always do this?" offers on the next card: after [ALWAYS_AFTER] identical choices in a row, once. Only
     * Transcribe and Save audio only: three deleted calls are no reason to stop recording calls.
     */
    fun alwaysOffer(s: AppSettings): Pick? {
        if (s.callChoiceAlwaysOffered || s.callChoiceStreakCount < ALWAYS_AFTER || setting(s) != ASK) return null
        return Pick.of(s.callChoiceStreak)?.takeIf { it != Pick.DELETE }
    }

    /** Each After each call value's words and help line (docs/v2/copy/call-choice.md), in Settings' order. */
    val SETTING_WORDS: List<Triple<String, Int, Int>> = listOf(
        Triple(ASK, com.echoledger.mobile.R.string.cc_setting_ask, com.echoledger.mobile.R.string.cc_setting_ask_help),
        Triple(TRANSCRIBE, com.echoledger.mobile.R.string.cc_setting_transcribe, com.echoledger.mobile.R.string.cc_setting_transcribe_help),
        Triple(AUDIO, com.echoledger.mobile.R.string.cc_setting_audio, com.echoledger.mobile.R.string.cc_setting_audio_help),
        Triple(OFF, com.echoledger.mobile.R.string.cc_setting_off, com.echoledger.mobile.R.string.cc_setting_off_help)
    )

    /** When to ask's two values and their words. */
    val WHEN_WORDS: List<Pair<String, Int>> = listOf(
        WHEN_AFTER to com.echoledger.mobile.R.string.cc_when_after,
        WHEN_START to com.echoledger.mobile.R.string.cc_when_start
    )

    /** The After each call value one tap on "Always do this? Yes" sets. */
    fun settingFor(pick: Pick): String = if (pick == Pick.TRANSCRIBE) TRANSCRIBE else AUDIO
}
