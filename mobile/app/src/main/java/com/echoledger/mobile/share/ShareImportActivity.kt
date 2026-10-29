package com.echoledger.mobile.share

import android.content.Intent
import android.os.Bundle
import android.provider.OpenableColumns
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.lifecycle.lifecycleScope
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.core.SharedNotes
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.net.UploadWorker
import com.echoledger.mobile.ui.applySecureScreen
import java.time.Instant
import java.util.UUID
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Receives voice notes from the Android share sheet, encrypts them locally and queues them for transcription.
 * It never shows recording titles or transcripts (only a count), so it needs no unlock; while the app lock hides
 * content its window is still kept out of Recents and screenshots.
 *
 * Then (since v1.3, Settings → Voice notes & files): the app opens on the shared notes (their status, then the
 * transcript; the app lock asks first, Back returns here), or it stays in the app shared from and a quiet
 * notification says when each transcript is ready ([SharedNotesWorker]).
 */
class ShareImportActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        lifecycleScope.launch {
            (application as EchoLedgerApp).graph.settings.settings.collect { settings ->
                window.applySecureScreen(settings.appLockEnabled && settings.secureScreen)
            }
        }
        val shared = ShareIntentParser.parse(intent)
        if (shared.isEmpty()) {
            Toast.makeText(this, "No audio found to transcribe", Toast.LENGTH_SHORT).show()
            finish()
            return
        }
        val graph = (application as EchoLedgerApp).graph
        lifecycleScope.launch {
            val settings = graph.settings.current()
            if (!settings.paired) {
                Toast.makeText(this@ShareImportActivity, "Open EchoLedger and pair this phone first", Toast.LENGTH_LONG).show()
                finish()
                return@launch
            }
            val ids = withContext(Dispatchers.IO) {
                shared.mapNotNull { audio ->
                    runCatching {
                        val id = UUID.randomUUID().toString()
                        val now = Instant.now().toString()
                        val input = contentResolver.openInputStream(audio.uri) ?: return@runCatching null
                        graph.recordings.importStream(
                            LocalRecording(
                                id = id,
                                sourceApp = SourceApp.VOICE_NOTE.wire,
                                clientId = settings.clientId,
                                startedAt = now,
                                endedAt = now,
                                durationMs = 0,
                                mime = normalizeMime(audio.mime, displayName(audio.uri)),
                                codec = "unknown",
                                channels = 1,
                                sampleRate = 0,
                                plainSize = 0,
                                sha256 = "",
                                state = "queued"
                            ),
                            input
                        )
                        // Remembered before the upload starts, so the upload can link it to its server recording.
                        SharedNotes.remember(this@ShareImportActivity, listOf(id))
                        UploadWorker.enqueue(this@ShareImportActivity, id)
                        id
                    }.getOrNull()
                }
            }
            when {
                ids.isEmpty() -> Toast.makeText(this@ShareImportActivity, "Could not read the shared audio", Toast.LENGTH_SHORT).show()

                settings.afterShare == SharedNotes.STAY -> {
                    SharedNotesWorker.enqueue(this@ShareImportActivity, ids)
                    val notes = if (ids.size == 1) "voice note" else "${ids.size} voice notes"
                    Toast.makeText(
                        this@ShareImportActivity,
                        "Sent the $notes for transcription. You'll be told when it is ready.",
                        Toast.LENGTH_SHORT
                    ).show()
                }

                // The app opens on the shared notes (after the app lock, when it is on); Back comes back here.
                else -> startActivity(
                    Intent(this@ShareImportActivity, MainActivity::class.java)
                        .putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_SHARED_PREFIX + ids.joinToString(","))
                )
            }
            finish()
        }
    }

    private fun displayName(uri: android.net.Uri): String? = runCatching {
        contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { c ->
            if (c.moveToFirst()) c.getString(0) else null
        }
    }.getOrNull()

    /** WhatsApp voice notes are Opus-in-Ogg (.opus); the server accepts audio/ogg. */
    private fun normalizeMime(mime: String, name: String?): String = when {
        mime == "application/ogg" || mime == "audio/opus" || name?.endsWith(".opus", true) == true -> "audio/ogg"
        mime == "audio/x-m4a" || mime == "audio/m4a" -> "audio/mp4"
        mime == "audio/mp3" -> "audio/mpeg"
        else -> mime
    }
}
