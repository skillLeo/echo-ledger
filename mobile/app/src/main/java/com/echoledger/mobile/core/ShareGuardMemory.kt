package com.echoledger.mobile.core

import android.content.Context

/**
 * [ShareGuard.Memory] in a small preference file of its own (since v1.8.1): Hide EchoLedger's live words (since 2.0.5),
 * read once when the process starts, before any floating window opens and without the settings DataStore, which loads
 * later and only once per process. The person's Hide is not kept.
 *
 * Until 2.0.5 the file kept During screen sharing ("mode": "hide", "black_box" or "show"); a file with only that reads
 * as the new setting (Show is off, Private is on) until the next change writes it.
 */
class ShareGuardMemory(context: Context) : ShareGuard.Memory {
    private val prefs = context.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    override fun read(): ShareGuard.Saved? = when {
        prefs.contains(HIDE_LIVE) -> ShareGuard.Saved(prefs.getBoolean(HIDE_LIVE, true))
        prefs.contains(MODE) -> ShareGuard.Saved(hideLive = prefs.getString(MODE, null) != SHOW)
        else -> null
    }

    override fun write(saved: ShareGuard.Saved) {
        // "hidden" was kept by the first v1.8.1 builds, "mode" and "block_screens" until 2.0.5: they go with this change.
        prefs.edit().putBoolean(HIDE_LIVE, saved.hideLive).remove(MODE).remove(OLD_BLOCK).remove(HIDDEN).apply()
    }

    private companion object {
        const val FILE = "screen_share_privacy"
        const val HIDE_LIVE = "hide_live_words"
        const val MODE = "mode"
        const val SHOW = "show"
        const val OLD_BLOCK = "block_screens"
        const val HIDDEN = "hidden"
    }
}
