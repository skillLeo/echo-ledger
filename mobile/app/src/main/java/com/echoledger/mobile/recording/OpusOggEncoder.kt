package com.echoledger.mobile.recording

import android.media.MediaCodec
import android.media.MediaFormat
import android.media.MediaMuxer
import java.io.File

/**
 * PCM 16-bit interleaved → Opus (MediaCodec, Android 10+) → Ogg (MediaMuxer MUXER_OUTPUT_OGG, Android 10+).
 * Two channels keep "Me" (left) and "Client" (right) separate for multichannel transcription.
 * Writes a plaintext temp file in app-private cache; RecordingStore encrypts it immediately after [finish].
 */
class OpusOggEncoder(
    private val output: File,
    private val sampleRate: Int = Pcm.CAPTURE_RATE,
    private val channels: Int = 2,
    bitrate: Int = 32_000 * channels
) {
    private val codec = MediaCodec.createEncoderByType(MediaFormat.MIMETYPE_AUDIO_OPUS)
    private val muxer = MediaMuxer(output.absolutePath, MediaMuxer.OutputFormat.MUXER_OUTPUT_OGG)
    private val info = MediaCodec.BufferInfo()
    private var track = -1
    private var muxerStarted = false
    private var framesQueued = 0L

    var framesWritten = 0L
        private set

    init {
        val format = MediaFormat.createAudioFormat(MediaFormat.MIMETYPE_AUDIO_OPUS, sampleRate, channels).apply {
            setInteger(MediaFormat.KEY_BIT_RATE, bitrate)
            setInteger(MediaFormat.KEY_MAX_INPUT_SIZE, 16 * 1024)
        }
        codec.configure(format, null, null, MediaCodec.CONFIGURE_FLAG_ENCODE)
        codec.start()
    }

    private fun ptsUs(frames: Long) = frames * 1_000_000L / sampleRate

    @Synchronized
    fun feed(pcm: ByteArray, length: Int = pcm.size) {
        var offset = 0
        val bytesPerFrame = 2 * channels
        while (offset < length) {
            val index = codec.dequeueInputBuffer(10_000)
            if (index < 0) {
                drain(endOfStream = false)
                continue
            }
            val buffer = codec.getInputBuffer(index) ?: continue
            buffer.clear()
            val n = minOf(buffer.remaining() / bytesPerFrame * bytesPerFrame, length - offset)
            buffer.put(pcm, offset, n)
            codec.queueInputBuffer(index, 0, n, ptsUs(framesQueued), 0)
            framesQueued += n / bytesPerFrame
            offset += n
            drain(endOfStream = false)
        }
        framesWritten = framesQueued
    }

    @Synchronized
    fun finish() {
        runCatching {
            val index = codec.dequeueInputBuffer(50_000)
            if (index >= 0) codec.queueInputBuffer(index, 0, 0, ptsUs(framesQueued), MediaCodec.BUFFER_FLAG_END_OF_STREAM)
            drain(endOfStream = true)
        }
        runCatching { codec.stop() }
        codec.release()
        if (muxerStarted) runCatching { muxer.stop() }
        muxer.release()
    }

    private fun drain(endOfStream: Boolean) {
        var idleSpins = 0
        while (true) {
            val index = codec.dequeueOutputBuffer(info, if (endOfStream) 10_000 else 0)
            when {
                index == MediaCodec.INFO_TRY_AGAIN_LATER -> {
                    if (!endOfStream || ++idleSpins > 100) return
                }

                index == MediaCodec.INFO_OUTPUT_FORMAT_CHANGED -> {
                    track = muxer.addTrack(codec.outputFormat) // carries the OpusHead codec-specific data
                    muxer.start()
                    muxerStarted = true
                }

                index >= 0 -> {
                    val buffer = codec.getOutputBuffer(index)
                    if (info.flags and MediaCodec.BUFFER_FLAG_CODEC_CONFIG != 0) info.size = 0
                    if (buffer != null && info.size > 0 && muxerStarted) {
                        buffer.position(info.offset)
                        buffer.limit(info.offset + info.size)
                        muxer.writeSampleData(track, buffer, info)
                    }
                    codec.releaseOutputBuffer(index, false)
                    if (info.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM != 0) return
                }
            }
        }
    }
}
