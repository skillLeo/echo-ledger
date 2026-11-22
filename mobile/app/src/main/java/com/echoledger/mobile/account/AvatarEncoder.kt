package com.echoledger.mobile.account

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.ImageDecoder
import android.graphics.Matrix
import android.media.ExifInterface
import android.os.Build
import java.io.ByteArrayInputStream
import java.io.ByteArrayOutputStream
import java.nio.ByteBuffer

/**
 * Decodes a picture the person picked or took. The platform one reads JPEG, PNG, WebP, HEIC/HEIF and AVIF (where the
 * phone can), turned upright by its orientation; tests give their own (a HEIC picture without a HEIC codec on the JVM).
 */
fun interface PictureDecoder {
    /** The picture upright, at most about [maxSide] px on its longer side; null when it can't be read. */
    fun decode(bytes: ByteArray, maxSide: Int): Bitmap?
}

/** The phone's own decoders: ImageDecoder (every format the phone knows, upright), else BitmapFactory with the EXIF turn. */
object PlatformPictureDecoder : PictureDecoder {
    override fun decode(bytes: ByteArray, maxSide: Int): Bitmap? = viaImageDecoder(bytes, maxSide) ?: viaBitmapFactory(bytes, maxSide)

    private fun viaImageDecoder(bytes: ByteArray, maxSide: Int): Bitmap? = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
        runCatching {
            ImageDecoder.decodeBitmap(ImageDecoder.createSource(ByteBuffer.wrap(bytes))) { decoder, info, _ ->
                decoder.allocator = ImageDecoder.ALLOCATOR_SOFTWARE
                val longer = maxOf(info.size.width, info.size.height)
                if (longer > maxSide) {
                    val scale = maxSide.toFloat() / longer
                    decoder.setTargetSize(
                        (info.size.width * scale).toInt().coerceAtLeast(1),
                        (info.size.height * scale).toInt().coerceAtLeast(1)
                    )
                }
            }
        }.getOrNull()
    } else {
        null
    }

    fun viaBitmapFactory(bytes: ByteArray, maxSide: Int): Bitmap? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null
        var sample = 1
        while (maxOf(bounds.outWidth, bounds.outHeight) / (sample * 2) >= maxSide) sample *= 2
        val bitmap = BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })
            ?: return null
        return AvatarEncoder.upright(bitmap, AvatarEncoder.orientation(bytes))
    }
}

/**
 * The profile picture as it is uploaded (docs/API.md "Profile picture"): decoded on the phone whatever its format
 * (HEIC included), turned upright, cut to a centred square (the server does too, so nothing else is lost), at most
 * [SIDE] px, and written again as a JPEG of at most [MAX_BYTES]. A new JPEG carries no EXIF: no GPS, no camera, no
 * date leaves the phone.
 */
object AvatarEncoder {
    const val MAX_BYTES = 5 * 1024 * 1024
    const val SIDE = 1024
    const val MIN_SIDE = 128

    sealed interface Outcome {
        class Ready(val jpeg: ByteArray, val side: Int) : Outcome
        data object Unreadable : Outcome
        data object TooSmall : Outcome
    }

    fun encode(bytes: ByteArray, decoder: PictureDecoder = PlatformPictureDecoder, maxBytes: Int = MAX_BYTES, side: Int = SIDE): Outcome {
        val decoded = runCatching { decoder.decode(bytes, side * 2) }.getOrNull() ?: return Outcome.Unreadable
        val square = minOf(decoded.width, decoded.height)
        if (square < MIN_SIDE) return Outcome.TooSmall
        val cropped = Bitmap.createBitmap(decoded, (decoded.width - square) / 2, (decoded.height - square) / 2, square, square)
        val target = minOf(square, side)
        val scaled = if (target == square) cropped else Bitmap.createScaledBitmap(cropped, target, target, true)
        var quality = 90
        var current = if (scaled.hasAlpha()) onWhite(scaled) else scaled
        while (true) {
            val out = ByteArrayOutputStream()
            current.compress(Bitmap.CompressFormat.JPEG, quality, out)
            val jpeg = out.toByteArray()
            if (jpeg.size <= maxBytes) return Outcome.Ready(jpeg, current.width)
            when {
                quality > 50 -> quality -= 10

                current.width / 2 >= MIN_SIDE -> {
                    current = Bitmap.createScaledBitmap(current, current.width / 2, current.height / 2, true)
                    quality = 80
                }

                else -> return Outcome.Ready(jpeg, current.width)
            }
        }
    }

    /** A transparent picture (a PNG) on white: a JPEG has no transparency and would show it black. */
    private fun onWhite(bitmap: Bitmap): Bitmap {
        val flat = Bitmap.createBitmap(bitmap.width, bitmap.height, Bitmap.Config.ARGB_8888)
        android.graphics.Canvas(flat).apply {
            drawColor(WHITE)
            drawBitmap(bitmap, 0f, 0f, null)
        }
        return flat
    }

    private const val WHITE = -1

    /** The EXIF orientation of a JPEG (or HEIC) picture; normal when it has none. */
    fun orientation(bytes: ByteArray): Int = runCatching {
        ExifInterface(ByteArrayInputStream(bytes)).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)
    }.getOrDefault(ExifInterface.ORIENTATION_NORMAL)

    /** [bitmap] turned as its EXIF [orientation] says. */
    fun upright(bitmap: Bitmap, orientation: Int): Bitmap {
        val m = Matrix()
        when (orientation) {
            ExifInterface.ORIENTATION_ROTATE_90 -> m.postRotate(90f)

            ExifInterface.ORIENTATION_ROTATE_180 -> m.postRotate(180f)

            ExifInterface.ORIENTATION_ROTATE_270 -> m.postRotate(270f)

            ExifInterface.ORIENTATION_FLIP_HORIZONTAL -> m.postScale(-1f, 1f)

            ExifInterface.ORIENTATION_FLIP_VERTICAL -> m.postScale(1f, -1f)

            ExifInterface.ORIENTATION_TRANSPOSE -> {
                m.postRotate(90f)
                m.postScale(-1f, 1f)
            }

            ExifInterface.ORIENTATION_TRANSVERSE -> {
                m.postRotate(270f)
                m.postScale(-1f, 1f)
            }

            else -> return bitmap
        }
        return Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, m, true)
    }
}
