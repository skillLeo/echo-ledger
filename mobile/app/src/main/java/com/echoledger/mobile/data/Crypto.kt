package com.echoledger.mobile.data

import android.content.Context
import com.google.crypto.tink.Aead
import com.google.crypto.tink.KeyTemplates
import com.google.crypto.tink.RegistryConfiguration
import com.google.crypto.tink.StreamingAead
import com.google.crypto.tink.aead.AeadConfig
import com.google.crypto.tink.integration.android.AndroidKeysetManager
import com.google.crypto.tink.streamingaead.StreamingAeadConfig
import java.io.InputStream
import java.io.OutputStream
import java.nio.channels.SeekableByteChannel

/** Small-secret encryption (device token, library cache). Keyset wrapped by an Android Keystore master key. */
interface SecretCipher {
    fun encrypt(plain: ByteArray, context: ByteArray): ByteArray

    fun decrypt(cipher: ByteArray, context: ByteArray): ByteArray
}

/** Streaming encryption for recordings (Tink AES256-GCM-HKDF 4 KB segments, recording id as associated data). */
interface RecordingCipher {
    fun encrypting(out: OutputStream, recordingId: String): OutputStream

    fun decrypting(input: InputStream, recordingId: String): InputStream

    fun seekableDecrypting(channel: SeekableByteChannel, recordingId: String): SeekableByteChannel
}

private const val MASTER_KEY_URI = "android-keystore://echoledger_master_key"
private const val PREFS = "echoledger_keysets"

class TinkSecretCipher(context: Context) : SecretCipher {
    private val aead: Aead by lazy {
        AeadConfig.register()
        AndroidKeysetManager.Builder()
            .withSharedPref(context, "token_keyset", PREFS)
            .withKeyTemplate(KeyTemplates.get("AES256_GCM"))
            .withMasterKeyUri(MASTER_KEY_URI)
            .build()
            .keysetHandle
            .getPrimitive(RegistryConfiguration.get(), Aead::class.java)
    }

    override fun encrypt(plain: ByteArray, context: ByteArray): ByteArray = aead.encrypt(plain, context)

    override fun decrypt(cipher: ByteArray, context: ByteArray): ByteArray = aead.decrypt(cipher, context)
}

class TinkRecordingCipher(context: Context) : RecordingCipher {
    private val streaming: StreamingAead by lazy {
        StreamingAeadConfig.register()
        AndroidKeysetManager.Builder()
            .withSharedPref(context, "recording_keyset", PREFS)
            .withKeyTemplate(KeyTemplates.get("AES256_GCM_HKDF_4KB"))
            .withMasterKeyUri(MASTER_KEY_URI)
            .build()
            .keysetHandle
            .getPrimitive(RegistryConfiguration.get(), StreamingAead::class.java)
    }

    override fun encrypting(out: OutputStream, recordingId: String): OutputStream =
        streaming.newEncryptingStream(out, recordingId.toByteArray())

    override fun decrypting(input: InputStream, recordingId: String): InputStream =
        streaming.newDecryptingStream(input, recordingId.toByteArray())

    override fun seekableDecrypting(channel: SeekableByteChannel, recordingId: String): SeekableByteChannel =
        streaming.newSeekableDecryptingChannel(channel, recordingId.toByteArray())
}
