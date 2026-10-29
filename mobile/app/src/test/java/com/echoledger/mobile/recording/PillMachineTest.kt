package com.echoledger.mobile.recording

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class PillMachineTest {
    private fun run(start: PillState, vararg events: PillEvent): Pair<PillState, List<PillEffect>> {
        var state = start
        val effects = mutableListOf<PillEffect>()
        for (event in events) {
            val (next, out) = PillMachine.reduce(state, event)
            state = next
            effects += out
        }
        return state to effects
    }

    @Test
    fun `a tap opens the controls, another or a tap outside closes them`() {
        val (open, _) = run(PillState(), PillEvent.Tap)
        assertEquals(PillState.Mode.MENU, open.mode)
        assertEquals(PillState.Mode.PILL, run(open, PillEvent.Tap).first.mode)
        assertEquals(PillState.Mode.PILL, run(open, PillEvent.CloseMenu).first.mode)
        assertEquals(PillState.Mode.MENU, run(PillState(mode = PillState.Mode.DOT), PillEvent.Tap).first.mode)
    }

    @Test
    fun `hiding the controls or closing captions never stops the recording`() {
        val (hidden, effects) = run(PillState(captions = true), PillEvent.HideControls, PillEvent.CaptionsClosed)
        assertEquals(PillState.Mode.HIDDEN, hidden.mode)
        assertFalse(hidden.captions)
        assertTrue(effects.isEmpty())
        assertEquals(PillState.Mode.HIDDEN, run(hidden, PillEvent.Tap).first.mode)
        assertEquals(PillState.Mode.PILL, run(hidden, PillEvent.ShowControls).first.mode)
    }

    @Test
    fun `pause, resume and captions ask the recorder`() {
        val (paused, first) = run(PillState(), PillEvent.TogglePause)
        assertTrue(paused.paused)
        assertEquals(listOf(PillEffect.Pause), first)
        assertEquals(listOf(PillEffect.Resume), run(paused, PillEvent.TogglePause).second)
        assertEquals(
            listOf(PillEffect.ShowCaptions, PillEffect.HideCaptions),
            run(PillState(), PillEvent.ToggleCaptions, PillEvent.ToggleCaptions).second
        )
        assertEquals(listOf(PillEffect.NextLanguage), run(PillState(), PillEvent.NextLanguage).second)
    }

    @Test
    fun `discard now pauses at once and deletes only after the 5-second undo`() {
        val (discarding, effects) = run(PillState(mode = PillState.Mode.MENU), PillEvent.DiscardNow(1_000))
        assertEquals(listOf(PillEffect.Pause), effects)
        assertTrue(discarding.discarding)
        assertEquals(PillState.Mode.PILL, discarding.mode)
        assertEquals(5, discarding.undoSeconds(1_000))
        assertEquals(1, discarding.undoSeconds(5_500))
        assertTrue(run(discarding, PillEvent.Tick(10_000, 5_999)).second.isEmpty())
        val (gone, last) = run(discarding, PillEvent.Tick(10_000, 6_000))
        assertEquals(listOf(PillEffect.Discard), last)
        assertEquals(PillState.Mode.HIDDEN, gone.mode)
        assertNull(gone.discardAtMs)
    }

    @Test
    fun `undo puts things back as they were, paused or not`() {
        val (running, resumed) = run(PillState(), PillEvent.DiscardNow(0), PillEvent.Undo)
        assertFalse(running.discarding)
        assertFalse(running.paused)
        assertEquals(listOf(PillEffect.Pause, PillEffect.Resume), resumed)
        val (stillPaused, effects) = run(PillState(paused = true), PillEvent.DiscardNow(0), PillEvent.Undo)
        assertTrue(stillPaused.paused)
        assertTrue(effects.isEmpty())
        assertTrue(run(stillPaused, PillEvent.Tick(1, 60_000)).second.isEmpty())
    }

    @Test
    fun `while a discard is pending, stop, pause and the language wait`() {
        val discarding = run(PillState(), PillEvent.DiscardNow(0)).first
        assertTrue(
            run(discarding, PillEvent.Stop, PillEvent.TogglePause, PillEvent.NextLanguage, PillEvent.DiscardNow(10)).second.isEmpty()
        )
        assertTrue(run(discarding, PillEvent.Paused(false)).first.paused)
    }

    @Test
    fun `stop asks for the after-call card and hides the pill`() {
        val (stopped, effects) = run(PillState(mode = PillState.Mode.MENU), PillEvent.Stop)
        assertEquals(listOf(PillEffect.Stop), effects)
        assertEquals(PillState.Mode.HIDDEN, stopped.mode)
    }

    @Test
    fun `the clock and the snap to the nearer edge`() {
        assertEquals("02:14", PillMachine.clock(134_000))
        assertEquals("1:02:03", PillMachine.clock(3_723_000))
        assertEquals("00:00", PillMachine.clock(-5))
        assertEquals(Triple("right", 1440 - 200 - 16, 300), PillMachine.snap(900, 300, 200, 1440, 100, 1400, 16))
        assertEquals(Triple("left", 16, 100), PillMachine.snap(300, 20, 200, 1440, 100, 1400, 16))
        assertEquals(Triple("left", 16, 1400), PillMachine.snap(10, 3000, 200, 1440, 100, 1400, 16))
    }
}
