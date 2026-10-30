package com.echoledger.mobile.translate

/**
 * Game translate starts by itself when a picked game plays voice chat and stops when the game has been silent and gone
 * for a minute (plan B3). Decided from the helper's player list, polled every few seconds; pure, so it is tested.
 */
class GameWatch(private val stopAfterMs: Long = STOP_AFTER_MS) {
    sealed interface Action {
        data object None : Action

        data class Start(val packageName: String) : Action

        data object Stop : Action
    }

    private var lastHeardMs = 0L

    /** [startedByUs]: the running Game translate was started by this watch (a manual start is never stopped here). */
    fun decide(
        players: List<ProbePlayer>,
        games: List<String>,
        autoStart: Boolean,
        running: Boolean,
        startedByUs: Boolean,
        nowMs: Long
    ): Action {
        if (games.isEmpty()) return Action.None
        val gamePlayers = players.filter { p -> p.uid > 0 && p.packages.any { it in games } }
        if (gamePlayers.isNotEmpty()) lastHeardMs = nowMs
        return when {
            !running && autoStart -> gamePlayers.firstOrNull { it.playing && it.voiceChat }?.packageName?.let { Action.Start(it) }
                ?: Action.None

            running && startedByUs && gamePlayers.isEmpty() && nowMs - lastHeardMs >= stopAfterMs -> Action.Stop

            else -> Action.None
        }
    }

    companion object {
        const val POLL_MS = 3_000L
        const val STOP_AFTER_MS = 60_000L
    }
}
