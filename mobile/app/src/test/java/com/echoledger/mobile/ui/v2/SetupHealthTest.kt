package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
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
    fun afterARestartOnlyStartShizukuIsLeft() {
        val f = SetupHealthFixtures.afterRestart
        val steps = ShizukuWizard.steps(f)
        // Pairing survives the restart: developer options, Shizuku and pairing stay done.
        assertEquals(listOf(StepState.Done, StepState.Done, StepState.Done, StepState.Current), steps.take(4).map { it.state })
        assertEquals("start", current(f))
        val helper = check(f, "helper")
        assertEquals(R.string.v2_setup_fail_helper_after_restart, helper.title)
        assertEquals(ReadyFix.OPEN_SHIZUKU, helper.fix)
        assertEquals(R.string.v2_setup_status_needs, HealthChecks.status(f))
        // Nothing else is reported broken by the restart.
        assertTrue(HealthChecks.of(f).filter { it.key != "helper" }.all { it.ok })
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
                V2SetupHealthContent(SetupHealthFixtures.afterRestart, null, { ran = it }, { fixed = it }, {}, {})
            }
        }
        compose.onNodeWithText("Start Shizuku").assertExists()
        compose.onAllNodesWithText("Open Shizuku")[0].performClick()
        assertEquals(StepAction.OpenShizuku, ran)
        compose.onAllNodesWithText("Open Shizuku")[1].performClick()
        assertEquals(ReadyFix.OPEN_SHIZUKU, fixed)
    }
}
