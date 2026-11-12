package com.echoledger.mobile.core

/**
 * What a call app's own notification says about its call (since v1.5, pure, unit-tested): ringing in (incoming),
 * ringing out (calling, no timer yet) or answered (its timer runs from the answer). WhatsApp and the phone's dialer use
 * Android's call notification style: an incoming call has call type 1, an answered call shows a timer whose base
 * ([android.app.Notification.when]) is the moment the call was picked up.
 *
 * Only call notifications reach this (the listener ignores every other notification); nothing of them is stored beyond
 * the kind, the package and the times, for a few minutes.
 */
object CallNotices {
    enum class Kind { INCOMING, RINGING_OUT, ANSWERED }

    data class Notice(val pkg: String, val kind: Kind, val atMs: Long, val answeredAtMs: Long? = null)

    /** Dialers whose call notifications stand for a SIM call. */
    val DIALERS = setOf("com.samsung.android.incallui", "com.samsung.android.dialer", "com.google.android.dialer", "com.android.incallui")

    /** `Notification.CALL_TYPE_INCOMING` / `CALL_TYPE_ONGOING` (API 31). */
    private const val CALL_TYPE_INCOMING = 1
    private const val CALL_TYPE_ONGOING = 2

    private val INCOMING_WORDS = listOf("incoming", "آنے والی", "واردة", "entrant", "eingehend", "entrante", "in arrivo", "gelen", "входящ")
    private val RINGING_WORDS = listOf(
        "calling",
        "ringing",
        "dialing",
        "dialling",
        "connecting",
        "کال کی جا رہی",
        "بج رہی",
        "جارٍ الاتصال",
        "يرن",
        "appel en cours",
        "klingelt",
        "llamando",
        "sonando",
        "chiamata in corso",
        "aranıyor",
        "вызов",
        "звонок"
    )

    /** Kept this long: an answer seen just before the recording starts still counts. */
    const val KEEP_MS = 3 * 60_000L

    /**
     * The kind of a call notification, or null when it says nothing useful. [callType] is the notification's
     * `android.callType` extra (0 when absent), [chronometer] its `android.showChronometer`, [whenMs] its `when`.
     */
    fun classify(
        pkg: String,
        isCallCategory: Boolean,
        callType: Int,
        chronometer: Boolean,
        whenMs: Long,
        text: String?,
        nowMs: Long
    ): Notice? {
        if (!isCallCategory && callType == 0) return null
        val words = text.orEmpty().lowercase()
        return when {
            callType == CALL_TYPE_INCOMING || INCOMING_WORDS.any { words.contains(it) } -> Notice(pkg, Kind.INCOMING, nowMs)

            // A running timer: the call was answered at its base.
            chronometer && whenMs in 1..nowMs + 5_000 -> Notice(pkg, Kind.ANSWERED, nowMs, answeredAtMs = whenMs)

            callType == CALL_TYPE_ONGOING || RINGING_WORDS.any { words.contains(it) } -> Notice(pkg, Kind.RINGING_OUT, nowMs)

            else -> null
        }
    }

    private val recent = ArrayDeque<Notice>()

    /** Called for every new notice while a call recording waits for its answer (set and cleared by the recorder). */
    @Volatile var onNotice: ((Notice) -> Unit)? = null

    @Synchronized
    fun record(notice: Notice) {
        recent.addLast(notice)
        while (recent.isNotEmpty() && notice.atMs - recent.first().atMs > KEEP_MS) recent.removeFirst()
        while (recent.size > 40) recent.removeFirst()
        onNotice?.invoke(notice)
    }

    /** The newest notice from [pkg] (a dialer for [sim]) since [sinceMs]. */
    @Synchronized
    fun latest(pkg: String?, sim: Boolean, sinceMs: Long): Notice? = recent.lastOrNull { it.atMs >= sinceMs && matches(it, pkg, sim) }

    /**
     * Apps installed side by side that are easily mistaken for each other at the start of a call (since v1.7.1: a
     * WhatsApp call right after a WhatsApp Business one was taken for Business, so WhatsApp's own "ongoing call" notice,
     * the answer signal, was ignored and the call was dropped as never answered).
     */
    private val TWINS = listOf(setOf("com.whatsapp", "com.whatsapp.w4b"))

    /** The twin of [pkg] that a notice may come from instead, or null. */
    fun twinOf(pkg: String?): Set<String> = TWINS.firstOrNull { pkg in it }.orEmpty()

    /** Whether a notice belongs to the call being recorded (its app or that app's twin, or any dialer for a SIM call). */
    fun matches(notice: Notice, pkg: String?, sim: Boolean): Boolean =
        if (sim) notice.pkg in DIALERS else notice.pkg == pkg || notice.pkg in twinOf(pkg)

    @Synchronized
    internal fun clearForTest() {
        recent.clear()
        onNotice = null
    }
}
