package com.echoledger.mobile.account

import android.content.ClipData
import android.content.ClipDescription
import android.content.ClipboardManager
import android.content.Context
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.PersistableBundle
import android.os.SystemClock
import java.util.UUID

/**
 * A secret on the clipboard (the two-step key, the recovery codes). The copy is marked sensitive on Android 13 and
 * later, so the phone's clipboard preview hides it, and it carries a token of its own. It is cleared [LIFETIME_MS]
 * after it was copied, when the app locks and on sign-out, and only while the clipboard still holds this copy: what the
 * person copied since is never touched.
 *
 * Android 10 and later only let the app in front read the clipboard. When the app is behind another one at that moment
 * the copy can't be checked, so it stays due, and [sweep] clears it as soon as the app is in front again.
 */
object SecretClip {
    const val LIFETIME_MS = 60_000L

    /** The extra on the clip's description with this copy's token. */
    const val TOKEN_EXTRA = "com.echoledger.mobile.secret_clip"

    private class Pending(val token: String, val label: String, val clearAtMs: Long)

    @Volatile private var pending: Pending? = null
    private var handler: Handler? = null
    private val main: Handler
        get() = handler?.takeIf { it.looper == Looper.getMainLooper() } ?: Handler(Looper.getMainLooper()).also { handler = it }
    private val timer = Any()

    /** What the clipboard holds, as far as this app can tell. */
    enum class Holds { Ours, Other, Unknown }

    /** [description] is what the clipboard says it holds (null: empty, or not readable while the app is behind). */
    fun holds(description: ClipDescription?, token: String, label: String): Holds = when {
        description == null -> Holds.Unknown
        description.label?.toString() == label && description.extras?.getString(TOKEN_EXTRA) == token -> Holds.Ours
        else -> Holds.Other
    }

    /** Copies [text] under [label] and clears it after [LIFETIME_MS] if it is still there. */
    fun copy(context: Context, label: String, text: String) {
        val app = context.applicationContext
        val clipboard = app.getSystemService(ClipboardManager::class.java) ?: return
        val token = UUID.randomUUID().toString()
        val clip = ClipData.newPlainText(label, text)
        clip.description.extras = PersistableBundle().apply {
            putString(TOKEN_EXTRA, token)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) putBoolean(ClipDescription.EXTRA_IS_SENSITIVE, true)
        }
        clipboard.setPrimaryClip(clip)
        pending = Pending(token, label, SystemClock.elapsedRealtime() + LIFETIME_MS)
        main.removeCallbacksAndMessages(timer)
        main.postAtTime({ sweep(app) }, timer, SystemClock.uptimeMillis() + LIFETIME_MS)
    }

    /** The app locked or signed out: the copy is due now (cleared at once, or once the app is in front). */
    fun clearNow(context: Context) {
        val p = pending ?: return
        pending = Pending(p.token, p.label, 0L)
        val app = context.applicationContext
        if (Looper.myLooper() == Looper.getMainLooper()) sweep(app) else main.post { sweep(app) }
    }

    /** Clears a copy that is due and still on the clipboard; called by the timer and whenever the app is in front. */
    fun sweep(context: Context) {
        val p = pending ?: return
        if (SystemClock.elapsedRealtime() < p.clearAtMs) return
        val clipboard = context.applicationContext.getSystemService(ClipboardManager::class.java) ?: return
        when (holds(clipboard.primaryClipDescription, p.token, p.label)) {
            Holds.Ours -> {
                clipboard.clearPrimaryClip()
                forget(p)
            }

            Holds.Other -> forget(p)

            Holds.Unknown -> Unit
        }
    }

    /** Whether a copy is still waiting to be cleared (tests). */
    internal val waiting: Boolean get() = pending != null

    private fun forget(p: Pending) {
        if (pending === p) {
            pending = null
            main.removeCallbacksAndMessages(timer)
        }
    }
}
