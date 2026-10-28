package com.echoledger.mobile.core

import android.media.AudioManager
import com.echoledger.mobile.data.CallerDto

/**
 * SIM phone calls as a source (since v1.2): the "Record phone calls" setting, how the capture helper tells a SIM call
 * from a WhatsApp call, and which call-log row belongs to a recording. Pure; unit-tested.
 */
object PhoneCalls {
    const val OFF = "off"
    const val ASK = "ask"
    const val ALWAYS = "always"
    val MODES = listOf(OFF, ASK, ALWAYS)

    /** A call-log row this long before the recording started still belongs to it (the row's time is when it rang). */
    const val BEFORE_MS = 2 * 60_000L

    /** The setting's value, `off` for anything unknown. */
    fun mode(value: String?): String = value?.takeIf { it in MODES } ?: OFF

    fun label(mode: String): String = when (mode(mode)) {
        ASK -> "Ask each time"
        ALWAYS -> "Always"
        else -> "Off"
    }

    /**
     * The call app for the phone's audio mode: a SIM call ([CallTargets.TELEPHONY]) in MODE_IN_CALL, the WhatsApp
     * package playing the voice call in MODE_IN_COMMUNICATION ([voipPackage]), otherwise none.
     */
    fun callApp(mode: Int, voipPackage: () -> String?): String? = when (mode) {
        AudioManager.MODE_IN_CALL -> CallTargets.TELEPHONY
        AudioManager.MODE_IN_COMMUNICATION -> voipPackage()
        else -> null
    }

    /** Still in a call of either kind (the helper ends a recording when this turns false). */
    fun inCall(mode: Int): Boolean = mode == AudioManager.MODE_IN_CALL || mode == AudioManager.MODE_IN_COMMUNICATION

    /** One call-log row: the number, the contact's name when the phone knows it, when it started. */
    data class LogEntry(val number: String?, val name: String?, val dateMs: Long)

    /** The row of a call recorded from [startMs] to [endMs]: the one that began closest to the start, or null. */
    fun pick(entries: List<LogEntry>, startMs: Long, endMs: Long): LogEntry? = entries
        .filter { it.dateMs in (startMs - BEFORE_MS)..endMs && (!it.number.isNullOrBlank() || !it.name.isNullOrBlank()) }
        .minByOrNull { kotlin.math.abs(it.dateMs - startMs) }

    /** What the recording says about the caller: the contact's name and the number ("contacts"), or the number alone ("phone"). */
    fun caller(entry: LogEntry?): CallerDto? {
        entry ?: return null
        val name = entry.name?.trim()?.takeIf { it.isNotEmpty() }?.take(120)
        val number = entry.number?.trim()?.takeIf { it.isNotEmpty() && it.any(Char::isDigit) }?.take(40)
        if (name == null && number == null) return null
        return CallerDto(name = name, number = number, source = if (name != null) "contacts" else "phone")
    }
}
