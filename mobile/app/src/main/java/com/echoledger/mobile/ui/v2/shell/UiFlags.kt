package com.echoledger.mobile.ui.v2.shell

/**
 * The interface flags of `GET /api/config` (contract 2.0.0-a, docs/API.md "Interface flags"): [flags] is `ui_flags`,
 * [classic] is `ui_classic`. The server decides them; the phone never does. A flag missing from the answer (an older
 * server) is off, a flag the app doesn't know is ignored, and "Switch back to classic" turns every `ui_v2.*` flag off
 * for this person (`live_assist` is unaffected).
 */
data class UiFlags(val flags: Map<String, Boolean> = emptyMap(), val classic: Boolean = false) {
    fun isOn(key: String): Boolean {
        if (classic && key.startsWith(V2_PREFIX)) return false
        return flags[key] == true
    }

    val shell: Boolean get() = isOn(SHELL)
    val library: Boolean get() = isOn(LIBRARY)
    val settings: Boolean get() = isOn(SETTINGS)

    /** Settings shows "Switch back to classic" while any `ui_v2.*` flag is on for this person, or after they chose it. */
    val offersClassicSwitch: Boolean get() = classic || KNOWN_V2.any { flags[it] == true }

    companion object {
        const val V2_PREFIX = "ui_v2."
        const val SHELL = "ui_v2.shell"
        const val HOME = "ui_v2.home"
        const val LIBRARY = "ui_v2.library"
        const val RECORDING_CONTROLS = "ui_v2.recording_controls"
        const val AFTER_CALL = "ui_v2.after_call"
        const val LIVE = "ui_v2.live"
        const val ASK = "ui_v2.ask"
        const val SETTINGS = "ui_v2.settings"
        const val SETUP_HEALTH = "ui_v2.setup_health"
        const val LIVE_ASSIST = "live_assist"

        const val ADMIN = "ui_v2.admin"
        const val WEBSITE = "ui_v2.website"

        /** The `ui_v2.*` names of contract 2.0.0-a; an unknown name never counts. */
        val KNOWN_V2 = setOf(SHELL, HOME, LIBRARY, RECORDING_CONTROLS, AFTER_CALL, LIVE, ASK, SETTINGS, SETUP_HEALTH, ADMIN, WEBSITE)

        val Off = UiFlags()
    }
}

/**
 * Holds the flags a screen opened with. A change from the server applies on the next screen the person opens, never in
 * the middle of a recording (docs/API.md): [screenOpened] takes the newest flags unless a recording is running.
 */
class UiFlagLatch(initial: UiFlags = UiFlags.Off) {
    var current: UiFlags = initial
        private set
    private var waiting: UiFlags? = null

    /** The server answered with [flags]; they wait for the next screen. */
    fun offer(flags: UiFlags) {
        waiting = flags.takeIf { it != current }
    }

    /** A screen is opening: apply waiting flags unless [recording]. Returns the flags to draw it with. */
    fun screenOpened(recording: Boolean): UiFlags {
        if (!recording) {
            waiting?.let { current = it }
            waiting = null
        }
        return current
    }
}
