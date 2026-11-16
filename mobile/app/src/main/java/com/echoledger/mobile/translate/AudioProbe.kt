package com.echoledger.mobile.translate

import android.media.AudioAttributes
import org.json.JSONArray

/**
 * One player the helper sees (plan B0): who plays, what kind of sound, and whether other apps may capture it. [uid] -1
 * and [state] -1 when the helper could not read them; [uidFrom] / [stateFrom] say where it read them ("getter", "text"
 * for the player's public description, "none"; null from a helper older than v1.8). [piid] the player's id (-1 unknown).
 */
data class ProbePlayer(
    val uid: Int,
    val packages: List<String>,
    val usage: Int,
    val contentType: Int,
    val capturePolicy: Int,
    val state: Int,
    val piid: Int = -1,
    val uidFrom: String? = null,
    val stateFrom: String? = null
) {
    val packageName: String? get() = packages.firstOrNull()

    /** The helper could say which app plays it. */
    val placed: Boolean get() = uid > 0

    /** The helper could say whether it plays. */
    val stateKnown: Boolean get() = state >= 0

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

/**
 * The helper's answer to "which players do you see": the players, or [problem] in plain words when there is no answer
 * to read (no helper, the call failed, an answer that cannot be read). Never an empty list in place of an answer.
 * [helperBuild] the build of the helper that answered, [stale] when it is older than this app (still running from
 * before an update).
 */
data class HelperPlayers(
    val players: List<ProbePlayer>,
    val problem: String? = null,
    val helperBuild: Int? = null,
    val stale: Boolean = false
)

object AudioProbe {
    /** The helper's JSON (AppAudioMix.players); a broken answer is an empty list, never a crash. */
    fun parse(json: String?): List<ProbePlayer> = runCatching { parseOrThrow(json ?: "[]") }.getOrDefault(emptyList())

    private fun parseOrThrow(json: String): List<ProbePlayer> {
        val array = JSONArray(json)
        return (0 until array.length()).map { i ->
            val o = array.getJSONObject(i)
            val pkgs = o.optJSONArray("packages")
            ProbePlayer(
                uid = o.optInt("uid", -1),
                packages = if (pkgs == null) emptyList() else (0 until pkgs.length()).map { pkgs.getString(it) },
                usage = o.optInt("usage", 0),
                contentType = o.optInt("content_type", 0),
                capturePolicy = o.optInt("capture_policy", AudioAttributes.ALLOW_CAPTURE_BY_ALL),
                state = o.optInt("state", -1),
                piid = o.optInt("piid", -1),
                uidFrom = o.optString("uid_from").takeIf { it.isNotEmpty() },
                stateFrom = o.optString("state_from").takeIf { it.isNotEmpty() }
            )
        }
    }

    /**
     * Asks [helper] which players it sees. A helper that is gone (its binder died: stopped, or replaced by an update)
     * is reported through [onDead] so it is connected again, and the answer says so; any other failure is said too. A
     * [stale] helper (older than this app) is named in the problem, since restarting it is the fix.
     */
    fun read(
        helper: com.echoledger.mobile.capture.ICaptureHelper?,
        stale: Boolean = false,
        helperBuild: Int? = null,
        onDead: (com.echoledger.mobile.capture.ICaptureHelper) -> Unit = {}
    ): HelperPlayers {
        if (helper == null) return HelperPlayers(emptyList(), "The capture helper is not running.")
        val older = if (stale) " An older capture helper is still running from before an update: restart it." else ""
        val json = try {
            helper.players()
        } catch (e: android.os.DeadObjectException) {
            onDead(helper)
            return HelperPlayers(emptyList(), "The capture helper stopped answering; it is being connected again.", helperBuild, stale)
        } catch (e: Exception) {
            if (!helper.asBinder().isBinderAlive) onDead(helper)
            return HelperPlayers(emptyList(), "The capture helper did not answer (${e.javaClass.simpleName}).$older", helperBuild, stale)
        } ?: return HelperPlayers(emptyList(), "The capture helper gave no answer.$older", helperBuild, stale)
        return runCatching { HelperPlayers(parseOrThrow(json), null, helperBuild, stale) }
            .getOrElse { HelperPlayers(emptyList(), "The capture helper's answer could not be read.$older", helperBuild, stale) }
    }

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

    /** Players the helper could not place (no uid): listed apart in the probe, never dropped. */
    fun unplaced(players: List<ProbePlayer>): List<ProbePlayer> = players.filter { it.uid <= 0 }

    /** "started", "paused", … or "state unknown" (-1: the helper could not read it). */
    fun state(value: Int): String = when (value) {
        0 -> "released"
        1 -> "idle"
        ProbePlayer.STATE_STARTED -> "started"
        3 -> "paused"
        4 -> "stopped"
        -1 -> "state unknown"
        else -> "state $value"
    }

    /**
     * What the helper returned for one player, for the capture log and Copy diagnostics: its uid, state and usage, and
     * where the uid and the state were read ("text": the player's description; "none": not at all) — "uid 10133 (text)
     * · started (text) · Media". Never an app's name: only the app chosen or picked is named, by the caller.
     */
    fun line(p: ProbePlayer): String = listOf(
        (if (p.uid > 0) "uid ${p.uid}" else "uid unknown") + p.uidFrom.from(),
        state(p.state) + p.stateFrom.from(),
        usage(p.usage)
    ).joinToString(" · ")

    private fun String?.from(): String = if (this == null || this == "getter") "" else " ($this)"

    /** The whole answer as lines: what went wrong, else one line per player (or that there are none). */
    fun lines(answer: HelperPlayers): List<String> = buildList {
        answer.helperBuild?.let { add("helper build $it" + if (answer.stale) " (older than this app)" else "") }
        answer.problem?.let { add("no answer: $it") }
        if (answer.problem == null && answer.players.isEmpty()) add("no players")
        answer.players.forEach { add(line(it)) }
    }
}
