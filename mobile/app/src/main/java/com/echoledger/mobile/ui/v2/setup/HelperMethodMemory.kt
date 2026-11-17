package com.echoledger.mobile.ui.v2.setup

import android.content.Context
import com.echoledger.mobile.privilege.HelperConnection

/**
 * How the capture helper was last started on this phone ("shizuku" or "adb", HelperConnection's source), kept so the
 * wizard knows after a restart, when the helper is gone, which method to bring back. Written whenever the v2 screens
 * see the helper connected.
 */
// TODO(phone core): the core is moving this record into every helper connection and exposing it in the readiness
// state. Read it from there once it lands and delete this UI-owned store, which only sees connections while a v2
// screen is open.
object HelperMethodMemory {
    private const val PREFS = "v2_setup"
    private const val KEY = "helper_method"

    fun method(source: String?): HelperMethod? = when (source) {
        "shizuku" -> HelperMethod.Shizuku
        "adb" -> HelperMethod.Computer
        else -> null
    }

    fun remember(context: Context, state: HelperConnection.State) {
        if (!state.connected) return
        val method = method(state.source) ?: return
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString(KEY, method.name).apply()
    }

    fun last(context: Context): HelperMethod? = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY, null)
        ?.let { name -> HelperMethod.entries.firstOrNull { it.name == name } }
}
