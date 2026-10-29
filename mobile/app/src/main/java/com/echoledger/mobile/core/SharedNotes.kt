package com.echoledger.mobile.core

import android.content.Context
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.LocalRecording

/**
 * Voice notes shared to EchoLedger from another app (since v1.3): which server recording each shared file became, so
 * the app can open it, follow its upload and transcription, and say when it is ready. The upload deletes the local
 * copy once the server holds it, so the link is kept here (a small private store, entries forgotten after two days).
 */
object SharedNotes {
    private const val FILE = "shared_notes"
    private const val KEEP_MS = 2 * 24 * 60 * 60 * 1000L

    /** After sharing: open the voice note in the app (default), or stay in the app shared from. */
    const val OPEN = "open"
    const val STAY = "stay"

    private fun prefs(context: Context) = context.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    /** Remembers just-imported local recordings as shared ones (no server recording yet). */
    fun remember(context: Context, localIds: List<String>, nowMs: Long = System.currentTimeMillis()) {
        val p = prefs(context)
        val edit = p.edit()
        p.all.forEach { (key, value) ->
            val at = (value as? String)?.substringBefore('|')?.toLongOrNull() ?: 0L
            if (nowMs - at > KEEP_MS) edit.remove(key)
        }
        localIds.forEach { edit.putString(it, "$nowMs|") }
        edit.apply()
    }

    /** The server recording a shared local recording became; ignored for recordings that were not shared. */
    fun linkSession(context: Context, localId: String, sessionId: String) {
        val p = prefs(context)
        val value = p.getString(localId, null) ?: return
        p.edit().putString(localId, value.substringBefore('|') + "|" + sessionId).apply()
    }

    fun sessionFor(context: Context, localId: String): String? =
        prefs(context).getString(localId, null)?.substringAfter('|', "")?.takeIf { it.isNotEmpty() }

    /** Where a shared note is, from its local copy (while uploading), its server recording and that one's transcript. */
    enum class Stage { WAITING, UPLOADING, TRANSCRIBING, READY, FAILED, NO_AUDIO }

    data class Status(val stage: Stage, val text: String, val sessionId: String? = null)

    /** Pure: the stage of one shared note. */
    fun status(local: LocalRecording?, sessionId: String?, item: LibraryItem?): Status {
        val transcript = item?.transcript
        return when {
            item != null && transcript?.finalErrorCode == "no_audio" -> Status(Stage.NO_AUDIO, "No audio in this voice note", item.id)
            item != null && transcript?.finalStatus == "completed" -> Status(Stage.READY, "Transcript ready", item.id)
            item != null && transcript?.finalStatus == "failed" -> Status(Stage.FAILED, "Transcription failed. Open it to retry.", item.id)
            item != null -> Status(Stage.TRANSCRIBING, TranscriptText.transcribing(transcript?.finalProgress), item.id)
            local?.state == "failed" -> Status(Stage.FAILED, "Upload failed: ${local.lastError ?: "try again"}", sessionId)
            local != null -> Status(Stage.UPLOADING, if (local.state == "uploading") "Uploading…" else "Waiting to upload…", sessionId)
            sessionId != null -> Status(Stage.TRANSCRIBING, "Transcribing…", sessionId)
            else -> Status(Stage.WAITING, "Preparing…")
        }
    }

    /** Whether a note needs no more watching. */
    fun done(stage: Stage): Boolean = stage == Stage.READY || stage == Stage.FAILED || stage == Stage.NO_AUDIO
}
