package com.echoledger.mobile.ui.v2.shell

/**
 * The interface flags of `GET /api/config` (contract 2.0.0-a, docs/API.md "Interface flags"): [flags] is `ui_flags`,
 * [classic] is `ui_classic`. A flag missing from the answer (an older server) is off and a flag the app doesn't know is
 * ignored. The person's own [design] choice (DesignChoice: "Try the new design", "Switch back to classic") comes first:
 * the new design turns every phone `ui_v2.*` screen on, classic turns them all off (`live_assist` is unaffected).
 */
data class UiFlags(
    val flags: Map<String, Boolean> = emptyMap(),
    val classic: Boolean = false,
    val design: Design? = DesignChoice.current,
    /** The server's `ui_design` (2.0.0-g): which switch it offers; null on an older server. */
    val server: com.echoledger.mobile.data.UiDesignDto? = null
) {
    fun isOn(key: String): Boolean {
        when (design) {
            Design.V2 -> if (key in PHONE_V2) return true
            Design.Classic -> if (key.startsWith(V2_PREFIX)) return false
            null -> Unit
        }
        if (classic && key.startsWith(V2_PREFIX)) return false
        return flags[key] == true
    }

    /** The person is on the classic screens by their own choice. */
    val chosenClassic: Boolean get() = design == Design.Classic || (design == null && classic)

    val shell: Boolean get() = isOn(SHELL)
    val library: Boolean get() = isOn(LIBRARY)
    val settings: Boolean get() = isOn(SETTINGS)

    /**
     * Settings shows "Switch back to classic" while this phone shows the new design by choice, or while the server
     * says so (`can_switch_back`; an older server: while any `ui_v2.*` flag is on, or after they chose classic).
     */
    val offersClassicSwitch: Boolean
        get() = design == Design.V2 || (server?.canSwitchBack ?: (classic || KNOWN_V2.any { flags[it] == true }))

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

        /** The phone's own redesigned screens: all of them on with the new design. */
        val PHONE_V2 = setOf(SHELL, HOME, LIBRARY, RECORDING_CONTROLS, AFTER_CALL, LIVE, ASK, SETTINGS, SETUP_HEALTH)

        /** The `ui_v2.*` names of contract 2.0.0-a; an unknown name never counts. */
        val KNOWN_V2 = setOf(SHELL, HOME, LIBRARY, RECORDING_CONTROLS, AFTER_CALL, LIVE, ASK, SETTINGS, SETUP_HEALTH, ADMIN, WEBSITE)

        val Off = UiFlags(design = null)
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
