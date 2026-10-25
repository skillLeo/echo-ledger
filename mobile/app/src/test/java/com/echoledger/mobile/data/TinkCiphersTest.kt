package com.echoledger.mobile.data

import androidx.test.core.app.ApplicationProvider
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.testsupport.FakeAndroidKeyStoreProvider
import com.echoledger.mobile.testsupport.sampleBytes
import java.io.ByteArrayInputStream
import java.io.ByteArrayOutputStream
import java.io.File
import java.io.IOException
import java.nio.ByteBuffer
import java.nio.channels.FileChannel
import java.nio.file.StandardOpenOption
import org.junit.After
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Assume.assumeTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The production Tink ciphers with their Keystore-wrapped keysets, run against an in-memory AndroidKeyStore stand-in.
 * If Tink cannot use the stand-in, the tests are skipped, not passed.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class TinkCiphersTest {
    private lateinit var app: EchoLedgerApp

    @Before
    fun installKeystore() {
        FakeAndroidKeyStoreProvider.install()
        app = ApplicationProvider.getApplicationContext()
        val probe = runCatching { TinkSecretCipher(app).encrypt(byteArrayOf(1), byteArrayOf(2)) }
        assumeTrue("the Keystore-wrapped keyset cannot be created here: ${probe.exceptionOrNull()}", probe.isSuccess)
    }

    @After
    fun removeKeystore() {
        FakeAndroidKeyStoreProvider.uninstall()
    }

    private val context = "echoledger.test".toByteArray()

    @Test
    fun `small secrets round trip and a second instance reads the persisted keyset`() {
        val first = TinkSecretCipher(app)
        val sealed = first.encrypt("7|eld_token".toByteArray(), context)

        assertEquals("7|eld_token", String(first.decrypt(sealed, context)))
        assertEquals("7|eld_token", String(TinkSecretCipher(app).decrypt(sealed, context)))
        assertFalse(String(sealed, Charsets.ISO_8859_1).contains("eld_token"))
    }

    @Test
    fun `every encryption is different and tampering or a wrong context is detected`() {
        val cipher = TinkSecretCipher(app)
        val a = cipher.encrypt("same".toByteArray(), context)
        val b = cipher.encrypt("same".toByteArray(), context)
        assertFalse(a.contentEquals(b))

        assertThrows(Exception::class.java) { cipher.decrypt(a, "other".toByteArray()) }
        val flipped = a.copyOf().also { it[it.size - 1] = (it[it.size - 1].toInt() xor 1).toByte() }
        assertThrows(Exception::class.java) { cipher.decrypt(flipped, context) }
        assertThrows(Exception::class.java) { cipher.decrypt(a.copyOf(a.size - 3), context) }
    }

    @Test
    fun `empty and large plaintexts survive`() {
        val cipher = TinkSecretCipher(app)
        assertEquals(0, cipher.decrypt(cipher.encrypt(ByteArray(0), context), context).size)
        val big = sampleBytes(1_000_000)
        assertArrayEquals(big, cipher.decrypt(cipher.encrypt(big, context), context))
    }

    private fun encrypt(cipher: TinkRecordingCipher, id: String, plain: ByteArray): ByteArray {
        val out = ByteArrayOutputStream()
        cipher.encrypting(out, id).use { it.write(plain) }
        return out.toByteArray()
    }

    @Test
    fun `recordings round trip as a stream bound to their id`() {
        val cipher = TinkRecordingCipher(app)
        val plain = sampleBytes(200_000)

        val sealed = encrypt(cipher, "rec-1", plain)

        assertNotEquals(plain.size, sealed.size)
        assertArrayEquals(plain, cipher.decrypting(ByteArrayInputStream(sealed), "rec-1").readBytes())
        assertThrows(IOException::class.java) { cipher.decrypting(ByteArrayInputStream(sealed), "rec-2").readBytes() }
    }

    @Test
    fun `a truncated or altered recording is detected instead of read as shorter audio`() {
        val cipher = TinkRecordingCipher(app)
        val sealed = encrypt(cipher, "rec-1", sampleBytes(50_000))

        assertThrows(IOException::class.java) {
            cipher.decrypting(ByteArrayInputStream(sealed.copyOf(sealed.size - 100)), "rec-1").readBytes()
        }
        val altered = sealed.copyOf().also { it[sealed.size / 2] = (it[sealed.size / 2].toInt() xor 0x55).toByte() }
        assertThrows(IOException::class.java) { cipher.decrypting(ByteArrayInputStream(altered), "rec-1").readBytes() }
    }

    @Test
    fun `slices read through the seekable channel match the plaintext across segment boundaries`() {
        val cipher = TinkRecordingCipher(app)
        val plain = sampleBytes(100_000)
        val file = File(app.cacheDir, "rec.elr").apply { writeBytes(encrypt(cipher, "rec-1", plain)) }

        listOf(0L to 1_000, 4_000L to 200, 4_090L to 20, 8_191L to 5_000, 99_900L to 100, 50_000L to 1).forEach { (offset, length) ->
            FileChannel.open(file.toPath(), StandardOpenOption.READ).use { raw ->
                cipher.seekableDecrypting(raw, "rec-1").use { channel ->
                    channel.position(offset)
                    val buffer = ByteBuffer.allocate(length)
                    while (buffer.hasRemaining() && channel.read(buffer) >= 0) Unit
                    assertArrayEquals(
                        "offset $offset length $length",
                        plain.copyOfRange(offset.toInt(), offset.toInt() + length),
                        buffer.array()
                    )
                }
            }
        }
        assertTrue(file.delete())
    }
}
