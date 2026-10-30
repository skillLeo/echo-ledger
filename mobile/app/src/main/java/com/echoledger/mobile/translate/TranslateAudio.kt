package com.echoledger.mobile.translate

import android.annotation.SuppressLint
import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioPlaybackCaptureConfiguration
import android.media.AudioRecord
import android.media.projection.MediaProjection
import android.os.Build
import android.os.ParcelFileDescriptor
import com.echoledger.mobile.capture.ICaptureHelper
import java.io.DataInputStream
import java.io.EOFException
import java.io.IOException
import kotlin.concurrent.thread

/** Where Live Translate's sound comes from (plan B0 step 4): the helper's per-app mix, or Android's screen-audio capture. */
interface TranslateAudioSource {
    /** Why it could not start, or null when frames (16 kHz mono, 100 ms each) are flowing to [onFrame]. */
    fun start(onFrame: (ShortArray) -> Unit, onEnd: () -> Unit): String?

    fun stop()

    /** "helper" or "projection", for diagnostics and the probe. */
    val kind: String
}

/** (a) The helper's loop-back mix of one app ([uid]); [usage] 0 = all its sound, voice chat allowed for games. */
class HelperAppSource(private val helper: ICaptureHelper, private val uid: Int, private val usage: Int, private val voiceChat: Boolean) :
    TranslateAudioSource {
    override val kind = "helper"

    @Volatile private var reader: ParcelFileDescriptor? = null

    override fun start(onFrame: (ShortArray) -> Unit, onEnd: () -> Unit): String? {
        val (read, write) = ParcelFileDescriptor.createPipe()
        val ok = runCatching { helper.startAppCapture(write, uid, usage, voiceChat) }.getOrElse {
            runCatching { read.close() }
            runCatching { write.close() }
            return "The capture helper did not answer (${it.message ?: it.javaClass.simpleName})."
        }
        runCatching { write.close() } // the helper holds its own copy
        if (!ok) {
            runCatching { read.close() }
            return "The capture helper could not hear this app."
        }
        reader = read
        thread(name = "el-translate-in", isDaemon = true) {
            val input = DataInputStream(ParcelFileDescriptor.AutoCloseInputStream(read))
            val bytes = ByteArray(SpeechGate.FRAME_SAMPLES * 2)
            try {
                while (true) {
                    input.readFully(bytes)
                    onFrame(
                        ShortArray(SpeechGate.FRAME_SAMPLES) { i ->
                            ((bytes[2 * i].toInt() and 0xff) or (bytes[2 * i + 1].toInt() shl 8)).toShort()
                        }
                    )
                }
            } catch (_: EOFException) {
            } catch (_: IOException) {
            } finally {
                runCatching { input.close() }
                onEnd()
            }
        }
        return null
    }

    override fun stop() {
        runCatching { helper.stopAppCapture() }
        runCatching { reader?.close() }
        reader = null
    }
}

/**
 * (b) Android's own playback capture (MediaProjection, a consent dialog each time): media, game and unknown sounds of
 * [uid] (or of every app when null). Apps that opt out stay silent; voice chat is usually not included.
 */
class ProjectionSource(private val projection: MediaProjection, private val uid: Int?) : TranslateAudioSource {
    override val kind = "projection"

    @Volatile private var record: AudioRecord? = null

    @Volatile private var running = false

    @SuppressLint("MissingPermission") // started only after RECORD_AUDIO is granted (the Game and Watch pages check it)
    override fun start(onFrame: (ShortArray) -> Unit, onEnd: () -> Unit): String? {
        if (Build.VERSION.SDK_INT < 29) return "Android 10 or later is needed to hear other apps."
        val config = AudioPlaybackCaptureConfiguration.Builder(projection).apply {
            if (uid != null) {
                addMatchingUid(uid)
            } else {
                addMatchingUsage(AudioAttributes.USAGE_MEDIA)
                addMatchingUsage(AudioAttributes.USAGE_GAME)
                addMatchingUsage(AudioAttributes.USAGE_UNKNOWN)
            }
        }.build()
        val r = runCatching {
            AudioRecord.Builder()
                .setAudioPlaybackCaptureConfig(config)
                .setAudioFormat(
                    AudioFormat.Builder().setSampleRate(16_000).setChannelMask(AudioFormat.CHANNEL_IN_MONO)
                        .setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
                )
                .setBufferSizeInBytes(16_000 / 5 * 2)
                .build()
        }.getOrElse { return "Android refused to share the app's sound (${it.message ?: it.javaClass.simpleName})." }
        if (r.state != AudioRecord.STATE_INITIALIZED) {
            r.release()
            return "Android refused to share the app's sound."
        }
        r.startRecording()
        record = r
        running = true
        thread(name = "el-translate-projection", isDaemon = true) {
            try {
                while (running) {
                    val frame = ShortArray(SpeechGate.FRAME_SAMPLES)
                    var filled = 0
                    while (running && filled < frame.size) {
                        val n = r.read(frame, filled, frame.size - filled)
                        if (n < 0) return@thread
                        filled += n
                    }
                    if (filled == frame.size) onFrame(frame)
                }
            } finally {
                runCatching { r.stop() }
                runCatching { r.release() }
                onEnd()
            }
        }
        return null
    }

    override fun stop() {
        running = false
        record = null
        runCatching { projection.stop() }
    }
}
