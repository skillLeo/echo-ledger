package com.echoledger.mobile.core

import android.content.Context

/**
 * [ShareGuard.Memory] in a small preference file of its own (since v1.8.1): Settings → During screen sharing, read once
 * when the process starts, before any floating window opens and without the settings DataStore, which loads later and
 * only once per process. Private is written as "hide", like the setting itself. The person's Hide is not kept.
 */
class ShareGuardMemory(context: Context) : ShareGuard.Memory {
    private val prefs = context.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    override fun read(): ShareGuard.Saved? {
        if (!prefs.contains(MODE) && !prefs.contains(BLOCK)) return null
        return ShareGuard.Saved(ShareGuard.Mode.of(prefs.getString(MODE, null)), prefs.getBoolean(BLOCK, false))
    }

    override fun write(saved: ShareGuard.Saved) {
        // "hidden" was kept by the first v1.8.1 builds; it goes with the next change of the setting.
        prefs.edit().putString(MODE, saved.mode.wire).putBoolean(BLOCK, saved.block).remove(HIDDEN).apply()
    }

    private companion object {
        const val FILE = "screen_share_privacy"
        const val MODE = "mode"
        const val BLOCK = "block_screens"
        const val HIDDEN = "hidden"
    }
}
