package com.echoledger.mobile.share

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import androidx.core.content.FileProvider
import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/**
 * Send to WhatsApp (since v1.6, P5.5): the transcript as text, the text with the voice, or a link. WhatsApp, else
 * WhatsApp Business, else the system share sheet. WhatsApp may drop the text from a voice it receives, so with the
 * voice the text is also put on the clipboard ("Text copied — paste it").
 *
 * Text and voice sends one message: the voice attached (a copy in the app's cache, [SHARE_DIR]) with the text. The
 * copy stays until the next share or an hour after it (WhatsApp reads it after the chat is picked), never longer.
 */
object WhatsAppShare {
    const val WHATSAPP = "com.whatsapp"
    const val BUSINESS = "com.whatsapp.w4b"
    const val TEXT_COPIED = "Text copied — paste it in WhatsApp if it is not there."
    const val SAVING_VOICE = "Saving the voice…"
    const val VOICE_NOT_SAVED = "The voice could not be saved, so nothing was sent. Try again."

    /** The cache folder the voice copies are shared from (res/xml/export_paths.xml). */
    const val SHARE_DIR = "whatsapp"

    /** How long a voice copy is kept for WhatsApp to read it. */
    const val KEEP_MS = 60 * 60 * 1000L

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

    /** The voice (a content:// address WhatsApp may read) with the text, as one message. */
    fun voiceIntent(audio: Uri, text: String, pkg: String?): Intent = Intent(Intent.ACTION_SEND)
        .setType("audio/*")
        .putExtra(Intent.EXTRA_STREAM, audio)
        .putExtra(Intent.EXTRA_TEXT, clip(text))
        .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        .apply {
            clipData = ClipData.newRawUri("voice", audio)
            pkg?.let { setPackage(it) }
        }

    /** Starts [intent] in its app; without one (or if it refuses), the system share sheet. */
    fun start(context: Context, intent: Intent) {
        val started = intent.`package` != null &&
            runCatching { context.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess
        if (!started) {
            context.startActivity(Intent.createChooser(intent.setPackage(null), "Send").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        }
    }

    fun shareDir(context: Context): File = File(context.cacheDir, SHARE_DIR)

    /** Deletes the files in [dir] last written [maxAgeMs] or more ago (all of them with 0); nothing else. */
    fun clearCopies(dir: File, maxAgeMs: Long, now: Long = System.currentTimeMillis()): Int =
        dir.listFiles().orEmpty().count { it.isFile && now - it.lastModified() >= maxAgeMs && it.delete() }

    /** At app start: the voice copies older than an hour. */
    fun clearOldCopies(context: Context): Int = clearCopies(shareDir(context), KEEP_MS)

    /**
     * Text and voice: clears the copies of earlier shares, has [save] write the voice into the share folder, and sends
     * one message with the voice attached and the text (also put on the clipboard). False, and nothing sent, when the
     * voice could not be saved: [save] says why when it returns null, [notSaved] otherwise
     * (classic says [VOICE_NOT_SAVED]).
     */
    suspend fun sendTextAndVoice(
        context: Context,
        text: String,
        pkg: String?,
        save: suspend (File) -> File?,
        notSaved: () -> Unit
    ): Boolean {
        val dir = shareDir(context)
        withContext(Dispatchers.IO) {
            dir.mkdirs()
            clearCopies(dir, 0)
        }
        val audio = save(dir) ?: return false
        val uri = runCatching {
            check(audio.isFile && audio.length() > 0)
            FileProvider.getUriForFile(context, "${context.packageName}.exports", audio)
        }.getOrNull()
        if (uri == null) {
            audio.delete()
            notSaved()
            return false
        }
        context.getSystemService(ClipboardManager::class.java)?.setPrimaryClip(ClipData.newPlainText("Transcript", clip(text)))
        start(context, voiceIntent(uri, text, pkg))
        return true
    }
}
