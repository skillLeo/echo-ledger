package com.echoledger.mobile.recording

import android.content.Context
import java.time.Instant
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * The capture helper's last complaint ("Call capture could not start: mic source 7: did not start; …"), kept on the
 * phone only so the Setup screen and the "no audio" notice can say why a call had no sound.
 */
object CaptureProblems {
    data class Problem(val message: String, val at: Instant)

    private const val PREFS = "capture_problems"
    private val _last = MutableStateFlow<Problem?>(null)
    val last: StateFlow<Problem?> = _last.asStateFlow()

    fun record(context: Context, message: String) {
        val problem = Problem(message.take(MAX_LENGTH), Instant.now())
        _last.value = problem
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString("message", problem.message).putLong("at", problem.at.toEpochMilli()).apply()
    }

    fun load(context: Context): Problem? {
        _last.value?.let { return it }
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val message = prefs.getString("message", null) ?: return null
        return Problem(message, Instant.ofEpochMilli(prefs.getLong("at", 0))).also { _last.value = it }
    }

    /** The problem when it happened in the last [withinMs] (it belongs to the call that just ended). */
    fun recent(context: Context, withinMs: Long = RECENT_MS): String? =
        load(context)?.takeIf { Instant.now().toEpochMilli() - it.at.toEpochMilli() <= withinMs }?.message

    fun clear(context: Context) {
        _last.value = null
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().clear().apply()
    }

    private const val MAX_LENGTH = 400
    private const val RECENT_MS = 6 * 60 * 60 * 1000L
}
