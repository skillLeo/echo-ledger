package com.echoledger.mobile.share

import android.content.ClipData
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri

/**
 * Send to WhatsApp (since v1.6, P5.5): the transcript as text, the text with the voice, or a link. WhatsApp, else
 * WhatsApp Business, else the system share sheet. WhatsApp may drop the text from a voice it receives, so with the
 * voice the text is also put on the clipboard ("Text copied — paste it").
 */
object WhatsAppShare {
    const val WHATSAPP = "com.whatsapp"
    const val BUSINESS = "com.whatsapp.w4b"
    const val TEXT_COPIED = "Text copied — paste it in WhatsApp if it is not there."

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

    fun voiceIntent(audio: Uri, text: String, pkg: String?): Intent = Intent(Intent.ACTION_SEND)
        .setType("audio/ogg")
        .putExtra(Intent.EXTRA_STREAM, audio)
        .putExtra(Intent.EXTRA_TEXT, clip(text))
        .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        .apply {
            clipData = ClipData.newRawUri("voice", audio)
            pkg?.let { setPackage(it) }
        }

    /** Starts [intent]; without the app (or if it refuses), the system share sheet. */
    fun start(context: Context, intent: Intent) {
        val started = runCatching { context.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess
        if (!started) {
            context.startActivity(Intent.createChooser(intent.setPackage(null), "Send").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        }
    }
}
