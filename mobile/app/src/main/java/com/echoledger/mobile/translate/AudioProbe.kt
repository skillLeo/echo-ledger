package com.echoledger.mobile.translate

import android.media.AudioAttributes
import org.json.JSONArray

/** One player the helper sees (plan B0): who plays, what kind of sound, and whether other apps may capture it. */
data class ProbePlayer(
    val uid: Int,
    val packages: List<String>,
    val usage: Int,
    val contentType: Int,
    val capturePolicy: Int,
    val state: Int
) {
    val packageName: String? get() = packages.firstOrNull()

    /** Voice chat in a game or a call app (what Game translate listens to). */
    val voiceChat: Boolean get() = usage == AudioAttributes.USAGE_VOICE_COMMUNICATION

    /**
     * Whether Live Translate may hear it: apps that opt out of capture (ALLOW_CAPTURE_BY_SYSTEM or _NONE, typical of
     * protected video) are left alone; voice chat is captured as call audio is.
     */
    val capturable: Boolean get() = voiceChat || capturePolicy == AudioAttributes.ALLOW_CAPTURE_BY_ALL

    val playing: Boolean get() = state == STATE_STARTED

    companion object {
        const val STATE_STARTED = 2
    }
}

object AudioProbe {
    /** The helper's JSON (AppAudioMix.players); a broken answer is an empty list, never a crash. */
    fun parse(json: String?): List<ProbePlayer> = runCatching {
        val array = JSONArray(json ?: "[]")
        (0 until array.length()).map { i ->
            val o = array.getJSONObject(i)
            val pkgs = o.optJSONArray("packages")
            ProbePlayer(
                uid = o.optInt("uid", -1),
                packages = if (pkgs == null) emptyList() else (0 until pkgs.length()).map { pkgs.getString(it) },
                usage = o.optInt("usage", 0),
                contentType = o.optInt("content_type", 0),
                capturePolicy = o.optInt("capture_policy", AudioAttributes.ALLOW_CAPTURE_BY_ALL),
                state = o.optInt("state", -1)
            )
        }
    }.getOrDefault(emptyList())

    fun usage(value: Int): String = when (value) {
        AudioAttributes.USAGE_MEDIA -> "Media"
        AudioAttributes.USAGE_VOICE_COMMUNICATION -> "Voice chat"
        AudioAttributes.USAGE_VOICE_COMMUNICATION_SIGNALLING -> "Call tones"
        AudioAttributes.USAGE_ALARM -> "Alarm"
        AudioAttributes.USAGE_NOTIFICATION, AudioAttributes.USAGE_NOTIFICATION_EVENT -> "Notification"
        AudioAttributes.USAGE_NOTIFICATION_RINGTONE -> "Ringtone"
        AudioAttributes.USAGE_ASSISTANCE_ACCESSIBILITY -> "Accessibility"
        AudioAttributes.USAGE_ASSISTANCE_NAVIGATION_GUIDANCE -> "Navigation"
        AudioAttributes.USAGE_ASSISTANCE_SONIFICATION -> "Sounds"
        AudioAttributes.USAGE_GAME -> "Game"
        AudioAttributes.USAGE_ASSISTANT -> "Assistant"
        0 -> "Unknown"
        else -> "Other ($value)"
    }

    fun content(value: Int): String = when (value) {
        AudioAttributes.CONTENT_TYPE_SPEECH -> "speech"
        AudioAttributes.CONTENT_TYPE_MUSIC -> "music"
        AudioAttributes.CONTENT_TYPE_MOVIE -> "film"
        AudioAttributes.CONTENT_TYPE_SONIFICATION -> "effects"
        else -> "unknown"
    }

    fun policy(value: Int): String = when (value) {
        AudioAttributes.ALLOW_CAPTURE_BY_ALL -> "allows capture"
        AudioAttributes.ALLOW_CAPTURE_BY_SYSTEM -> "system capture only"
        AudioAttributes.ALLOW_CAPTURE_BY_NONE -> "blocks capture"
        else -> "capture policy $value"
    }

    /** "Voice chat · speech · allows capture". */
    fun describe(p: ProbePlayer): String = listOf(usage(p.usage), content(p.contentType), policy(p.capturePolicy)).joinToString(" · ")

    /** The message for an app that opts out (plan B0: "This app doesn't allow live captions"). */
    const val NOT_ALLOWED = "This app doesn't allow live captions."

    /** One player per app: the one playing, voice chat first. */
    fun byApp(players: List<ProbePlayer>): List<ProbePlayer> = players.filter { it.uid > 0 }
        .sortedWith(compareBy<ProbePlayer>({ !it.playing }, { !it.voiceChat }))
        .distinctBy { it.uid to it.usage }
}
