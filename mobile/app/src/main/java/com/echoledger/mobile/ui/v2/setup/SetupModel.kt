package com.echoledger.mobile.ui.v2.setup

import com.echoledger.mobile.R
import com.echoledger.mobile.core.Readiness
import com.echoledger.mobile.core.ReadyFacts
import com.echoledger.mobile.core.ReadyFix
import com.echoledger.mobile.privilege.HelperMethod

/**
 * What Setup & health looks at: the Ready check's facts (read live on the phone by ReadinessWatch, never cached, so
 * they are right after a restart), and two of Android's own switches read live.
 */
data class SetupFacts(val ready: ReadyFacts, val developerOptions: Boolean, val wirelessDebugging: Boolean) {
    /** How the helper was last started on this phone, as the Ready check has it; null when it never ran here. */
    val method: HelperMethod? = Readiness.evaluate(ready).helperMethod

    val callsPossible: Boolean get() = ready.capabilities.helperInBuild && ready.capabilities.callCaptureSupportedByOs

    /** The helper is running and current: nothing to set up. */
    val helperReady: Boolean get() = ready.capabilities.helperConnected && !ready.helperStale
}

/** Where a wizard step stands. [Unknown]: the phone can't tell yet (Shizuku's permission, while Shizuku is stopped). */
enum class StepState { Done, Current, Todo, Unknown }

/** What a wizard step's button does. */
enum class StepAction { OpenSettings, GetShizuku, OpenWireless, OpenShizuku, AllowShizuku, StartHelper, ComputerMethod }

/** One step of the wizard, with its words. */
data class WizardStep(val key: String, val title: Int, val detail: Int, val action: StepAction, val actionLabel: Int, val state: StepState)

/**
 * The Android capture helper's wizard. Every step is decided from what the phone shows now, never from history alone:
 * Developer options from Android's own switch; Shizuku installed from the package; paired only with live proof (Shizuku
 * runs, or the helper is connected through it now); unknown while Shizuku is stopped after being used here, since a
 * reinstall, cleared data or a removed pairing all need pairing again; to do when it was never used here; started while Shizuku runs; allowed only while Shizuku can answer
 * (else unknown); the helper while it runs. The start step says to pair again if Shizuku asks, and after a restart,
 * when Wireless debugging is off, to turn it on first. A phone set up with the computer method gets that method's one
 * step instead.
 */
object ShizukuWizard {
    private class Def(val key: String, val title: Int, val detail: Int, val action: StepAction, val label: Int)

    fun steps(f: SetupFacts): List<WizardStep> {
        val r = f.ready
        val helper = r.capabilities.helperConnected
        if (f.method == HelperMethod.Computer && !helper) {
            return listOf(
                WizardStep(
                    "computer",
                    R.string.v2_setup_step_computer,
                    R.string.v2_setup_step_computer_detail,
                    StepAction.ComputerMethod,
                    R.string.v2_setup_action_show_command,
                    StepState.Current
                )
            )
        }
        val viaShizuku = helper && f.method == HelperMethod.Shizuku
        // true with live proof; null (unknown) while Shizuku is stopped but was used here (the pairing may be gone);
        // false (to do) when Shizuku isn't installed or this phone has no memory of it being used.
        val paired: Boolean? = when {
            r.shizukuRunning || viaShizuku -> true
            r.shizukuInstalled && f.method == HelperMethod.Shizuku -> null
            else -> false
        }
        val started = r.shizukuRunning || viaShizuku
        val startDef = if (!started && !f.wirelessDebugging) {
            Def(
                "start",
                R.string.v2_setup_step_start,
                R.string.v2_setup_step_start_wireless_first,
                StepAction.OpenWireless,
                R.string.v2_setup_action_open_wireless
            )
        } else {
            Def(
                "start",
                R.string.v2_setup_step_start,
                R.string.v2_setup_step_start_detail,
                StepAction.OpenShizuku,
                R.string.v2_setup_fix_open_shizuku
            )
        }
        val defs = listOf(
            Def(
                "developer",
                R.string.v2_setup_step_developer,
                R.string.v2_setup_step_developer_detail,
                StepAction.OpenSettings,
                R.string.v2_setup_action_open_settings
            ),
            Def(
                "install",
                R.string.v2_setup_step_install,
                R.string.v2_setup_step_install_detail,
                StepAction.GetShizuku,
                R.string.v2_setup_action_get_shizuku
            ),
            Def(
                "pair",
                R.string.v2_setup_step_pair,
                R.string.v2_setup_step_pair_detail,
                StepAction.OpenWireless,
                R.string.v2_setup_action_open_wireless
            ),
            startDef,
            Def(
                "allow",
                R.string.v2_setup_step_allow,
                R.string.v2_setup_step_allow_detail,
                StepAction.AllowShizuku,
                R.string.v2_setup_fix_allow
            ),
            Def(
                "helper",
                R.string.v2_setup_step_helper,
                R.string.v2_setup_step_helper_detail,
                StepAction.StartHelper,
                R.string.v2_setup_fix_start
            )
        )
        // null: unknown for now.
        val done: List<Boolean?> = listOf(
            f.developerOptions || helper,
            r.shizukuInstalled || viaShizuku,
            paired,
            started,
            when {
                viaShizuku -> true
                r.shizukuRunning -> r.shizukuAllowed
                else -> null
            },
            f.helperReady
        )
        val current = done.indexOfFirst { it == false }
        return defs.mapIndexed { i, d ->
            val state = when {
                done[i] == true -> StepState.Done
                done[i] == null -> StepState.Unknown
                i == current -> StepState.Current
                else -> StepState.Todo
            }
            WizardStep(d.key, d.title, d.detail, d.action, d.label, state)
        }
    }
}

/** One health check: passing or not, its words, and the fix the Ready check offers. */
data class HealthCheck(val key: String, val ok: Boolean, val title: Int, val detail: Int?, val fix: ReadyFix?, val freeMb: Long? = null)

/**
 * The health checks of Setup & health, from the Ready check itself ([Readiness.evaluate]), so this screen, the Ready
 * chip and the alert never disagree. Every check is listed, passing ones too; the capture helper's says which of its
 * problems it has (stopped, not allowed, stopped by a restart…).
 */
object HealthChecks {
    private val helperWords = mapOf(
        "helper_stopped" to (R.string.v2_setup_fail_helper_stopped to R.string.v2_setup_why_helper_stopped),
        "shizuku_not_allowed" to (R.string.v2_setup_fail_shizuku_not_allowed to R.string.v2_setup_why_shizuku_not_allowed),
        "helper_not_started" to (R.string.v2_setup_fail_helper_not_started to R.string.v2_setup_why_helper_not_started),
        "helper_after_restart" to (R.string.v2_setup_fail_helper_after_restart to R.string.v2_setup_why_helper_after_restart),
        "helper_not_set_up" to (R.string.v2_setup_fail_helper_not_set_up to R.string.v2_setup_why_helper_not_set_up),
        "helper_old" to (R.string.v2_setup_fail_helper_old to R.string.v2_setup_why_helper_old),
        "helper_not_armed" to (R.string.v2_setup_fail_helper_not_armed to R.string.v2_setup_why_helper_not_armed)
    )

    fun of(f: SetupFacts): List<HealthCheck> {
        val r = f.ready
        val readiness = Readiness.evaluate(r)
        val byKey = readiness.problems.associateBy { it.key }
        fun check(key: String, problemKey: String, ok: Int, fail: Int, why: Int) =
            byKey[problemKey].let { p -> HealthCheck(key, p == null, if (p == null) ok else fail, if (p == null) null else why, p?.fix) }
        return buildList {
            add(
                check(
                    "microphone",
                    "microphone",
                    R.string.v2_setup_ok_microphone,
                    R.string.v2_setup_fail_microphone,
                    R.string.v2_setup_why_microphone
                )
            )
            if (f.callsPossible) {
                val helperProblem = readiness.problems.firstOrNull { it.key in helperWords }
                add(
                    if (helperProblem == null) {
                        HealthCheck("helper", true, R.string.v2_setup_ok_helper, null, null)
                    } else {
                        val (fail, why) = helperWords.getValue(helperProblem.key)
                        HealthCheck("helper", false, fail, why, helperProblem.fix)
                    }
                )
                add(check("auto", "auto_record_off", R.string.v2_setup_ok_auto, R.string.v2_setup_fail_auto, R.string.v2_setup_why_auto))
                add(
                    check("battery", "battery", R.string.v2_setup_ok_battery, R.string.v2_setup_fail_battery, R.string.v2_setup_why_battery)
                )
                add(
                    check(
                        "callers",
                        "caller_names",
                        R.string.v2_setup_ok_callers,
                        R.string.v2_setup_fail_callers,
                        R.string.v2_setup_why_callers
                    )
                )
            }
            add(
                check(
                    "notifications",
                    "notifications",
                    R.string.v2_setup_ok_notifications,
                    R.string.v2_setup_fail_notifications,
                    R.string.v2_setup_why_notifications
                )
            )
            add(
                check(
                    "storage",
                    "storage",
                    R.string.v2_setup_ok_storage,
                    R.string.v2_setup_fail_storage,
                    R.string.v2_setup_why_storage
                ).copy(freeMb = r.freeStorageMb)
            )
            add(check("signin", "sign_in", R.string.v2_setup_ok_signin, R.string.v2_setup_fail_signin, R.string.v2_setup_why_signin))
            add(
                check(
                    "last_call",
                    "one_sided",
                    R.string.v2_setup_ok_last_call,
                    R.string.v2_setup_fail_last_call,
                    R.string.v2_setup_why_last_call
                )
            )
        }
    }

    /** The headline: ready, off, voice notes only, or needs setup (the Ready chip's meaning). */
    fun status(f: SetupFacts): Int = when (Readiness.evaluate(f.ready).chip) {
        com.echoledger.mobile.core.ReadyChip.READY -> R.string.v2_setup_status_ready
        com.echoledger.mobile.core.ReadyChip.OFF -> R.string.v2_setup_status_off
        com.echoledger.mobile.core.ReadyChip.NONE -> R.string.v2_setup_status_voice
        com.echoledger.mobile.core.ReadyChip.NOT_READY -> R.string.v2_setup_status_needs
    }

    /** A fix's button words. */
    fun fixLabel(fix: ReadyFix): Int = when (fix) {
        ReadyFix.START_HELPER -> R.string.v2_setup_fix_start

        ReadyFix.OPEN_SHIZUKU -> R.string.v2_setup_fix_open_shizuku

        ReadyFix.ALLOW_SHIZUKU, ReadyFix.MICROPHONE, ReadyFix.NOTIFICATIONS, ReadyFix.BATTERY,
        ReadyFix.CALLER_NAMES -> R.string.v2_setup_fix_allow

        ReadyFix.SET_UP -> R.string.v2_setup_fix_set_up

        ReadyFix.RESTART_HELPER -> R.string.v2_setup_fix_restart

        ReadyFix.TURN_ON -> R.string.v2_setup_fix_turn_on

        ReadyFix.SIGN_IN -> R.string.v2_setup_fix_sign_in

        ReadyFix.STORAGE -> R.string.v2_setup_fix_storage

        ReadyFix.TEST -> R.string.v2_setup_fix_test
    }
}
