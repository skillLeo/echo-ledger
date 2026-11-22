package com.echoledger.mobile.ui.v2

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.getBoundsInRoot
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.calls.CallChoice.Pick
import com.echoledger.mobile.overlay.PillToolbar
import com.echoledger.mobile.recording.PillEvent
import com.echoledger.mobile.recording.PillState
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.ui.v2.live.V2RecordingPill
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * A call's recording pill (the call-choice plan, 2.0.5): its menu starts with Transcribe this call, Save audio only and
 * Stop and delete (which is the pill's Discard), and a voice note's menu has none of them.
 */
class CallPillMenuTest : ScreenTest() {
    private val events = mutableListOf<PillEvent>()

    @Test
    fun `a call's menu starts with its three choices, and they say what happens`() {
        show { EchoV2Theme { V2RecordingPill(PillState(mode = PillState.Mode.MENU, call = true), 0, "Omar", { events += it }) } }
        val transcribe = compose.onNodeWithText("Transcribe this call")
        val captions = compose.onNodeWithText("Captions", substring = true).getBoundsInRoot()
        assertTrue("first in the menu", transcribe.getBoundsInRoot().top < captions.top)
        transcribe.performClick()
        compose.onNodeWithText("Save audio only").performClick()
        compose.onNodeWithText("Stop and delete").performClick()
        assertEquals(PillEvent.Choose(Pick.TRANSCRIBE), events[0])
        assertEquals(PillEvent.Choose(Pick.AUDIO), events[1])
        assertTrue(events[2] is PillEvent.DiscardNow)
        // Stop and delete takes Discard's place, so the menu never has both.
        compose.onAllNodesWithText("Discard").assertCountEquals(0)
    }

    @Test
    fun `a voice note's menu has no call choices`() {
        show { EchoV2Theme { V2RecordingPill(PillState(mode = PillState.Mode.MENU), 0, null, { events += it }) } }
        listOf("Transcribe this call", "Save audio only", "Stop and delete").forEach { compose.onAllNodesWithText(it).assertCountEquals(0) }
        compose.onNodeWithText("Discard").performClick()
        assertTrue(events.single() is PillEvent.DiscardNow)
    }

    @Test
    fun `the classic pill offers the same three choices on a call`() {
        show { PillToolbar(PillState(mode = PillState.Mode.MENU, call = true), onEvent = { events += it }) }
        compose.onNodeWithText("Transcribe this call").performClick()
        compose.onNodeWithText("Save audio only").performClick()
        compose.onNodeWithText("Stop and delete").performClick()
        assertEquals(listOf<PillEvent>(PillEvent.Choose(Pick.TRANSCRIBE), PillEvent.Choose(Pick.AUDIO)), events.take(2))
        assertTrue(events[2] is PillEvent.DiscardNow)
    }
}
