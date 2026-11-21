package com.echoledger.mobile.account

import android.app.Application
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.media.ExifInterface
import java.io.ByteArrayOutputStream
import java.nio.file.Files
import kotlin.random.Random
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * The profile picture made on the phone: any format the phone reads (HEIC through the decoder), cut square, at most
 * 1024 px, at most 5 MB (or the cap given), and a new JPEG with none of the original's EXIF (no GPS, no camera).
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], application = Application::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
class AvatarEncoderTest {
    private fun noise(width: Int, height: Int, seed: Int = 1): Bitmap {
        val r = Random(seed)
        val pixels = IntArray(width * height) { -0x1000000 or r.nextInt(0x1000000) }
        return Bitmap.createBitmap(pixels, width, height, Bitmap.Config.ARGB_8888)
    }

    private fun jpeg(bitmap: Bitmap, quality: Int = 95): ByteArray =
        ByteArrayOutputStream().also { bitmap.compress(Bitmap.CompressFormat.JPEG, quality, it) }.toByteArray()

    /** A JPEG whose EXIF holds a GPS position, a camera and a date, as a phone's camera writes. */
    private fun withExif(bytes: ByteArray): ByteArray {
        val file = Files.createTempFile("photo", ".jpg").toFile()
        file.writeBytes(bytes)
        ExifInterface(file.absolutePath).apply {
            setAttribute(ExifInterface.TAG_GPS_LATITUDE, "24/1,51/1,0/1")
            setAttribute(ExifInterface.TAG_GPS_LATITUDE_REF, "N")
            setAttribute(ExifInterface.TAG_GPS_LONGITUDE, "67/1,0/1,0/1")
            setAttribute(ExifInterface.TAG_GPS_LONGITUDE_REF, "E")
            setAttribute(ExifInterface.TAG_MAKE, "Samsung")
            setAttribute(ExifInterface.TAG_MODEL, "Galaxy S25")
            setAttribute(ExifInterface.TAG_DATETIME, "2026:11:21 10:00:00")
            saveAttributes()
        }
        return file.readBytes().also { file.delete() }
    }

    private fun hasExif(bytes: ByteArray): Boolean = String(bytes, Charsets.ISO_8859_1).contains("Exif\u0000\u0000")

    private fun ready(o: AvatarEncoder.Outcome) = o as AvatarEncoder.Outcome.Ready

    @Test
    fun `the photo's EXIF, GPS and camera never leave the phone`() {
        val original = withExif(jpeg(noise(600, 400)))
        assertTrue("the planted EXIF is there", hasExif(original))
        assertEquals("Samsung", ExifInterface(original.inputStream()).getAttribute(ExifInterface.TAG_MAKE))
        val out = ready(AvatarEncoder.encode(original)).jpeg
        assertFalse(hasExif(out))
        val exif = ExifInterface(out.inputStream())
        assertNull(exif.getAttribute(ExifInterface.TAG_MAKE))
        assertNull(exif.getAttribute(ExifInterface.TAG_GPS_LATITUDE))
        assertFalse(exif.getLatLong(FloatArray(2)))
        // A JPEG all the same.
        assertEquals(0xFF.toByte(), out[0])
        assertEquals(0xD8.toByte(), out[1])
    }

    @Test
    fun `it is cut to a centred square of at most 1024 px`() {
        val out = ready(AvatarEncoder.encode(jpeg(noise(3000, 1000))))
        val decoded = BitmapFactory.decodeByteArray(out.jpeg, 0, out.jpeg.size)
        assertEquals(decoded.width, decoded.height)
        assertTrue(decoded.width <= AvatarEncoder.SIDE)
        assertEquals(out.side, decoded.width)
        // A smaller picture keeps its size, square.
        val small = ready(AvatarEncoder.encode(jpeg(noise(300, 500))))
        assertEquals(300, small.side)
    }

    @Test
    fun `the upload stays under its size cap`() {
        val big = jpeg(noise(2048, 2048, seed = 7), quality = 100)
        assertTrue(ready(AvatarEncoder.encode(big)).jpeg.size <= AvatarEncoder.MAX_BYTES)
        // A cap far under what noise needs makes it lower the quality, then the size, until it fits.
        val capped = ready(AvatarEncoder.encode(big, maxBytes = 40_000))
        assertTrue("${capped.jpeg.size}", capped.jpeg.size <= 40_000)
    }

    @Test
    fun `a HEIC photo goes through the decoder and comes out a JPEG`() {
        val heic = "....ftypheic....".toByteArray()
        var asked: ByteArray? = null
        val decoder = PictureDecoder { bytes, _ ->
            asked = bytes
            noise(800, 600)
        }
        val out = ready(AvatarEncoder.encode(heic, decoder))
        assertArrayEquals(heic, asked)
        assertEquals(0xFF.toByte(), out.jpeg[0])
        assertEquals(0xD8.toByte(), out.jpeg[1])
        assertEquals(600, out.side)
    }

    @Test
    fun `a picture that can't be read or is too small is refused before any upload`() {
        assertEquals(AvatarEncoder.Outcome.Unreadable, AvatarEncoder.encode("not a picture".toByteArray()))
        assertEquals(AvatarEncoder.Outcome.Unreadable, AvatarEncoder.encode(byteArrayOf(1), PictureDecoder { _, _ -> error("broken") }))
        assertEquals(AvatarEncoder.Outcome.TooSmall, AvatarEncoder.encode(jpeg(noise(500, 100))))
    }

    @Test
    fun `a transparent PNG is put on white, not black`() {
        val clear = Bitmap.createBitmap(200, 200, Bitmap.Config.ARGB_8888)
        val png = ByteArrayOutputStream().also { clear.compress(Bitmap.CompressFormat.PNG, 100, it) }.toByteArray()
        val out = ready(AvatarEncoder.encode(png)).jpeg
        val pixel = BitmapFactory.decodeByteArray(out, 0, out.size).getPixel(100, 100)
        assertTrue("white-ish: ${Integer.toHexString(pixel)}", (pixel and 0xFF) > 0xF0)
    }

    @Test
    fun `a rotated camera photo is turned upright`() {
        val wide = noise(400, 200)
        assertEquals(200, AvatarEncoder.upright(wide, ExifInterface.ORIENTATION_ROTATE_90).width)
        assertEquals(400, AvatarEncoder.upright(wide, ExifInterface.ORIENTATION_NORMAL).width)
        val turned = withExifOrientation(jpeg(wide), ExifInterface.ORIENTATION_ROTATE_90)
        assertEquals(ExifInterface.ORIENTATION_ROTATE_90, AvatarEncoder.orientation(turned))
        val decoded = PlatformPictureDecoder.viaBitmapFactory(turned, 2048)!!
        assertEquals(200, decoded.width)
        assertEquals(400, decoded.height)
    }

    private fun withExifOrientation(bytes: ByteArray, orientation: Int): ByteArray {
        val file = Files.createTempFile("turned", ".jpg").toFile()
        file.writeBytes(bytes)
        ExifInterface(file.absolutePath).apply {
            setAttribute(ExifInterface.TAG_ORIENTATION, orientation.toString())
            saveAttributes()
        }
        return file.readBytes().also { file.delete() }
    }

    @Test
    fun `pictures are kept by person and version, downloaded once per version`(): Unit = runBlocking {
        val cache = AvatarCache(Files.createTempDirectory("avatars").toFile())
        var downloads = 0
        val fetch: suspend () -> ByteArray? = {
            downloads++
            byteArrayOf(1, 2, 3)
        }
        assertArrayEquals(byteArrayOf(1, 2, 3), cache.load("1", 10, fetch))
        assertArrayEquals(byteArrayOf(1, 2, 3), cache.load("1", 10, fetch))
        assertEquals(1, downloads)
        // A new version replaces the old copy; a person's removal forgets it.
        cache.load("1", 11) { byteArrayOf(4) }
        assertNull(cache.get("1", 10))
        assertArrayEquals(byteArrayOf(4), cache.get("1", 11))
        cache.forget("1")
        assertNull(cache.get("1", 11))
        // A stale address (403/404) gives nothing and keeps nothing.
        assertNull(cache.load("2", 1) { null })
        assertNull(cache.get("2", 1))
        assertEquals("7", AvatarCache.personOf("https://echoledger.test/api/avatars/7/1795276846?expires=1&signature=s", "me"))
        assertEquals("me", AvatarCache.personOf(null, "me"))
    }

    @Test
    fun `the QR code holds the otpauth address`() {
        val uri = "otpauth://totp/EchoLedger:a%40x.pk?secret=JBSWY3DPEHPK3PXP&issuer=EchoLedger"
        val bitmap = QrCode.bitmap(uri, 256)
        assertEquals(256, bitmap.width)
        val pixels = IntArray(bitmap.width * bitmap.height).also {
            bitmap.getPixels(it, 0, bitmap.width, 0, 0, bitmap.width, bitmap.height)
        }
        val source = com.google.zxing.RGBLuminanceSource(bitmap.width, bitmap.height, pixels)
        val read = com.google.zxing.qrcode.QRCodeReader().decode(
            com.google.zxing.BinaryBitmap(com.google.zxing.common.HybridBinarizer(source))
        )
        assertEquals(uri, read.text)
    }
}
