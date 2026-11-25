package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.assertHeightIsAtLeast
import androidx.compose.ui.test.click
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.unit.dp
import androidx.test.core.app.ApplicationProvider
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** P-033: the player's scrubber and speed chip are 48 dp targets (they were a 20 dp band and a 32 dp chip). */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class RecordingPlayerTargetsTest {
    @get:Rule
    val compose = createComposeRule()

    @Test
    fun `the scrubber and the speed chip are each at least 48 dp`() {
        val state = RecordingPlayerState(ApplicationProvider.getApplicationContext())
        state.knownMs = 100_000
        compose.setContent { EchoLedgerTheme("light") { RecordingPlayer(state) } }

        compose.onNodeWithContentDescription("Position").assertHeightIsAtLeast(48.dp)
        // The chip's own semantics are its 32 dp clickable; what it lays out is the space a touch reaches it in.
        val chip = compose.onNodeWithContentDescription("Playback speed 1×").fetchSemanticsNode().layoutInfo
        with(chip.density) {
            assertTrue("chip height ${chip.height.toDp()}", chip.height.toDp() >= 48.dp)
            assertTrue("chip width ${chip.width.toDp()}", chip.width.toDp() >= 48.dp)
        }
        compose.runOnIdle { state.release() }
    }

    @Test
    fun `a touch 20 dp above the track still seeks`() {
        val state = RecordingPlayerState(ApplicationProvider.getApplicationContext())
        state.knownMs = 100_000
        compose.setContent {
            EchoLedgerTheme("light") { Scrubber(state, enabled = true, modifier = Modifier.fillMaxWidth()) }
        }
        compose.onNodeWithContentDescription("Position").performTouchInput {
            click(center.copy(x = width * 0.5f, y = centerY - 20.dp.toPx()))
        }
        compose.runOnIdle {
            assertTrue("seeked to ${state.positionMs}", state.positionMs in 45_000..55_000)
            state.release()
        }
    }
}
