package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.recording.PillEffect
import com.echoledger.mobile.recording.PillEvent
import com.echoledger.mobile.recording.PillMachine
import com.echoledger.mobile.recording.PillState
import com.echoledger.mobile.ui.v2.live.V2RecordingPill
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The new design's recording pill does all that classic's does, through the real PillMachine (parity.csv PH-OTH-032):
 * tap for the controls; Captions, Pause and Resume, the language, Discard with Undo, Stop on a second tap, Shrink to a
 * dot (a tap opens the controls again), Hide controls, and Hide EchoLedger windows.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class V2PillParityTest {
    @get:Rule
    val compose = createComposeRule()

    private var state by mutableStateOf(PillState(elapsedMs = 134_000, language = "UR"))
    private val effects = mutableListOf<PillEffect>()

    @After
    fun showWindows() = ShareGuard.setHidden(false)

    private fun show() = compose.setContent {
        EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
            V2RecordingPill(state, nowMs = 1_000, title = "Omar Khan", onEvent = { e ->
                val (next, more) = PillMachine.reduce(state, e)
                state = next
                effects += more
            })
        }
    }

    private fun press(words: String) {
        compose.onNodeWithText(words).performClick()
        compose.waitForIdle()
    }

    private fun openControls() {
        compose.onNodeWithContentDescription("Recording 02:14. Tap for the controls.").performClick()
        compose.waitForIdle()
        assertEquals(PillState.Mode.MENU, state.mode)
    }

    @Test
    fun theControlsCaptionPauseChangeTheLanguageAndStopOnTheSecondTap() {
        show()
        openControls()
        press("Captions")
        assertTrue(state.captions)
        compose.onNodeWithText("Captions off").assertExists()
        press("Pause")
        assertTrue(state.paused)
        compose.onNodeWithText("Paused · 02:14").assertExists()
        press("Resume")
        assertTrue(!state.paused)
        press("Language: UR")
        press("Stop")
        compose.onNodeWithText("Tap again to stop").assertExists()
        assertTrue(PillEffect.Stop !in effects)
        press("Tap again to stop")
        assertEquals(
            listOf(PillEffect.ShowCaptions, PillEffect.Pause, PillEffect.Resume, PillEffect.NextLanguage, PillEffect.Stop),
            effects
        )
        assertEquals(PillState.Mode.HIDDEN, state.mode)
    }

    @Test
    fun discardCountsDownWithUndo() {
        show()
        openControls()
        press("Discard")
        assertTrue(state.discarding)
        compose.onNodeWithText("Undo", substring = true).performClick()
        compose.waitForIdle()
        assertTrue(!state.discarding)
    }

    @Test
    fun shrinkToADotThenATapOpensTheControlsAgain() {
        show()
        openControls()
        press("Shrink to a dot")
        assertEquals(PillState.Mode.DOT, state.mode)
        compose.onAllNodesWithText("Hide controls").fetchSemanticsNodes().let { assertTrue(it.isEmpty()) }
        openControls()
    }

    @Test
    fun hideControlsLeavesNothingAndHideWindowsTurnsEveryWindowIntoADot() {
        show()
        openControls()
        press("Hide EchoLedger windows")
        assertTrue("the windows are hidden", ShareGuard.hiddenByUser.value)
        assertTrue("and their words with them", !ShareGuard.textVisible.value)
        assertEquals(PillState.Mode.PILL, state.mode)
        openControls()
        press("Hide controls")
        assertEquals(PillState.Mode.HIDDEN, state.mode)
        compose.onAllNodesWithText("02:14", substring = true).fetchSemanticsNodes().let { assertTrue(it.isEmpty()) }
    }
}
