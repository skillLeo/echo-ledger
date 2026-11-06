package com.echoledger.mobile.core

import com.echoledger.mobile.privilege.AppCallCapture
import com.echoledger.mobile.privilege.Capabilities

/** How far one thing works on this phone; the words go to the server as they are (docs/API.md). */
enum class CheckState(val wire: String, val word: String) {
    OK("ok", "Works"),
    LIMITED("limited", "Limited"),
    OFF("off", "Off")
}

/** The one-tap fix of a row. */
enum class CheckFix(val label: String) {
    MICROPHONE("Allow"),
    OVERLAY("Allow"),
    HELPER("Set up"),
    BATTERY("Allow")
}

data class CheckRow(val key: String, val title: String, val state: CheckState, val detail: String, val fix: CheckFix? = null)

/** What the phone says about itself; read once, so the rows below are pure. */
data class PhoneFacts(
    val manufacturer: String,
    val brand: String,
    val model: String,
    val release: String,
    val appVersion: String,
    val capabilities: Capabilities,
    val batteryExempt: Boolean,
    val hasPhoneLine: Boolean,
    val phoneCallsInBuild: Boolean,
    val signedIn: Boolean,
    val liveTranslateInPlan: Boolean,
    val memoryGb: Int
)

/**
 * The device check (since v1.5, plan Phase 1B): what works on this phone and why, in the same words on the Record tab,
 * in onboarding and in Help & diagnostics. It says what the app really does on this Android version: during a call
 * Android gives ordinary apps no microphone sound, so without the capture helper an app call records nothing at all,
 * and on Android 12 and 13 the helper records your voice only.
 */
object DeviceCheck {
    private const val NO_SOUND = "Without it Android gives other apps no sound during a call."
    private const val ONLY_YOUR_VOICE =
        "This phone can record only your voice in WhatsApp calls: hearing the other side needs Android 14 or newer."

    /** Said up front wherever calls are offered; null when calls record both sides. */
    fun callLine(capture: AppCallCapture): String? = when (capture) {
        AppCallCapture.BOTH_SIDES -> null

        AppCallCapture.NEEDS_HELPER -> "Set up the capture helper to record both sides of WhatsApp and other app calls. $NO_SOUND"

        AppCallCapture.VOICE_ONLY -> ONLY_YOUR_VOICE

        AppCallCapture.VOICE_ONLY_NEEDS_HELPER ->
            "With the capture helper this phone can record only your voice in WhatsApp calls: the other side needs Android 14 or newer. $NO_SOUND"

        AppCallCapture.NONE ->
            "This phone cannot record WhatsApp calls: its Android version gives other apps no sound during a call. Voice notes work."

        AppCallCapture.NOT_IN_THIS_BUILD -> Capabilities.NOT_IN_THIS_BUILD
    }

    fun rows(facts: PhoneFacts): List<CheckRow> = listOf(
        voiceNotes(facts),
        appCalls(facts),
        simCalls(facts),
        captions(facts),
        liveTranslate(facts),
        background(facts),
        CheckRow("memory", "Memory", CheckState.OK, "${facts.memoryGb} GB")
    )

    private fun voiceNotes(facts: PhoneFacts) = if (facts.capabilities.micPermission) {
        CheckRow("voice_notes", "Voice notes and import", CheckState.OK, "Voice notes and file imports work.")
    } else {
        CheckRow(
            "voice_notes",
            "Voice notes and import",
            CheckState.LIMITED,
            "Importing files works. Allow the microphone to record voice notes.",
            CheckFix.MICROPHONE
        )
    }

    private fun appCalls(facts: PhoneFacts): CheckRow {
        val capture = facts.capabilities.appCalls
        val line = callLine(capture)
        return when (capture) {
            AppCallCapture.BOTH_SIDES ->
                CheckRow("app_calls", "App calls", CheckState.OK, "WhatsApp, Meet and other app calls record both sides.")

            AppCallCapture.VOICE_ONLY -> CheckRow("app_calls", "App calls", CheckState.LIMITED, line.orEmpty())

            AppCallCapture.NEEDS_HELPER, AppCallCapture.VOICE_ONLY_NEEDS_HELPER ->
                CheckRow("app_calls", "App calls", CheckState.OFF, line.orEmpty(), CheckFix.HELPER)

            AppCallCapture.NONE, AppCallCapture.NOT_IN_THIS_BUILD -> CheckRow("app_calls", "App calls", CheckState.OFF, line.orEmpty())
        }
    }

    private fun simCalls(facts: PhoneFacts): CheckRow {
        val caps = facts.capabilities
        return when {
            !facts.hasPhoneLine -> CheckRow("sim_calls", "SIM calls", CheckState.OFF, "This device has no phone line.")

            !facts.phoneCallsInBuild || !caps.helperInBuild -> CheckRow(
                "sim_calls",
                "SIM calls",
                CheckState.OFF,
                Capabilities.NOT_IN_THIS_BUILD
            )

            caps.appCalls == AppCallCapture.NONE -> CheckRow(
                "sim_calls",
                "SIM calls",
                CheckState.OFF,
                "Recording SIM calls needs Android 12 or newer."
            )

            !caps.helperConnected ->
                CheckRow("sim_calls", "SIM calls", CheckState.OFF, "SIM calls record through the capture helper.", CheckFix.HELPER)

            else -> CheckRow(
                "sim_calls",
                "SIM calls",
                CheckState.LIMITED,
                "Turn it on under Calls & meetings. Some phone makers block the phone line: if so, the app says it after 8 seconds."
            )
        }
    }

    private fun captions(facts: PhoneFacts) = when {
        !facts.signedIn -> CheckRow("captions", "Live captions", CheckState.OFF, "Live captions work once you are signed in.")

        !facts.capabilities.micPermission ->
            CheckRow(
                "captions",
                "Live captions",
                CheckState.LIMITED,
                "Allow the microphone to see captions of what you say.",
                CheckFix.MICROPHONE
            )

        else -> CheckRow("captions", "Live captions", CheckState.OK, "Captions show while you record; they need the internet.")
    }

    private fun liveTranslate(facts: PhoneFacts): CheckRow {
        val caps = facts.capabilities
        return when {
            !caps.helperInBuild -> CheckRow("live_translate", "Live Translate", CheckState.OFF, Capabilities.NOT_IN_THIS_BUILD)

            !facts.liveTranslateInPlan -> CheckRow("live_translate", "Live Translate", CheckState.OFF, PlanState.NO_LIVE_TRANSLATE)

            !caps.overlayPermission ->
                CheckRow(
                    "live_translate",
                    "Live Translate",
                    CheckState.LIMITED,
                    "Needs Display over other apps for its box.",
                    CheckFix.OVERLAY
                )

            caps.helperConnected && caps.sdkInt >= Capabilities.MIN_CALL_CAPTURE_SDK ->
                CheckRow("live_translate", "Live Translate", CheckState.OK, "Films, apps and game voice chat.")

            caps.sdkInt >= Capabilities.MIN_CALL_CAPTURE_SDK -> CheckRow(
                "live_translate",
                "Live Translate",
                CheckState.LIMITED,
                "Films and apps work, and Android asks each time. The capture helper adds game voice chat.",
                CheckFix.HELPER
            )

            else -> CheckRow(
                "live_translate",
                "Live Translate",
                CheckState.LIMITED,
                "Films and apps work, and Android asks each time. Game voice chat needs Android 14."
            )
        }
    }

    private fun background(facts: PhoneFacts): CheckRow {
        val brand = PhoneBrand.of(facts.manufacturer, facts.brand)
        return if (facts.batteryExempt) {
            CheckRow(
                "background",
                "Staying ready",
                CheckState.OK,
                "Android lets EchoLedger run in the background. Steps for ${brand.label} are below."
            )
        } else {
            CheckRow(
                "background",
                "Staying ready",
                CheckState.LIMITED,
                "Android may stop EchoLedger in the background, and auto-record with it. Allow it, then follow the steps for ${brand.label} below.",
                CheckFix.BATTERY
            )
        }
    }

    /** The text of Copy report: the phone and the rows, nothing about the person. */
    fun report(facts: PhoneFacts, rows: List<CheckRow>): String = buildString {
        appendLine("EchoLedger device check")
        appendLine("Phone: ${facts.brand} ${facts.model} · Android ${facts.release} (API ${facts.capabilities.sdkInt})")
        appendLine("App: ${facts.appVersion} (${if (facts.capabilities.helperInBuild) "website" else "Google Play"} build)")
        rows.forEach { appendLine("${it.state.word}: ${it.title}. ${it.detail}") }
    }.trimEnd()
}
