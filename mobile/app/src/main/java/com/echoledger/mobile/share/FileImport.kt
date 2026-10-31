package com.echoledger.mobile.share

import android.content.Context
import android.media.MediaCodec
import android.media.MediaExtractor
import android.media.MediaFormat
import android.media.MediaMetadataRetriever
import android.media.MediaMuxer
import android.net.Uri
import android.provider.OpenableColumns
import com.echoledger.mobile.AppGraph
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.net.UploadWorker
import java.io.File
import java.io.InputStream
import java.nio.ByteBuffer
import java.time.Instant
import java.util.UUID

/**
 * Files picked in Voice notes & files (since 1.4): WhatsApp voice notes (.opus/.ogg), mp3, m4a, aac, wav, and the
 * sound of mp4/webm videos. Each file becomes its own recording titled from its file name, with the batch's client
 * and language; a file already on this phone or (asked of the server) already in the Library is skipped.
 */
object FileImport {
    data class Picked(val uri: Uri, val name: String, val mime: String, val size: Long?)

    data class Summary(val imported: List<String>, val duplicates: Int, val unreadable: Int) {
        /** "Importing 3 files." · "1 file was already in your Library." · "1 file could not be read." */
        fun message(): String = listOfNotNull(
            imported.size.takeIf { it > 0 }?.let { "Importing ${files(it)}." },
            duplicates.takeIf { it > 0 }?.let { "${files(it)} already in your Library." },
            unreadable.takeIf { it > 0 }?.let { "${files(it)} could not be read." }
        ).joinToString(" ").ifEmpty { "Nothing to import." }

        private fun files(count: Int) = if (count == 1) "1 file" else "$count files"
    }

    /** Name, type and size of a picked file. */
    fun describe(context: Context, uri: Uri): Picked {
        var name: String? = null
        var size: Long? = null
        runCatching {
            context.contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE), null, null, null)?.use { c ->
                if (c.moveToFirst()) {
                    // Providers may leave out a column: look each one up by name.
                    c.getColumnIndex(OpenableColumns.DISPLAY_NAME).takeIf { it >= 0 && !c.isNull(it) }?.let { name = c.getString(it) }
                    c.getColumnIndex(OpenableColumns.SIZE).takeIf { it >= 0 && !c.isNull(it) }?.let { size = c.getLong(it) }
                }
            }
        }
        val type = context.contentResolver.getType(uri)?.let(ShareIntentParser::bareType) ?: "application/octet-stream"
        return Picked(uri, name ?: uri.lastPathSegment ?: "Imported file", type, size)
    }

    /** "WhatsApp Ptt 2026-10-30 at 11.39.12.opus" → "WhatsApp Ptt 2026-10-30 at 11.39.12" (≤ 200 characters). */
    fun titleOf(name: String): String {
        val bare = if ('.' in name.drop(1)) name.substringBeforeLast('.') else name
        return bare.replace('_', ' ').trim().take(200).ifBlank { "Imported file" }
    }

    /** The type the server stores the file as, or null for something that is neither sound nor video. */
    fun uploadMime(mime: String, name: String): String? {
        val ext = name.substringAfterLast('.', "").lowercase()
        return when {
            mime == "application/ogg" || mime == "audio/opus" || ext == "opus" || ext == "ogg" -> "audio/ogg"
            mime == "audio/x-m4a" || mime == "audio/m4a" || ext == "m4a" -> "audio/mp4"
            mime == "audio/mp3" || ext == "mp3" -> "audio/mpeg"
            mime == "audio/x-wav" || mime == "audio/wave" || ext == "wav" -> "audio/wav"
            mime == "audio/x-aac" || ext == "aac" -> "audio/aac"
            mime == "video/mp4" || ext == "mp4" -> "audio/mp4"
            mime == "video/webm" || ext == "webm" -> "audio/webm"
            mime.startsWith("audio/") -> mime
            else -> null
        }
    }

    fun isVideo(picked: Picked): Boolean =
        picked.mime.startsWith("video/") || picked.name.lowercase().let { it.endsWith(".mp4") || it.endsWith(".webm") }

    /** Imports [files] and queues their uploads. Runs on the caller's (background) thread. */
    fun import(context: Context, graph: AppGraph, files: List<Picked>, clientId: String?, language: String?): Summary {
        val imported = mutableListOf<String>()
        var duplicates = 0
        var unreadable = 0
        val known = graph.recordings.all().map { it.sha256 }.filter { it.isNotEmpty() }.toMutableSet()
        files.forEach { file ->
            val id = UUID.randomUUID().toString()
            val stored = runCatching { store(context, graph, file, id, clientId, language) }.getOrNull()
            when {
                stored == null -> unreadable++

                stored.sha256 in known -> {
                    graph.recordings.delete(id)
                    duplicates++
                }

                else -> {
                    known += stored.sha256
                    UploadWorker.enqueue(context, id)
                    imported += id
                }
            }
        }
        return Summary(imported, duplicates, unreadable)
    }

    private fun store(context: Context, graph: AppGraph, file: Picked, id: String, clientId: String?, language: String?): LocalRecording? {
        // A video gives its sound track only (much smaller); one that cannot be split goes up whole.
        val extracted = if (isVideo(file)) File(context.cacheDir, "import-$id.audio") else null
        val extractedMime = extracted?.let { AudioTrack.extract(context, file.uri, it) }
        val mime = extractedMime ?: uploadMime(file.mime, file.name) ?: return null
        val durationMs = durationOf(context, file.uri)
        val now = Instant.now()
        val meta = LocalRecording(
            id = id,
            sourceApp = SourceApp.VOICE_NOTE.wire,
            clientId = clientId,
            startedAt = now.minusMillis(durationMs).toString(),
            endedAt = now.toString(),
            durationMs = durationMs,
            mime = mime,
            codec = "unknown",
            channels = 1,
            sampleRate = 0,
            plainSize = 0,
            sha256 = "",
            state = "queued",
            language = language,
            title = titleOf(file.name),
            skipDuplicate = true
        )
        return try {
            val input: InputStream = if (extractedMime !=
                null
            ) {
                extracted!!.inputStream()
            } else {
                context.contentResolver.openInputStream(file.uri) ?: return null
            }
            graph.recordings.importStream(meta, input)
        } finally {
            extracted?.delete()
        }
    }

    /** The file's length from its metadata, or 0 when unknown (the server measures it then). */
    private fun durationOf(context: Context, uri: Uri): Long = runCatching {
        val retriever = MediaMetadataRetriever()
        try {
            retriever.setDataSource(context, uri)
            retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)?.toLongOrNull() ?: 0L
        } finally {
            retriever.release()
        }
    }.getOrDefault(0L)

    /** Copies a video's sound track, without re-encoding, into its own file (AAC → MP4, Opus/Vorbis → WebM). */
    object AudioTrack {
        fun extract(context: Context, uri: Uri, out: File): String? {
            val extractor = MediaExtractor()
            return try {
                extractor.setDataSource(context, uri, null)
                val track = (0 until extractor.trackCount).firstOrNull {
                    extractor.getTrackFormat(it).getString(MediaFormat.KEY_MIME)?.startsWith("audio/") == true
                } ?: return null
                val format = extractor.getTrackFormat(track)
                val (container, mime) = when (format.getString(MediaFormat.KEY_MIME)) {
                    MediaFormat.MIMETYPE_AUDIO_AAC -> MediaMuxer.OutputFormat.MUXER_OUTPUT_MPEG_4 to "audio/mp4"

                    MediaFormat.MIMETYPE_AUDIO_OPUS, MediaFormat.MIMETYPE_AUDIO_VORBIS ->
                        MediaMuxer.OutputFormat.MUXER_OUTPUT_WEBM to
                            "audio/webm"

                    else -> return null
                }
                extractor.selectTrack(track)
                val muxer = MediaMuxer(out.path, container)
                try {
                    val target = muxer.addTrack(format)
                    muxer.start()
                    val size = if (format.containsKey(MediaFormat.KEY_MAX_INPUT_SIZE)) {
                        format.getInteger(MediaFormat.KEY_MAX_INPUT_SIZE)
                    } else {
                        1 shl
                            20
                    }
                    val buffer = ByteBuffer.allocate(maxOf(size, 64 * 1024))
                    val info = MediaCodec.BufferInfo()
                    while (true) {
                        val read = extractor.readSampleData(buffer, 0)
                        if (read < 0) break
                        val sync = extractor.sampleFlags and MediaExtractor.SAMPLE_FLAG_SYNC != 0
                        info.set(0, read, extractor.sampleTime, if (sync) MediaCodec.BUFFER_FLAG_KEY_FRAME else 0)
                        muxer.writeSampleData(target, buffer, info)
                        extractor.advance()
                    }
                    muxer.stop()
                } finally {
                    muxer.release()
                }
                mime.takeIf { out.length() > 0 }
            } catch (e: Exception) {
                out.delete()
                null
            } finally {
                extractor.release()
            }
        }
    }
}
