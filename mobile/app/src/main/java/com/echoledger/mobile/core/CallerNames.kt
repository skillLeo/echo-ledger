package com.echoledger.mobile.core

/**
 * Caller names (since v1.2): the name WhatsApp shows in its call notification ("Omar Khan · Ongoing voice call"),
 * kept for a few minutes so a call recording can say who it was with. Read by [com.echoledger.mobile.notify.CallerNameListener]
 * when the user allows notification access; nothing is stored on disk or sent anywhere except with that recording.
 */
object CallerNames {
    const val WHATSAPP = "com.whatsapp"
    const val WHATSAPP_BUSINESS = "com.whatsapp.w4b"
    val PACKAGES = setOf(WHATSAPP, WHATSAPP_BUSINESS)

    /** Names seen this long before a recording starts still count (the notification comes as the call rings). */
    const val BEFORE_MS = 2 * 60_000L

    /** Kept at most this long. */
    private const val KEEP_MS = 6 * 60 * 60_000L

    data class Seen(val pkg: String, val name: String, val atMs: Long)

    private val seen = ArrayDeque<Seen>()

    /** Words of a WhatsApp call notification, in the languages the phones here use. */
    private val CALL_WORDS = listOf(
        "call", "calling", "ringing", "کال", "مكالمة", "اتصال",
        "appel", "anruf", "llamada", "chiamata", "chamada", "oproep", "arama", "звонок"
    )

    /**
     * The person a WhatsApp notification is about when it is a call notification (its category is CALL, or its text
     * speaks of a call); null for a message or a placeholder title.
     */
    fun callerOf(pkg: String, category: String?, title: String?, text: String?): String? {
        if (pkg !in PACKAGES) return null
        val isCall = category == "call" || CALL_WORDS.any { text.orEmpty().contains(it, ignoreCase = true) }
        if (!isCall) return null
        val name = title?.replace(Regex("\\p{C}+"), "")?.trim()?.take(120).orEmpty()
        if (name.isEmpty() || name.equals("WhatsApp", ignoreCase = true) || name.equals("WhatsApp Business", ignoreCase = true)) return null
        return name
    }

    @Synchronized
    fun record(pkg: String, name: String, atMs: Long) {
        seen.addLast(Seen(pkg, name, atMs))
        while (seen.isNotEmpty() && (seen.first().atMs < atMs - KEEP_MS || seen.size > 50)) seen.removeFirst()
    }

    /**
     * Who a call recording of [sourceApp] (whatsapp or whatsapp_business) between [startMs] and [endMs] was with: the
     * name seen most often in that window (the last one on a tie), or null.
     */
    @Synchronized
    fun forCall(sourceApp: String?, startMs: Long, endMs: Long): String? {
        val pkg = when (sourceApp) {
            "whatsapp" -> WHATSAPP
            "whatsapp_business" -> WHATSAPP_BUSINESS
            else -> return null
        }
        val inWindow = seen.filter { it.pkg == pkg && it.atMs in (startMs - BEFORE_MS)..endMs }
        if (inWindow.isEmpty()) return null
        val counts = inWindow.groupingBy { it.name }.eachCount()
        val best = counts.values.max()
        return inWindow.last { counts.getValue(it.name) == best }.name
    }

    @Synchronized
    fun clear() = seen.clear()
}
