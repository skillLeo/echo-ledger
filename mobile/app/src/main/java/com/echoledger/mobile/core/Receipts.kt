package com.echoledger.mobile.core

import android.content.ContentResolver
import android.graphics.Bitmap
import android.graphics.ImageDecoder
import android.net.Uri
import android.provider.OpenableColumns
import java.io.ByteArrayOutputStream

/**
 * Payment receipts (since v1.7, docs/API.md "Payment receipts"): what the phone sends for an upgrade request. A
 * picture is redrawn as a JPEG of at most [MAX_SIDE] px on its long side, so no location or camera data leaves the
 * phone and a large photo still fits; a PDF goes as it is. The server checks and re-encodes again.
 */
object Receipts {
    const val MAX_BYTES = 5L * 1024 * 1024
    const val MAX_SIDE = 2560
    const val PER_ROUND = 3

    data class Prepared(val name: String, val mime: String, val bytes: ByteArray)

    fun prepare(resolver: ContentResolver, uri: Uri): Prepared {
        val type = resolver.getType(uri).orEmpty()
        val original = displayName(resolver, uri) ?: "receipt"
        if (type == "application/pdf" || original.endsWith(".pdf", ignoreCase = true)) {
            val bytes = resolver.openInputStream(uri)?.use { it.readBytes() } ?: error("unreadable")
            require(bytes.size >= 5 && String(bytes, 0, 5, Charsets.US_ASCII) == "%PDF-") { "not a PDF" }
            return Prepared(baseName(original) + ".pdf", "application/pdf", bytes)
        }
        val bitmap = ImageDecoder.decodeBitmap(ImageDecoder.createSource(resolver, uri)) { decoder, info, _ ->
            val (w, h) = scaled(info.size.width, info.size.height)
            decoder.setTargetSize(w, h)
            decoder.allocator = ImageDecoder.ALLOCATOR_SOFTWARE
        }
        return Prepared(baseName(original) + ".jpg", "image/jpeg", jpeg(bitmap))
    }

    /** The size a picture is drawn at: its own when it fits, else the long side brought down to [MAX_SIDE]. */
    fun scaled(width: Int, height: Int): Pair<Int, Int> {
        val long = maxOf(width, height)
        if (long <= MAX_SIDE || long <= 0) return width to height
        val f = MAX_SIDE.toDouble() / long
        return maxOf(1, Math.round(width * f).toInt()) to maxOf(1, Math.round(height * f).toInt())
    }

    /** "Screenshot_2026-11-09.png" → "Screenshot_2026-11-09"; nothing usable → "receipt". */
    fun baseName(name: String): String =
        name.substringBeforeLast('.').replace(Regex("[^A-Za-z0-9 _.-]"), "").trim().take(60).ifEmpty { "receipt" }

    private fun jpeg(bitmap: Bitmap): ByteArray {
        var quality = 85
        while (true) {
            val out = ByteArrayOutputStream()
            bitmap.compress(Bitmap.CompressFormat.JPEG, quality, out)
            if (out.size() <= MAX_BYTES || quality <= 50) return out.toByteArray()
            quality -= 10
        }
    }

    private fun displayName(resolver: ContentResolver, uri: Uri): String? = runCatching {
        resolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { c ->
            if (c.moveToFirst()) c.getString(0) else null
        }
    }.getOrNull()
}
