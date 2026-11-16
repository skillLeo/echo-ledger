package com.echoledger.mobile.core

import android.content.Context

/**
 * [ShareGuard.Memory] in a small preference file of its own (since v1.8.1): read once when the process starts, before
 * any floating window opens and without the settings DataStore, which loads later and only once per process. Private
 * is written as "hide", like the setting itself.
 */
class ShareGuardMemory(context: Context) : ShareGuard.Memory {
    private val prefs = context.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    override fun read(): ShareGuard.Saved? {
        if (!prefs.contains(MODE)) return null
        return ShareGuard.Saved(ShareGuard.Mode.of(prefs.getString(MODE, null)), prefs.getBoolean(HIDDEN, false))
    }

    override fun write(saved: ShareGuard.Saved) {
        prefs.edit().putString(MODE, saved.mode.wire).putBoolean(HIDDEN, saved.hidden).apply()
    }

    private companion object {
        const val FILE = "screen_share_privacy"
        const val MODE = "mode"
        const val HIDDEN = "hidden"
    }
}
