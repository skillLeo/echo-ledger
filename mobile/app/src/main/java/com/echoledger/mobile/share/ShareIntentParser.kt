package com.echoledger.mobile.share

import android.content.Intent
import android.net.Uri
import androidx.core.content.IntentCompat

/**
 * Extracts shared audio from ACTION_SEND / ACTION_SEND_MULTIPLE (Robolectric-tested). WhatsApp voice notes arrive as
 * `audio/ogg` (Opus) or `application/ogg`. Nothing is recorded automatically; sharing is an explicit user action.
 */
object ShareIntentParser {
    data class SharedAudio(val uri: Uri, val mime: String)

    private val ACCEPTED_PREFIXES = listOf("audio/")
    private val ACCEPTED_EXACT = setOf("application/ogg", "application/octet-stream")

    fun isAcceptedMime(mime: String?): Boolean {
        val m = mime?.lowercase() ?: return false
        return ACCEPTED_PREFIXES.any { m.startsWith(it) } || m in ACCEPTED_EXACT
    }

    fun parse(intent: Intent?): List<SharedAudio> {
        if (intent == null) return emptyList()
        val type = intent.type
        if (!isAcceptedMime(type)) return emptyList()
        val uris: List<Uri> = when (intent.action) {
            Intent.ACTION_SEND -> listOfNotNull(IntentCompat.getParcelableExtra(intent, Intent.EXTRA_STREAM, Uri::class.java))
            Intent.ACTION_SEND_MULTIPLE -> IntentCompat.getParcelableArrayListExtra(intent, Intent.EXTRA_STREAM, Uri::class.java).orEmpty()
            else -> emptyList()
        }
        return uris.filter { it.scheme == "content" || it.scheme == "file" }.map { SharedAudio(it, type!!.lowercase()) }
    }
}
