package com.echoledger.mobile.share

import android.content.ActivityNotFoundException
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import androidx.core.content.FileProvider
import com.echoledger.mobile.R
import java.io.File
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.withContext

/**
 * Send to WhatsApp (since v1.6, P5.5): the transcript as text, the text with the voice, or a link. WhatsApp, else
 * WhatsApp Business, else the system share sheet. WhatsApp may drop the text from a voice it receives, so with the
 * voice the text is also put on the clipboard.
 *
 * Text and voice sends one message: the voice attached (a copy in the app's cache, [SHARE_DIR], one folder per share
 * so the file keeps its name) with the text. A copy stays while WhatsApp may still read it: the next share clears the
 * ones older than [BUSY_MS], app start the ones older than [KEEP_MS]. Both designs say the same words
 * (strings_v2_recording.xml, `v2_rec_wa_*`).
 */
object WhatsAppShare {
    const val WHATSAPP = "com.whatsapp"
    const val BUSINESS = "com.whatsapp.w4b"

    /** The cache folder the voice copies are shared from (res/xml/export_paths.xml). */
    const val SHARE_DIR = "whatsapp"

    /** How long a voice copy is kept for WhatsApp to read it (app start clears older ones). */
    const val KEEP_MS = 60 * 60 * 1000L

    /** A copy this young may still be being read: the next share leaves it. */
    const val BUSY_MS = 60 * 1000L

    /** WhatsApp, else WhatsApp Business, else null (the system share sheet). */
    fun target(installed: (String) -> Boolean): String? = listOf(WHATSAPP, BUSINESS).firstOrNull(installed)

    fun installed(context: Context): (String) -> Boolean = { pkg ->
        runCatching { context.packageManager.getPackageInfo(pkg, 0) }.isSuccess ||
            runCatching { context.packageManager.getApplicationInfo(pkg, PackageManager.GET_META_DATA) }.isSuccess
    }

    /** WhatsApp shows at most this many characters comfortably; longer text is cut with a note. */
    const val MAX_TEXT = 60_000

    fun clip(text: String): String = if (text.length <=
        MAX_TEXT
    ) {
        text
    } else {
        text.take(MAX_TEXT) + "\n… (cut: the whole transcript is in EchoLedger)"
    }

    fun textIntent(text: String, pkg: String?): Intent = Intent(Intent.ACTION_SEND)
        .setType("text/plain")
        .putExtra(Intent.EXTRA_TEXT, clip(text))
        .apply { pkg?.let { setPackage(it) } }

    /**
     * The voice (a content:// address WhatsApp may read) with the text, as one message. The file's own type (audio/mp4,
     * audio/ogg…) comes from the provider ([ShareFileProvider]), by its extension ([voiceExtension]).
     */
    fun voiceIntent(audio: Uri, text: String, pkg: String?): Intent = Intent(Intent.ACTION_SEND)
        .setType("audio/*")
        .putExtra(Intent.EXTRA_STREAM, audio)
        .putExtra(Intent.EXTRA_TEXT, clip(text))
        .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        .apply {
            clipData = ClipData.newRawUri("voice", audio)
            pkg?.let { setPackage(it) }
        }

    /**
     * The voice file's extension, from the download's Content-Type; else the one the recording's address names; else
     * m4a (what the phone records).
     */
    fun voiceExtension(contentType: String?, known: String? = null): String {
        val type = contentType?.substringBefore(';')?.trim()?.lowercase()
        return when (type) {
            "audio/mp4", "audio/m4a", "audio/x-m4a", "audio/aac", "audio/mp4a-latm" -> "m4a"
            "audio/ogg", "audio/opus", "application/ogg" -> "ogg"
            "audio/mpeg", "audio/mp3" -> "mp3"
            "audio/wav", "audio/x-wav", "audio/wave", "audio/vnd.wave" -> "wav"
            else -> known?.lowercase()?.takeIf { it in setOf("m4a", "ogg", "opus", "mp3", "wav", "aac") } ?: "m4a"
        }
    }

    /** The audio type of a voice file by its extension (m4a: audio/mp4), or null for any other file. */
    fun voiceType(name: String?): String? = when (name?.substringAfterLast('.', "")?.lowercase()) {
        "m4a" -> "audio/mp4"
        "ogg", "opus" -> "audio/ogg"
        "mp3" -> "audio/mpeg"
        "wav" -> "audio/wav"
        "aac" -> "audio/aac"
        else -> null
    }

    /**
     * Starts [intent] in its app; without one (or if it refuses), the system share sheet. False when neither could
     * open (nothing was sent).
     */
    fun start(context: Context, intent: Intent): Boolean {
        fun open(i: Intent) = try {
            context.startActivity(i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            true
        } catch (e: ActivityNotFoundException) {
            false
        } catch (e: SecurityException) {
            false
        }
        if (intent.`package` != null && open(intent)) return true
        return open(Intent.createChooser(intent.setPackage(null), context.getString(R.string.v2_rec_wa_chooser)))
    }

    /** [start], saying so when nothing could open. */
    fun send(context: Context, intent: Intent, say: (String) -> Unit): Boolean =
        start(context, intent).also { if (!it) say(context.getString(R.string.v2_rec_wa_not_opened)) }

    /**
     * One choice's work, as both designs run it: "Not sent" when the sheet is closed before it is done (then it is
     * cancelled), a message when it fails. True when [block] ran to the end.
     */
    suspend fun attempt(context: Context, say: (String) -> Unit, block: suspend () -> Unit): Boolean = try {
        block()
        true
    } catch (e: CancellationException) {
        say(context.getString(R.string.v2_rec_wa_closed))
        throw e
    } catch (e: Exception) {
        say(context.getString(R.string.v2_rec_wa_failed))
        false
    }

    fun shareDir(context: Context): File = File(context.cacheDir, SHARE_DIR)

    /** Deletes what [dir] holds (copies and their folders) last written [maxAgeMs] or more ago; nothing else. */
    fun clearCopies(dir: File, maxAgeMs: Long, now: Long = System.currentTimeMillis()): Int =
        dir.listFiles().orEmpty().count { now - it.lastModified() >= maxAgeMs && it.deleteRecursively() }

    /** At app start: the voice copies an hour old or more. */
    fun clearOldCopies(context: Context): Int = clearCopies(shareDir(context), KEEP_MS)

    /**
     * Text and voice: clears the copies WhatsApp is done with, has [save] write the voice into a new folder of the share
     * folder, and sends one message with the voice attached and the text (also put on the clipboard). [say] gets the
     * outcome in words, except when [save] returns null: it said why. Nothing is sent unless the voice was saved, and
     * nothing if the share is closed (cancelled) while it saves.
     */
    suspend fun sendTextAndVoice(
        context: Context,
        text: String,
        pkg: String?,
        save: suspend (File) -> File?,
        say: (String) -> Unit
    ): Boolean {
        val folder = withContext(Dispatchers.IO) {
            val dir = shareDir(context).apply { mkdirs() }
            clearCopies(dir, BUSY_MS)
            File(dir, System.currentTimeMillis().toString(36)).apply { mkdirs() }
        }
        val audio = try {
            save(folder)
        } catch (e: CancellationException) {
            // Closed while saving: no copy is left and nothing is sent (the sheet says "Not sent", [say] can't).
            withContext(NonCancellable + Dispatchers.IO) { folder.deleteRecursively() }
            throw e
        }
        val uri = withContext(Dispatchers.IO) {
            runCatching {
                check(audio != null && audio.isFile && audio.length() > 0)
                FileProvider.getUriForFile(context, "${context.packageName}.exports", audio)
            }.getOrNull().also { if (it == null) folder.deleteRecursively() }
        }
        if (audio == null) return false
        if (uri == null) {
            say(context.getString(R.string.v2_rec_wa_voice_failed))
            return false
        }
        context.getSystemService(ClipboardManager::class.java)?.setPrimaryClip(ClipData.newPlainText("Transcript", clip(text)))
        if (!send(context, voiceIntent(uri, text, pkg), say)) return false
        say(context.getString(R.string.v2_rec_wa_text_copied))
        return true
    }
}
