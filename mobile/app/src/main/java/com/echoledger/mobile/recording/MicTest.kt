package com.echoledger.mobile.recording

import android.annotation.SuppressLint
import android.content.Context
import android.media.AudioAttributes
import android.media.AudioDeviceInfo
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioRecord
import android.media.AudioTrack
import android.media.MediaRecorder
import android.os.PowerManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.isActive
import kotlinx.coroutines.withContext

/** A microphone the phone can record from (B5); [key] is what `inputDevice` stores. */
data class InputChoice(val key: String, val label: String)

/** Capture health on the phone (B5): input device choice, the 10-second test recording and the permissions centre. */
object CaptureHealth {
    const val TEST_SECONDS = 10
    const val TEST_RATE = 16_000

    /** The choice for an input device type, or null for types that are not microphones the user picks. */
    fun inputChoice(type: Int): InputChoice? = when (type) {
        AudioDeviceInfo.TYPE_BUILTIN_MIC -> InputChoice("builtin", "Phone microphone")
        AudioDeviceInfo.TYPE_WIRED_HEADSET -> InputChoice("wired", "Wired headset")
        AudioDeviceInfo.TYPE_BLUETOOTH_SCO -> InputChoice("bluetooth", "Bluetooth headset")
        AudioDeviceInfo.TYPE_USB_DEVICE, AudioDeviceInfo.TYPE_USB_HEADSET -> InputChoice("usb", "USB microphone")
        else -> null
    }

    /** The connected microphones, each kind once, the phone's own first. */
    fun inputChoices(context: Context): List<InputChoice> {
        val audio = context.getSystemService(AudioManager::class.java) ?: return emptyList()
        return audio.getDevices(AudioManager.GET_DEVICES_INPUTS).mapNotNull { inputChoice(it.type) }.distinctBy { it.key }
            .sortedBy { if (it.key == "builtin") 0 else 1 }
    }

    /** The device to prefer for [key] while it is connected, else null (= the system's choice). */
    fun preferredDevice(context: Context, key: String?): AudioDeviceInfo? {
        if (key == null) return null
        val audio = context.getSystemService(AudioManager::class.java) ?: return null
        return audio.getDevices(AudioManager.GET_DEVICES_INPUTS).firstOrNull { inputChoice(it.type)?.key == key }
    }

    /** Peak level and whether the microphone was heard, from the recorded samples. */
    fun summarize(samples: ShortArray): MicTestResult {
        val peak = Levels.peak(samples, TEST_RATE / 10)
        return MicTestResult(peak, peak >= Levels.HEARD, samples)
    }

    /**
     * Records [seconds] from the microphone (blocking on an IO thread) and reports the level every 100 ms. Needs the
     * microphone permission; refused while a recording runs (the caller checks).
     */
    @SuppressLint("MissingPermission")
    suspend fun record(context: Context, inputKey: String?, seconds: Int = TEST_SECONDS, onLevel: (Float) -> Unit): MicTestResult =
        withContext(Dispatchers.IO) {
            val minBuffer = AudioRecord.getMinBufferSize(TEST_RATE, AudioFormat.CHANNEL_IN_MONO, AudioFormat.ENCODING_PCM_16BIT)
            val record = AudioRecord.Builder()
                .setAudioSource(MediaRecorder.AudioSource.VOICE_RECOGNITION)
                .setAudioFormat(
                    AudioFormat.Builder().setSampleRate(TEST_RATE).setChannelMask(AudioFormat.CHANNEL_IN_MONO)
                        .setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
                )
                .setBufferSizeInBytes(maxOf(minBuffer, TEST_RATE / 5) * 2)
                .build()
            preferredDevice(context, inputKey)?.let { record.setPreferredDevice(it) }
            val all = ShortArray(TEST_RATE * seconds)
            var filled = 0
            try {
                record.startRecording()
                val chunk = ShortArray(TEST_RATE / 10)
                while (isActive && filled < all.size) {
                    val n = record.read(chunk, 0, minOf(chunk.size, all.size - filled))
                    if (n <= 0) break
                    chunk.copyInto(all, filled, 0, n)
                    filled += n
                    onLevel(Levels.rms(chunk, 0, n))
                }
            } finally {
                runCatching { record.stop() }
                record.release()
                onLevel(0f)
            }
            summarize(all.copyOf(filled))
        }

    /** Plays the test recording back through the speaker. */
    fun play(samples: ShortArray): AudioTrack {
        val track = AudioTrack.Builder()
            .setAudioAttributes(
                AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_MEDIA).setContentType(AudioAttributes.CONTENT_TYPE_SPEECH).build()
            )
            .setAudioFormat(
                AudioFormat.Builder().setSampleRate(TEST_RATE).setChannelMask(AudioFormat.CHANNEL_OUT_MONO)
                    .setEncoding(AudioFormat.ENCODING_PCM_16BIT).build()
            )
            .setTransferMode(AudioTrack.MODE_STATIC)
            .setBufferSizeInBytes(maxOf(2, samples.size * 2))
            .build()
        track.write(samples, 0, samples.size)
        track.play()
        return track
    }

    fun ignoresBatteryOptimisation(context: Context): Boolean =
        context.getSystemService(PowerManager::class.java)?.isIgnoringBatteryOptimizations(context.packageName) ?: false

    /** The permissions centre's rows, from what is granted now. */
    fun permissionItems(
        microphone: Boolean,
        notifications: Boolean,
        overlay: Boolean,
        battery: Boolean,
        helper: Boolean,
        callerNames: Boolean? = null
    ): List<PermissionItem> = listOfNotNull(
        PermissionItem("microphone", "Microphone", microphone, "Needed to record you."),
        PermissionItem("helper", "Call capture helper", helper, "Needed to record the other side of calls."),
        PermissionItem(
            "notifications",
            "Notifications",
            notifications,
            "Asks Keep or Discard after a call and shows the recording controls."
        ),
        PermissionItem("overlay", "Display over other apps", overlay, "Shows live captions over the call."),
        PermissionItem("battery", "Unrestricted battery use", battery, "Keeps auto-record ready while the phone sleeps."),
        // Optional (since v1.2): who each WhatsApp call was with, from its call notification only.
        callerNames?.let {
            PermissionItem(
                "caller_names",
                "Notification access",
                it,
                "Shows who each WhatsApp call was with. Only WhatsApp call notifications are read."
            )
        }
    )
}

data class MicTestResult(val peak: Float, val heard: Boolean, val samples: ShortArray) {
    override fun equals(other: Any?): Boolean =
        other is MicTestResult && peak == other.peak && heard == other.heard && samples.contentEquals(other.samples)

    override fun hashCode(): Int = 31 * (31 * peak.hashCode() + heard.hashCode()) + samples.contentHashCode()
}

/** One row of the permissions centre. */
data class PermissionItem(val key: String, val title: String, val granted: Boolean, val why: String)
