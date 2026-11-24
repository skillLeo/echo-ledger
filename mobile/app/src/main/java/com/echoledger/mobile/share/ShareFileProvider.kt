package com.echoledger.mobile.share

import android.net.Uri
import androidx.core.content.FileProvider

/**
 * The app's FileProvider (exports and Send to WhatsApp's voice), giving a voice file its real type: Android's own
 * table calls an .m4a "audio/mpeg" (MP3), and the app that receives it reads the type it is told.
 */
class ShareFileProvider : FileProvider() {
    override fun getType(uri: Uri): String? = WhatsAppShare.voiceType(uri.lastPathSegment) ?: super.getType(uri)
}
