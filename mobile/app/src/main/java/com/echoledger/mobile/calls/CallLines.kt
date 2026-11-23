package com.echoledger.mobile.calls

/**
 * Where a choice made on the after-call card is confirmed (v2.1, I-05): never on a floating window. Inside the app it is
 * the app's own snackbar host ([Host]: one at a time, above the bottom bar, Material 3's Long time, a swipe dismisses
 * it); outside the app it is the after-call notification, updated in place
 * ([com.echoledger.mobile.notify.Notifications.choiceLine]). The shell attaches its host while the app is on screen.
 */
object CallLines {
    /** Material 3's SnackbarDuration.Long. */
    const val LONG_MS = 10_000L

    /** One confirmation: [pick] for the call [id], or (no id) [count] calls saved together as audio only. */
    data class Line(val id: String?, val pick: CallChoice.Pick?, val count: Int = 1)

    /** What a line does. */
    class Actions(
        /** Undo (a choice), or Review (calls saved together). */
        val action: () -> Unit,
        /** Its time is over or it was swiped away: the choice stands. */
        val ended: () -> Unit = {},
        /** The app left the screen with [leftMs] of the line still to go: it carries on in a notification. */
        val away: (leftMs: Long) -> Unit = {}
    )

    /** The app's snackbar host for these lines (main thread). */
    interface Host {
        /**
         * Shows [line] for [lineMs] once the lines with an end time ahead of it are over, in place of the line this host
         * shows for the same call (or the same group). Returns how long from now until it ends: what the call's upload
         * or delete waits for, so its Undo always finds the call.
         */
        fun show(line: Line, lineMs: Long, actions: Actions): Long

        /** Takes the line for the call [id] (null: the group's) away, running nothing. */
        fun cancel(id: String?)
    }

    /** The host on screen; null while the app is not (then the notification confirms). */
    @Volatile
    var host: Host? = null
        private set

    fun attach(host: Host) {
        this.host = host
    }

    fun detach(host: Host) {
        if (this.host === host) this.host = null
    }

    /** A line handed from a host being made again (a rotation) to the next one: it ends at [endsAt], as before. */
    class Carried(val line: Line, val endsAt: Long, val actions: Actions)

    private val carried = mutableListOf<Carried>()

    /**
     * The app's screen is being made again (a rotation): its lines wait for the next host ([takeCarried]) and keep their
     * end times. One the next host never takes (the app did not come back to the screen) goes on in the notification
     * ([Actions.away]) after [CARRY_MS]. Main thread.
     */
    fun carry(lines: List<Carried>, clock: () -> Long) {
        if (lines.isEmpty()) return
        carried += lines
        android.os.Handler(android.os.Looper.getMainLooper()).postDelayed({
            val left = lines.filter { carried.remove(it) }
            val now = clock()
            left.forEach { c -> (c.endsAt - now).takeIf { it > 0 }?.let(c.actions.away) }
        }, CARRY_MS)
    }

    /** The lines handed over by the host before this one (main thread). */
    fun takeCarried(): List<Carried> = carried.toList().also { carried.clear() }

    /** How long a rotation may take before the lines it carried go on in the notification. */
    const val CARRY_MS = 2_000L
}
