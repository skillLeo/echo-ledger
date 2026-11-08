package com.echoledger.mobile.ui

import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.test.core.app.ApplicationProvider
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** E1 (Phase 1C): a recording whose file has not reported its length yet still shows its real length, never 0:00 / 0:00. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class RecordingPlayerLengthTest {
    @get:Rule
    val compose = createComposeRule()

    @Test
    fun `the length the library knows is shown until the player reports its own`() {
        val state = RecordingPlayerState(ApplicationProvider.getApplicationContext())
        state.knownMs = 125_000
        assertEquals(125_000L, state.totalMs)
        compose.setContent { EchoLedgerTheme("light") { RecordingPlayer(state) } }

        compose.onNodeWithText("0:00 / 2:05").assertExists()
        compose.runOnIdle { state.release() }
    }

    @Test
    fun `an unknown length stays 0 rather than a made-up one`() {
        val state = RecordingPlayerState(ApplicationProvider.getApplicationContext())
        assertEquals(0L, state.totalMs)
        state.release()
    }
}
