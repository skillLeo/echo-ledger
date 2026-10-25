package com.echoledger.mobile.widget

import org.junit.Assert.assertEquals
import org.junit.Test

class WidgetStateTest {
    @Test
    fun `unpaired state asks the user to open the app`() {
        assertEquals("Open EchoLedger to pair", WidgetState(paired = false).statusLine)
    }

    @Test
    fun `recording takes priority over pending reviews and armed`() {
        val state = WidgetState(paired = true, recording = true, armed = true, pendingDecisions = 2)
        assertEquals("Recording…", state.statusLine)
        assertEquals("Stop", state.buttonLabel)
    }

    @Test
    fun `pending decisions are surfaced before the armed hint`() {
        val state = WidgetState(paired = true, armed = true, pendingDecisions = 3)
        assertEquals("3 to review", state.statusLine)
    }

    @Test
    fun `armed and idle states have their own lines`() {
        assertEquals("Ready to record calls", WidgetState(paired = true, armed = true).statusLine)
        assertEquals("Tap to record", WidgetState(paired = true).statusLine)
        assertEquals("Record", WidgetState(paired = true).buttonLabel)
    }
}
