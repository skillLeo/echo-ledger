package com.echoledger.mobile.recording

import android.content.Context

/** The last call that recorded only your voice, and why (since v1.5, A5): shown in the Ready check until a call has both. */
object OneSidedCalls {
    private const val PREFS = "readiness"
    private const val KEY = "last_one_sided"

    fun record(context: Context, why: String) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString(KEY, why).apply()
    }

    fun clear(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().remove(KEY).apply()
    }

    fun last(context: Context): String? = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY, null)
}
