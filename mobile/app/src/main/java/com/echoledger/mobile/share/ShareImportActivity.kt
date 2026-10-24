package com.echoledger.mobile.share

import android.os.Bundle
import android.provider.OpenableColumns
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.lifecycle.lifecycleScope
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.net.UploadWorker
import java.time.Instant
import java.util.UUID
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** Receives voice notes from the Android share sheet, encrypts them locally and queues them for transcription. */
class ShareImportActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
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
            val count = withContext(Dispatchers.IO) {
                shared.count { audio ->
                    runCatching {
                        val id = UUID.randomUUID().toString()
                        val now = Instant.now().toString()
                        val input = contentResolver.openInputStream(audio.uri) ?: return@runCatching false
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
                        UploadWorker.enqueue(this@ShareImportActivity, id)
                        true
                    }.getOrDefault(false)
                }
            }
            Toast.makeText(
                this@ShareImportActivity,
                if (count >
                    0
                ) {
                    "Sent $count voice note(s) for transcription"
                } else {
                    "Could not read the shared audio"
                },
                Toast.LENGTH_SHORT
            ).show()
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
