package com.echoledger.mobile.calls

import android.content.Context

/**
 * The calls kept as audio only because quiet hours or Do Not Disturb were on when they ended (the call-choice plan,
 * 2.0.5): no card was shown, so the Library says "Saved as audio during quiet hours" on them. Their local ids and, once
 * sent, their server ids; the newest [LIMIT] only.
 */
object QuietSaved {
    private const val PREFS = "quiet_saved"
    private const val IDS = "ids"
    private const val LIMIT = 200

    private fun prefs(context: Context) = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    private fun ids(context: Context): List<String> = prefs(context).getString(IDS, null)?.split(',')?.filter { it.isNotBlank() }.orEmpty()

    @Synchronized
    fun mark(context: Context, id: String) {
        val now = (ids(context) - id + id).takeLast(LIMIT)
        prefs(context).edit().putString(IDS, now.joinToString(",")).apply()
    }

    /** Whether the recording with any of [ids] (its local or server id) was saved during quiet hours. */
    fun has(context: Context, vararg ids: String?): Boolean {
        val saved = ids(context).toSet()
        return ids.any { it != null && it in saved }
    }

    @Synchronized
    fun clear(context: Context) {
        prefs(context).edit().clear().apply()
    }
}
