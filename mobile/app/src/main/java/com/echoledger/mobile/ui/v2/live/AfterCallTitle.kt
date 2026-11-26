package com.echoledger.mobile.ui.v2.live

import android.content.res.Resources
import androidx.core.text.BidiFormatter
import androidx.core.text.TextDirectionHeuristicsCompat
import com.echoledger.mobile.R
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.ui.v2.shell.sourceName
import java.util.Locale

/** "Call with Omar Khan", else the number, else the source ("WhatsApp"), in the screen's language. */
fun afterCallTitle(res: Resources, rec: LocalRecording): String = rec.title?.takeIf { it.isNotBlank() }
    ?: rec.callerName?.takeIf { it.isNotBlank() }?.let { res.getString(R.string.v2_live_call_with, it) }
    ?: rec.callerNumber?.takeIf { it.any(Char::isDigit) }?.let { res.getString(R.string.v2_live_call_with, leftToRight(res, it)) }
    ?: sourceName(res, rec.sourceApp)

/** A phone number kept left to right inside a sentence in any language ("+92 300 …" in Urdu). */
private fun leftToRight(res: Resources, number: String): String =
    BidiFormatter.getInstance(res.configuration.locales[0] ?: Locale.ROOT).unicodeWrap(number, TextDirectionHeuristicsCompat.LTR)
