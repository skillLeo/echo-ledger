package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.R
import com.echoledger.mobile.core.ReadyFix
import com.echoledger.mobile.ui.v2.setup.HealthChecks
import com.echoledger.mobile.ui.v2.setup.ShizukuWizard
import com.echoledger.mobile.ui.v2.setup.StepAction
import com.echoledger.mobile.ui.v2.setup.StepState
import com.echoledger.mobile.ui.v2.setup.V2SetupHealthContent
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** Setup & health: the wizard's current step and every check, right in each state the phone can be in, a restart too. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi", application = Application::class)
class SetupHealthTest {
    @get:Rule
    val compose = createComposeRule()

    private fun current(f: com.echoledger.mobile.ui.v2.setup.SetupFacts) = ShizukuWizard.steps(f).firstOrNull {
        it.state ==
            StepState.Current
    }?.key

    private fun check(f: com.echoledger.mobile.ui.v2.setup.SetupFacts, key: String) = HealthChecks.of(f).first { it.key == key }

    @Test
    fun aNewPhoneStartsAtDeveloperOptions() {
        assertEquals("developer", current(SetupHealthFixtures.fresh))
        val helper = check(SetupHealthFixtures.fresh, "helper")
        assertFalse(helper.ok)
        assertEquals(R.string.v2_setup_fail_helper_not_set_up, helper.title)
        assertEquals(R.string.v2_setup_status_needs, HealthChecks.status(SetupHealthFixtures.fresh))
    }

    @Test
    fun afterARestartWirelessDebuggingComesFirstThenStart() {
        val f = SetupHealthFixtures.afterRestart
        val steps = ShizukuWizard.steps(f)
        // While Shizuku is stopped the phone can't tell whether it is still paired or allows EchoLedger: both unknown.
        assertEquals(
            listOf(StepState.Done, StepState.Done, StepState.Unknown, StepState.Current, StepState.Unknown, StepState.Todo),
            steps.map { it.state }
        )
        val start = steps.first { it.key == "start" }
        assertEquals(R.string.v2_setup_step_start_wireless_first, start.detail)
        assertEquals(StepAction.OpenWireless, start.action)
        val helper = check(f, "helper")
        assertEquals(R.string.v2_setup_fail_helper_after_restart, helper.title)
        assertEquals(ReadyFix.OPEN_SHIZUKU, helper.fix)
        assertEquals(R.string.v2_setup_status_needs, HealthChecks.status(f))
        assertTrue(HealthChecks.of(f).filter { it.key != "helper" }.all { it.ok })
        // With Wireless debugging back on, the step is Open Shizuku.
        assertEquals(
            StepAction.OpenShizuku,
            ShizukuWizard.steps(SetupHealthFixtures.afterRestartWirelessOn).first {
                it.key == "start"
            }.action
        )
    }

    @Test
    fun theComputerMethodAfterARestartGetsItsOwnStep() {
        val steps = ShizukuWizard.steps(SetupHealthFixtures.computerAfterRestart)
        assertEquals(listOf("computer"), steps.map { it.key })
        assertEquals(StepAction.ComputerMethod, steps.single().action)
        assertEquals(ReadyFix.SET_UP, check(SetupHealthFixtures.computerAfterRestart, "helper").fix)
    }

    @Test
    fun aReinstalledShizukuIsNotTakenAsPaired() {
        val steps = ShizukuWizard.steps(SetupHealthFixtures.shizukuReinstalled)
        assertEquals(StepState.Unknown, steps.first { it.key == "pair" }.state)
        val start = steps.first { it.key == "start" }
        assertEquals(StepState.Current, start.state)
        // Its words say to pair again if Shizuku asks.
        assertEquals(R.string.v2_setup_step_start_detail, start.detail)
    }

    @Test
    fun aRemovedPairingIsNotTakenAsPaired() {
        val steps = ShizukuWizard.steps(SetupHealthFixtures.pairingRemoved)
        assertEquals(StepState.Unknown, steps.first { it.key == "pair" }.state)
        assertEquals(R.string.v2_setup_step_start_wireless_first, steps.first { it.key == "start" }.detail)
    }

    @Test
    fun withNoMemoryOfShizukuPairingIsToDoAndCurrent() {
        val pair = ShizukuWizard.steps(SetupHealthFixtures.shizukuNeverUsed).first { it.key == "pair" }
        assertEquals(StepState.Current, pair.state)
    }

    @Test
    fun pairedOnlyWithLiveProof() {
        // Shizuku running is proof; the helper connected through it is proof.
        assertEquals(StepState.Done, ShizukuWizard.steps(SetupHealthFixtures.notAllowed).first { it.key == "pair" }.state)
        assertEquals(StepState.Done, ShizukuWizard.steps(SetupHealthFixtures.allReady).first { it.key == "pair" }.state)
        // Not installed: pairing is still to do, after installing.
        assertEquals(StepState.Todo, ShizukuWizard.steps(SetupHealthFixtures.fresh).first { it.key == "pair" }.state)
    }

    @Test
    fun theStartStepsWordsSayToPairAgainIfAsked() {
        val en = V2Testing.resources(com.echoledger.mobile.ui.v2.theme.EchoLanguage.English)
        assertTrue(en.getString(R.string.v2_setup_step_start_detail).contains("If Shizuku asks to pair, do the pairing step again."))
        assertTrue(
            en.getString(R.string.v2_setup_step_start_wireless_first).contains("If Shizuku asks to pair, do the pairing step again.")
        )
    }

    @Test
    fun developerOptionsFollowAndroidsSwitchNotHistory() {
        val steps = ShizukuWizard.steps(SetupHealthFixtures.developerOff)
        assertEquals(StepState.Current, steps.first { it.key == "developer" }.state)
    }

    @Test
    fun shizukuRunningButNotAllowedAsksToAllow() {
        assertEquals("allow", current(SetupHealthFixtures.notAllowed))
        assertEquals(ReadyFix.ALLOW_SHIZUKU, check(SetupHealthFixtures.notAllowed, "helper").fix)
    }

    @Test
    fun anOlderHelperAsksForARestart() {
        assertEquals("helper", current(SetupHealthFixtures.staleHelper))
        assertEquals(ReadyFix.RESTART_HELPER, check(SetupHealthFixtures.staleHelper, "helper").fix)
    }

    @Test
    fun allReadyHasNoWizardAndEveryCheckPasses() {
        assertEquals(null, current(SetupHealthFixtures.allReady))
        assertTrue(HealthChecks.of(SetupHealthFixtures.allReady).all { it.ok })
        assertEquals(R.string.v2_setup_status_ready, HealthChecks.status(SetupHealthFixtures.allReady))
    }

    @Test
    fun eachMissingPermissionHasItsFix() {
        val f = SetupHealthFixtures.permissionsMissing
        assertEquals(ReadyFix.MICROPHONE, check(f, "microphone").fix)
        assertEquals(ReadyFix.NOTIFICATIONS, check(f, "notifications").fix)
        assertEquals(ReadyFix.BATTERY, check(f, "battery").fix)
        assertEquals(ReadyFix.CALLER_NAMES, check(f, "callers").fix)
        assertEquals(ReadyFix.STORAGE, check(f, "storage").fix)
        assertEquals(120L, check(f, "storage").freeMb)
    }

    @Test
    fun withoutAHelperInTheBuildThereIsNoWizardOrHelperCheck() {
        val keys = HealthChecks.of(SetupHealthFixtures.playBuild).map { it.key }
        assertFalse("helper" in keys)
        assertFalse("battery" in keys)
        assertEquals(R.string.v2_setup_status_voice, HealthChecks.status(SetupHealthFixtures.playBuild))
    }

    @Test
    fun theCurrentStepsButtonRunsItsAction() {
        var ran: StepAction? = null
        var fixed: ReadyFix? = null
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                V2SetupHealthContent(SetupHealthFixtures.afterRestartWirelessOn, null, { ran = it }, { fixed = it }, {}, {})
            }
        }
        compose.onNodeWithText("Start Shizuku").assertExists()
        // Pairing and whether Shizuku allows EchoLedger can't be known while Shizuku is stopped.
        compose.onAllNodesWithText("Known once Shizuku is running").assertCountEquals(2)
        compose.onAllNodesWithText("Open Shizuku")[0].performClick()
        assertEquals(StepAction.OpenShizuku, ran)
        compose.onAllNodesWithText("Open Shizuku")[1].performClick()
        assertEquals(ReadyFix.OPEN_SHIZUKU, fixed)
    }

    @Test
    @Config(qualifiers = "w411dp-h640dp-mdpi")
    fun setUpOnACheckBringsTheWizardIntoView() {
        var fixed: ReadyFix? = null
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                V2SetupHealthContent(SetupHealthFixtures.fresh, null, {}, { fixed = it }, {}, {})
            }
        }
        compose.onNodeWithText("Set up").performScrollTo().performClick()
        compose.onNodeWithText("Turn on Developer options").assertIsDisplayed()
        // Handled here, not passed on as a fix that does nothing.
        assertEquals(null, fixed)
    }

    @Test
    fun setUpAfterARestartWithTheComputerMethodOpensItsStep() {
        var ran: StepAction? = null
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                V2SetupHealthContent(SetupHealthFixtures.computerAfterRestart, null, { ran = it }, {}, {}, {})
            }
        }
        compose.onNodeWithText("Set up").performClick()
        assertEquals(StepAction.ComputerMethod, ran)
        compose.onNodeWithText("Start the helper from the computer again").assertExists()
    }
}
