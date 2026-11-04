package com.echoledger.mobile.recording

import android.content.Context
import android.media.AudioDeviceInfo
import android.media.AudioManager
import android.media.AudioRecord
import android.os.Build

/**
 * Since v1.5: a Bluetooth headset's microphone (narrow-band call audio) makes transcripts less accurate, so the Record
 * tab says so while it is in use, and before a voice note when a headset is connected.
 */
object BluetoothMic {
    const val NOTE = "Accuracy is lower with Bluetooth mics"

    /** Microphone kinds that come from a Bluetooth headset (SCO calls; LE Audio headsets on Android 12+). */
    fun isBluetooth(type: Int): Boolean = type == AudioDeviceInfo.TYPE_BLUETOOTH_SCO ||
        (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S && type == AudioDeviceInfo.TYPE_BLE_HEADSET)

    /** The recording (or the call's audio) goes through a Bluetooth headset right now. */
    fun inUse(context: Context, record: AudioRecord? = null): Boolean = runCatching {
        record?.routedDevice?.type?.let { if (isBluetooth(it)) return@runCatching true }
        val audio = context.getSystemService(AudioManager::class.java) ?: return@runCatching false
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.S && audio.communicationDevice?.type?.let(::isBluetooth) == true
    }.getOrDefault(false)

    /** A Bluetooth headset with a microphone is connected. */
    fun connected(context: Context): Boolean = runCatching {
        context.getSystemService(AudioManager::class.java)?.getDevices(AudioManager.GET_DEVICES_INPUTS)?.any { isBluetooth(it.type) } ==
            true
    }.getOrDefault(false)
}
