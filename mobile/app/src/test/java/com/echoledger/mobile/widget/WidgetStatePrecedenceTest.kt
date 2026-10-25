package com.echoledger.mobile.widget

import org.junit.Assert.assertEquals
import org.junit.Test

/** The widget status line and button for every combination of its flags. */
class WidgetStatePrecedenceTest {
    @Test
    fun `every combination of flags resolves to exactly one status in priority order`() {
        for (mask in 0 until 16) {
            val recording = mask and 1 != 0
            val armed = mask and 2 != 0
            val paired = mask and 4 != 0
            val pending = if (mask and 8 != 0) 2 else 0
            val state = WidgetState(recording = recording, armed = armed, paired = paired, pendingDecisions = pending)

            val expected = when {
                !paired -> "Open EchoLedger to pair"
                recording -> "Recording…"
                pending > 0 -> "2 to review"
                armed -> "Ready to record calls"
                else -> "Tap to record"
            }
            assertEquals("mask $mask", expected, state.statusLine)
            assertEquals("mask $mask", if (recording) "Stop" else "Record", state.buttonLabel)
        }
    }

    @Test
    fun `the review count is shown as given including large numbers`() {
        assertEquals("1 to review", WidgetState(paired = true, pendingDecisions = 1).statusLine)
        assertEquals("250 to review", WidgetState(paired = true, pendingDecisions = 250).statusLine)
    }

    @Test
    fun `a negative pending count is treated as nothing to review`() {
        assertEquals("Tap to record", WidgetState(paired = true, pendingDecisions = -1).statusLine)
    }

    @Test
    fun `an unpaired widget never shows recording controls text`() {
        assertEquals(
            "Open EchoLedger to pair",
            WidgetState(paired = false, recording = true, armed = true, pendingDecisions = 5).statusLine
        )
    }

    @Test
    fun `the last recording title and preview are optional`() {
        val state = WidgetState(paired = true, lastTitle = null, lastPreview = null)
        assertEquals(null, state.lastTitle)
        assertEquals(WidgetState(paired = true), state)
    }
}
