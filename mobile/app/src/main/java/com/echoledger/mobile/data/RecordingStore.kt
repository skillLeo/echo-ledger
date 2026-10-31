package com.echoledger.mobile.data

import android.content.Context
import java.io.File
import java.io.InputStream
import java.security.MessageDigest
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.serialization.Serializable

/** Local metadata for an encrypted recording kept in app-private, no-backup storage. */
@Serializable
data class LocalRecording(
    val id: String,
    val sourceApp: String,
    val clientId: String? = null,
    val startedAt: String,
    val endedAt: String,
    val durationMs: Long,
    val mime: String,
    val codec: String,
    val channels: Int,
    val sampleRate: Int,
    val plainSize: Long,
    val sha256: String,
    /** "pending_decision" (call, awaiting Keep/Discard) | "queued" | "uploading" | "uploaded" | "failed" */
    val state: String,
    val lastError: String? = null,
    val liveSegments: List<SegmentDto> = emptyList(),
    val liveProvider: String? = null,
    /** The open server session captions used (v1.1); Keep finishes it, Discard deletes it. */
    val serverSessionId: String? = null,
    /** Bookmarks (time order) and pause gaps in recorded time, sent with the create or finish call. */
    val markers: List<MarkerDto> = emptyList(),
    val pauses: List<PauseDto> = emptyList(),
    /** The transcription language chosen when the recording ended (null = automatic). */
    val language: String? = null,
    /** The language chip changes during the recording (since v1.2). */
    val languageMarks: List<LanguageMarkDto> = emptyList(),
    /** Who the call was with, from WhatsApp's call notification or the phone's call log (since v1.2). */
    val callerName: String? = null,
    val callerSource: String? = null,
    /** A SIM phone call's number from the call log (since v1.2). */
    val callerNumber: String? = null,
    /** Why the recording may be one-sided, in the helper's words (since v1.3); shown on the after-call card and the recording. */
    val captureWarning: String? = null,
    /** A title typed before Keep (since v1.3); sent with the recording, else the server names it. */
    val title: String? = null,
    /** An imported file (since 1.4): the server skips it when the same file is already in the Library. */
    val skipDuplicate: Boolean = false
)

/**
 * Encrypted recordings live in `noBackupFilesDir/recordings/<id>.elr` (Tink streaming AEAD) with a JSON metadata file.
 * Never a shared folder. Plaintext only exists transiently in the app's private cache while encoding.
 */
class RecordingStore(context: Context, private val cipher: RecordingCipher) {
    private val dir = File(context.noBackupFilesDir, "recordings").apply { mkdirs() }

    private val _changes = MutableStateFlow(0L)

    /** Counts every save and delete, so a screen listing the recordings reloads the moment one changes (A6.M12). */
    val changes: StateFlow<Long> = _changes.asStateFlow()

    fun encryptedFile(id: String) = File(dir, "$id.elr")

    private fun metaFile(id: String) = File(dir, "$id.json")

    /** Encrypts [plain] into storage, computing size + SHA-256 of the plaintext on the way. Deletes [plain] afterwards. */
    fun importPlainFile(meta: LocalRecording, plain: File): LocalRecording {
        val digest = MessageDigest.getInstance("SHA-256")
        var size = 0L
        encryptOrClean(meta.id) {
            plain.inputStream().use { input ->
                cipher.encrypting(encryptedFile(meta.id).outputStream(), meta.id).use { out ->
                    val buf = ByteArray(64 * 1024)
                    while (true) {
                        val n = input.read(buf)
                        if (n < 0) break
                        digest.update(buf, 0, n)
                        out.write(buf, 0, n)
                        size += n
                    }
                }
            }
        }
        plain.delete()
        val stored = meta.copy(plainSize = size, sha256 = digest.digest().joinToString("") { "%02x".format(it) })
        save(stored)
        return stored
    }

    /** Imports an external stream (shared voice note) directly into encrypted storage. */
    fun importStream(meta: LocalRecording, input: InputStream): LocalRecording {
        val digest = MessageDigest.getInstance("SHA-256")
        var size = 0L
        encryptOrClean(meta.id) {
            input.use { src ->
                cipher.encrypting(encryptedFile(meta.id).outputStream(), meta.id).use { out ->
                    val buf = ByteArray(64 * 1024)
                    while (true) {
                        val n = src.read(buf)
                        if (n < 0) break
                        digest.update(buf, 0, n)
                        out.write(buf, 0, n)
                        size += n
                    }
                }
            }
        }
        val stored = meta.copy(plainSize = size, sha256 = digest.digest().joinToString("") { "%02x".format(it) })
        save(stored)
        return stored
    }

    /** Runs [write]; when it fails, the half-written encrypted file is removed so no orphan is left behind. */
    private inline fun encryptOrClean(id: String, write: () -> Unit) {
        try {
            write()
        } catch (e: Throwable) {
            encryptedFile(id).delete()
            throw e
        }
    }

    /** Reads [length] plaintext bytes at [offset] through Tink's seekable decrypting channel (no plaintext on disk). */
    fun readSlice(id: String, offset: Long, length: Int): ByteArray {
        val channel = java.nio.channels.FileChannel.open(encryptedFile(id).toPath(), java.nio.file.StandardOpenOption.READ)
        cipher.seekableDecrypting(channel, id).use { plain ->
            plain.position(offset)
            val buffer = java.nio.ByteBuffer.allocate(length)
            while (buffer.hasRemaining()) {
                if (plain.read(buffer) < 0) break
            }
            return buffer.array().copyOf(buffer.position())
        }
    }

    fun save(meta: LocalRecording) {
        val tmp = File(dir, "${meta.id}.json.tmp")
        tmp.writeText(ApiJson.encodeToString(LocalRecording.serializer(), meta))
        tmp.renameTo(metaFile(meta.id))
        announceChange()
    }

    fun get(id: String): LocalRecording? = metaFile(id).takeIf {
        it.exists()
    }?.let { runCatching { ApiJson.decodeFromString(LocalRecording.serializer(), it.readText()) }.getOrNull() }

    fun all(): List<LocalRecording> = dir.listFiles { f -> f.extension == "json" }.orEmpty()
        .mapNotNull { runCatching { ApiJson.decodeFromString(LocalRecording.serializer(), it.readText()) }.getOrNull() }
        .sortedByDescending { it.startedAt }

    fun pendingDecisions(): List<LocalRecording> = all().filter { it.state == "pending_decision" }

    fun update(id: String, transform: (LocalRecording) -> LocalRecording): LocalRecording? = get(id)?.let(transform)?.also(::save)

    fun delete(id: String) {
        encryptedFile(id).delete()
        metaFile(id).delete()
        announceChange()
    }

    /** Wakes whoever watches [changes]. */
    internal fun announceChange() {
        _changes.update { it + 1 }
    }
}
