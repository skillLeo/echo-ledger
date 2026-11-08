package com.echoledger.mobile.core

import com.echoledger.mobile.privilege.AppCallCapture
import com.echoledger.mobile.privilege.Capabilities

/** The one-tap fix of a readiness problem (since v1.5, Phase 1C). */
enum class ReadyFix(val label: String) {
    /** Shizuku is up and allowed: start the capture helper through it, no screen. */
    START_HELPER("Start"),

    /** Shizuku is installed but not running (after a restart Android turns Wireless debugging off). */
    OPEN_SHIZUKU("Open Shizuku"),
    ALLOW_SHIZUKU("Allow"),

    /** Never set up on this phone: the guided setup. */
    SET_UP("Set up"),

    /** The running helper is from an older version, or did not get ready: start it again. */
    RESTART_HELPER("Restart"),
    MICROPHONE("Allow"),
    NOTIFICATIONS("Allow"),
    BATTERY("Allow"),
    TURN_ON("Turn on"),
    SIGN_IN("Sign in"),
    STORAGE("Free up space"),
    TEST("Test a call")
}

/**
 * One thing that stops calls being recorded, or makes them worse. [blocking]: calls are not recorded (or not fully)
 * until it is fixed. [critical]: it broke by itself, so the phone says so at once, even in quiet hours.
 */
data class ReadyProblem(
    val key: String,
    val title: String,
    val detail: String,
    val fix: ReadyFix?,
    val blocking: Boolean,
    val critical: Boolean = false
)

/** Everything the Ready check looks at, read once on the phone, so [Readiness.evaluate] is pure. */
data class ReadyFacts(
    val capabilities: Capabilities,
    /** Auto-record is on in Settings (the person wants calls recorded). */
    val autoRecordOn: Boolean,
    val shizukuInstalled: Boolean,
    val shizukuRunning: Boolean,
    val shizukuAllowed: Boolean,
    /** The capture helper ran on this phone before: a missing helper then stopped, rather than was never set up. */
    val helperEverConnected: Boolean,
    val helperStale: Boolean = false,
    /** The helper's answer to the last arm ("armed", "error: …"); null when it was not asked. */
    val armState: String? = null,
    val signedIn: Boolean,
    val freeStorageMb: Long,
    val batteryExempt: Boolean,
    /** The last call recorded only this phone's side (A5); null when the last call had both or none was checked. */
    val lastCallOneSided: String? = null
)

/** What the chip says. */
enum class ReadyChip(val text: String) {
    READY("Calls: Ready"),
    NOT_READY("Not ready — Fix"),
    OFF("Calls: Off"),

    /** This phone cannot record calls (Android 11 and older, or the Google Play build): no chip. */
    NONE("")
}

data class Readiness(val problems: List<ReadyProblem>, val chip: ReadyChip) {
    val ready: Boolean get() = chip == ReadyChip.READY
    val critical: List<ReadyProblem> get() = problems.filter { it.critical }

    /** The line under the check: "✓ Ready to record calls" or the first problem. */
    val headline: String
        get() = when (chip) {
            ReadyChip.READY -> "✓ Ready to record calls"
            ReadyChip.OFF -> "Calls are not recorded automatically"
            ReadyChip.NONE -> "Voice notes are ready"
            ReadyChip.NOT_READY -> problems.first { it.blocking }.title
        }

    companion object {
        /** Below this, a long call might not fit (an hour of a two-sided call is about 30 MB). */
        const val LOW_STORAGE_MB = 200L

        fun evaluate(facts: ReadyFacts): Readiness {
            val caps = facts.capabilities
            val callsPossible = caps.helperInBuild && caps.callCaptureSupportedByOs
            val problems = buildList {
                if (callsPossible) addAll(helperProblems(facts))
                if (!caps.micPermission) {
                    add(
                        ReadyProblem(
                            "microphone",
                            "The microphone is not allowed",
                            "Nothing can be recorded until EchoLedger may use the microphone.",
                            ReadyFix.MICROPHONE,
                            blocking = true,
                            critical = facts.autoRecordOn
                        )
                    )
                }
                if (facts.freeStorageMb < LOW_STORAGE_MB) {
                    add(
                        ReadyProblem(
                            "storage",
                            "The phone is almost full",
                            "${facts.freeStorageMb} MB free. A long call may not fit: free up some space.",
                            ReadyFix.STORAGE,
                            blocking = true,
                            critical = facts.autoRecordOn
                        )
                    )
                }
                if (!caps.notificationPermission) {
                    add(
                        ReadyProblem(
                            "notifications",
                            "Notifications are off",
                            "EchoLedger cannot tell you when recording stops working, or ask after a call.",
                            ReadyFix.NOTIFICATIONS,
                            blocking = false
                        )
                    )
                }
                if (callsPossible && !facts.batteryExempt) {
                    add(
                        ReadyProblem(
                            "battery",
                            "Android may stop EchoLedger in the background",
                            "Allow it to run in the background so calls are recorded while the app is closed.",
                            ReadyFix.BATTERY,
                            blocking = false
                        )
                    )
                }
                if (!facts.signedIn) {
                    add(
                        ReadyProblem(
                            "sign_in",
                            "Not signed in",
                            "Recordings are kept on this phone and are transcribed once you sign in.",
                            ReadyFix.SIGN_IN,
                            blocking = false
                        )
                    )
                }
                facts.lastCallOneSided?.let { why ->
                    add(ReadyProblem("one_sided", "Only your voice was recorded in the last call", why, ReadyFix.TEST, blocking = false))
                }
            }
            val chip = when {
                !callsPossible -> ReadyChip.NONE
                problems.any { it.blocking } -> ReadyChip.NOT_READY
                !facts.autoRecordOn -> ReadyChip.OFF
                else -> ReadyChip.READY
            }
            return Readiness(problems, chip)
        }

        private fun helperProblems(facts: ReadyFacts): List<ReadyProblem> {
            val caps = facts.capabilities
            val critical = facts.autoRecordOn
            val voiceOnly = caps.appCalls == AppCallCapture.VOICE_ONLY || caps.appCalls == AppCallCapture.VOICE_ONLY_NEEDS_HELPER
            val records = if (voiceOnly) "your side of WhatsApp calls" else "both sides of WhatsApp and other app calls"
            if (caps.helperConnected) {
                val arm = facts.armState
                return listOfNotNull(
                    if (facts.helperStale) {
                        ReadyProblem(
                            "helper_old",
                            "The capture helper is from an older version",
                            "Start it again so it records with this version's fixes.",
                            ReadyFix.RESTART_HELPER,
                            blocking = false
                        )
                    } else {
                        null
                    },
                    if (facts.autoRecordOn && arm != null && arm.startsWith("error")) {
                        ReadyProblem(
                            "helper_not_armed",
                            "The capture helper did not get ready",
                            "It answered: ${arm.removePrefix("error: ")}. Start it again.",
                            ReadyFix.RESTART_HELPER,
                            blocking = true,
                            critical = true
                        )
                    } else {
                        null
                    },
                    if (!facts.autoRecordOn) {
                        ReadyProblem(
                            "auto_record_off",
                            "Recording calls automatically is off",
                            "Turn it on to record $records without opening the app.",
                            ReadyFix.TURN_ON,
                            blocking = false
                        )
                    } else {
                        null
                    }
                )
            }
            val problem = when {
                facts.helperEverConnected && facts.shizukuRunning && facts.shizukuAllowed -> ReadyProblem(
                    "helper_stopped",
                    "The capture helper stopped",
                    "Android or the phone's battery saver ended it. Start it again: one tap, no computer needed.",
                    ReadyFix.START_HELPER,
                    blocking = true,
                    critical = critical
                )

                facts.shizukuRunning && !facts.shizukuAllowed -> ReadyProblem(
                    "shizuku_not_allowed",
                    "Shizuku has not allowed EchoLedger",
                    "Allow EchoLedger in Shizuku so it can start the capture helper.",
                    ReadyFix.ALLOW_SHIZUKU,
                    blocking = true,
                    critical = critical && facts.helperEverConnected
                )

                facts.shizukuRunning -> ReadyProblem(
                    "helper_not_started",
                    "The capture helper is not running",
                    "Start it to record $records.",
                    ReadyFix.START_HELPER,
                    blocking = true
                )

                facts.helperEverConnected && facts.shizukuInstalled -> ReadyProblem(
                    "helper_after_restart",
                    "The helper stopped when the phone restarted",
                    "Android turns off Wireless debugging at every restart, which stops Shizuku. Open Shizuku, tap Start, " +
                        "then come back: EchoLedger starts the helper by itself.",
                    ReadyFix.OPEN_SHIZUKU,
                    blocking = true,
                    critical = critical
                )

                facts.helperEverConnected -> ReadyProblem(
                    "helper_after_restart",
                    "The helper stopped when the phone restarted",
                    "Start it again with the command in Setup (the computer method), or set up Shizuku so it takes one tap.",
                    ReadyFix.SET_UP,
                    blocking = true,
                    critical = critical
                )

                else -> ReadyProblem(
                    "helper_not_set_up",
                    "Call capture is not set up yet",
                    "${DeviceCheck.callLine(caps.appCalls) ?: "Set up the capture helper."} The setup takes about 2 minutes.",
                    ReadyFix.SET_UP,
                    blocking = true
                )
            }
            return listOf(problem)
        }
    }
}
