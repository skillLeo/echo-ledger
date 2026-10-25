package com.echoledger.mobile.ui

import androidx.biometric.BiometricManager
import java.util.UUID

/** Whether the app content may be shown. `Unknown` until the stored settings have loaded (the splash is shown). */
enum class LockState { Unknown, Locked, Unlocked }

/** What the lock decisions depend on: the user's setting, whether the phone can authenticate, and the grace period. */
data class LockInput(val enabled: Boolean, val available: Boolean, val lockAfterMs: Long) {
    /** The lock applies: switched on and something on the phone can unlock it. */
    val applies: Boolean get() = enabled && available
}

/** The "Lock after" choices, in the order the settings screen lists them. */
object LockAfter {
    const val IMMEDIATELY = 0L
    const val ONE_MINUTE = 60_000L
    const val FIVE_MINUTES = 300_000L

    val choices: List<Pair<Long, String>> = listOf(
        IMMEDIATELY to "Immediately",
        ONE_MINUTE to "After 1 minute",
        FIVE_MINUTES to "After 5 minutes"
    )

    /** Any stored value other than the offered ones counts as Immediately. */
    fun normalize(ms: Long): Long = if (ms == ONE_MINUTE || ms == FIVE_MINUTES) ms else IMMEDIATELY
}

/**
 * The app lock as a pure state machine, driven by the activity: settings loaded or changed, the app going to the
 * background (`ON_STOP`) and coming back (`ON_START`), and the outcome of an unlock prompt.
 *
 * [clock] is a monotonic millisecond clock (`SystemClock.elapsedRealtime` in the app), so changing the phone's time
 * cannot shorten the grace period.
 */
class AppLockMachine(private val clock: () -> Long) {
    var state: LockState = LockState.Unknown
        private set

    /** When the app last went to the background while the state was known; null while it is in the foreground. */
    var backgroundedAt: Long? = null
        private set

    private var noScreenLockNotice = false
    private var noScreenLockNoticeGiven = false

    /** First settings value after a cold start: lock when the lock applies. Later calls behave like a settings change. */
    fun onSettingsLoaded(input: LockInput) {
        if (state != LockState.Unknown) return onSettingsChanged(input)
        state = if (input.applies) LockState.Locked else LockState.Unlocked
        if (input.enabled && !input.available) noteNoScreenLock()
    }

    /** Turning the lock off (or losing the screen lock) unlocks; turning it on never locks by itself. */
    fun onSettingsChanged(input: LockInput) {
        if (state == LockState.Unknown) return
        if (!input.applies) state = LockState.Unlocked
    }

    /** The app went to the background. With "Immediately" the lock engages at once. */
    fun onStop(input: LockInput) {
        if (state == LockState.Unknown) return
        backgroundedAt = clock()
        if (input.applies && LockAfter.normalize(input.lockAfterMs) == LockAfter.IMMEDIATELY) state = LockState.Locked
    }

    /** The app came back to the foreground: lock when it stayed away at least the grace period. */
    fun onStart(input: LockInput) {
        val since = backgroundedAt ?: return
        backgroundedAt = null
        if (state == LockState.Unknown) return
        val due = clock() - since >= LockAfter.normalize(input.lockAfterMs)
        when {
            input.applies && due -> state = LockState.Locked

            // The screen lock was removed while the app was away: never trap the user behind the cover.
            input.enabled && !input.available && (due || state == LockState.Locked) -> {
                state = LockState.Unlocked
                noteNoScreenLock()
            }
        }
    }

    fun onUnlockSucceeded() {
        if (state == LockState.Locked) state = LockState.Unlocked
    }

    /** A cancelled or failed prompt keeps the app locked; the cover offers Unlock again. */
    fun onUnlockFailed() = Unit

    /**
     * True once after a due lock was skipped because the phone has no screen lock, so the user can be told. The notice
     * is given at most once per machine (per screen), never on every return to the app.
     */
    fun takeNoScreenLockNotice(): Boolean = noScreenLockNotice.also { noScreenLockNotice = false }

    private fun noteNoScreenLock() {
        if (noScreenLockNoticeGiven) return
        noScreenLockNoticeGiven = true
        noScreenLockNotice = true
    }

    /** What survives a configuration change. */
    data class Saved(val process: String, val state: LockState, val backgroundedAt: Long?)

    fun save(process: String = PROCESS): Saved = Saved(process, state, backgroundedAt)

    /**
     * Restores the state saved before a configuration change. A snapshot written by an earlier process (the system
     * restores saved state after process death) is ignored, so a cold start always decides afresh and re-locks.
     */
    fun restore(saved: Saved, currentProcess: String = PROCESS) {
        if (saved.process != currentProcess) return
        state = saved.state
        backgroundedAt = saved.backgroundedAt
    }

    companion object {
        /** Identifies the running process; saved instance state from another process carries a different value. */
        val PROCESS: String = UUID.randomUUID().toString()
    }
}

enum class Method { Biometric, Keyguard, None }

/** How the app lock can unlock on this phone, and why it cannot when [method] is [Method.None]. */
data class LockAvailability(val method: Method, val reason: String?)

const val REASON_NO_SCREEN_LOCK = "Set a screen lock on this phone to use App lock."
const val REASON_SECURITY_UPDATE = "Install the latest security update to use App lock."

/**
 * Maps `BiometricManager.canAuthenticate(BIOMETRIC_WEAK or DEVICE_CREDENTIAL)` and `KeyguardManager.isDeviceSecure()`
 * to the way the lock unlocks. When BiometricPrompt cannot be used but a PIN, pattern or password is set, the system's
 * confirm-credential screen is used instead.
 */
fun lockAvailability(status: Int, deviceSecure: Boolean): LockAvailability = when (status) {
    BiometricManager.BIOMETRIC_SUCCESS -> LockAvailability(Method.Biometric, null)

    BiometricManager.BIOMETRIC_ERROR_SECURITY_UPDATE_REQUIRED ->
        if (deviceSecure) LockAvailability(Method.Keyguard, null) else LockAvailability(Method.None, REASON_SECURITY_UPDATE)

    // BIOMETRIC_ERROR_UNSUPPORTED, BIOMETRIC_STATUS_UNKNOWN, BIOMETRIC_ERROR_NONE_ENROLLED,
    // BIOMETRIC_ERROR_NO_HARDWARE, BIOMETRIC_ERROR_HW_UNAVAILABLE and anything newer.
    else -> if (deviceSecure) LockAvailability(Method.Keyguard, null) else LockAvailability(Method.None, REASON_NO_SCREEN_LOCK)
}
