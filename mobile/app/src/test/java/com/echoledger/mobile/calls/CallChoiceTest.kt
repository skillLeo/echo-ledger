package com.echoledger.mobile.calls

import com.echoledger.mobile.calls.CallChoice.Outcome
import com.echoledger.mobile.calls.CallChoice.Pick
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.overlay.StartBanner
import com.echoledger.mobile.recording.PillEffect
import com.echoledger.mobile.recording.PillEvent
import com.echoledger.mobile.recording.PillMachine
import com.echoledger.mobile.recording.PillState
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** After each call, When to ask, the pill's choice and "Always do this?" (the call-choice plan, 2.0.5). Pure. */
class CallChoiceTest {
    private fun set(value: String, whenToAsk: String = CallChoice.WHEN_AFTER) = AppSettings(afterEachCall = value, whenToAsk = whenToAsk)

    @Test
    fun `Ask me after the call is the default`() {
        assertEquals(CallChoice.ASK, CallChoice.setting(AppSettings()))
        assertEquals(CallChoice.WHEN_AFTER, CallChoice.whenToAsk(AppSettings()))
        assertEquals(Outcome.CARD, CallChoice.outcome(AppSettings(), duringCall = null, quiet = false))
    }

    @Test
    fun `each setting value gives its result`() {
        assertEquals(Outcome.CARD, CallChoice.outcome(set(CallChoice.ASK), null, quiet = false))
        assertEquals(Outcome.TRANSCRIBE, CallChoice.outcome(set(CallChoice.TRANSCRIBE), null, quiet = false))
        assertEquals(Outcome.AUDIO, CallChoice.outcome(set(CallChoice.AUDIO), null, quiet = false))
        assertEquals("pending_decision" to null, CallChoice.stored(Outcome.CARD))
        assertEquals("queued" to true, CallChoice.stored(Outcome.TRANSCRIBE))
        assertEquals("queued" to false, CallChoice.stored(Outcome.AUDIO))
        assertEquals("queued" to false, CallChoice.stored(Outcome.AUDIO_QUIET))
        // Don't record calls: automatic call recording only.
        assertFalse(CallChoice.recordsCallsAutomatically(set(CallChoice.OFF)))
        listOf(CallChoice.ASK, CallChoice.TRANSCRIBE, CallChoice.AUDIO).forEach {
            assertTrue(it, CallChoice.recordsCallsAutomatically(set(it)))
        }
        assertEquals("an unknown value reads as Ask me", CallChoice.ASK, CallChoice.setting(AppSettings(afterEachCall = "later")))
    }

    @Test
    fun `a choice on the pill wins over every setting, so nothing asks after the call`() {
        CallChoice.SETTINGS.forEach { value ->
            assertEquals(value, Outcome.TRANSCRIBE, CallChoice.outcome(set(value), Pick.TRANSCRIBE, quiet = true))
            assertEquals(value, Outcome.AUDIO, CallChoice.outcome(set(value), Pick.AUDIO, quiet = false))
        }
    }

    @Test
    fun `quiet hours or Do Not Disturb save the audio only with no card, in Ask me`() {
        assertEquals(Outcome.AUDIO_QUIET, CallChoice.outcome(set(CallChoice.ASK), null, quiet = true))
        assertEquals("no question anyway", Outcome.TRANSCRIBE, CallChoice.outcome(set(CallChoice.TRANSCRIBE), null, quiet = true))
        val night = AppSettings(quietHours = true, quietFrom = "22:00", quietTo = "07:00")
        assertTrue(CallChoice.inQuietHours(night, 23 * 60))
        assertTrue(CallChoice.inQuietHours(night, 6 * 60 + 59))
        assertFalse(CallChoice.inQuietHours(night, 12 * 60))
        assertFalse(CallChoice.inQuietHours(night.copy(quietHours = false), 23 * 60))
    }

    @Test
    fun `asked at the start, the call is kept as audio only with no card after it, unless the banner never showed`() {
        val start = set(CallChoice.ASK, CallChoice.WHEN_START)
        assertTrue(CallChoice.asksAtStart(start))
        assertEquals(Outcome.AUDIO, CallChoice.outcome(start, null, quiet = false, bannerShown = true))
        // No banner (no overlay permission, a call brought in from the Phone app): asked with the card after all.
        assertEquals(Outcome.CARD, CallChoice.outcome(start, null, quiet = false, bannerShown = false))
        assertFalse("When to ask belongs to Ask me", CallChoice.asksAtStart(set(CallChoice.AUDIO, CallChoice.WHEN_START)))
    }

    @Test
    fun `the start banner shows once a call has connected, never while it rings`() {
        val start = set(CallChoice.ASK, CallChoice.WHEN_START)
        assertFalse("ringing", StartBanner.due(start, fromCall = true, connected = false, shown = false))
        assertTrue("connected", StartBanner.due(start, fromCall = true, connected = true, shown = false))
        assertFalse("once", StartBanner.due(start, fromCall = true, connected = true, shown = true))
        assertFalse("a recording by hand", StartBanner.due(start, fromCall = false, connected = true, shown = false))
        assertFalse("after the call", StartBanner.due(set(CallChoice.ASK), fromCall = true, connected = true, shown = false))
    }

    @Test
    fun `the old After a call becomes Ask me, the default, from every old value`() {
        assertEquals(CallChoice.ASK, CallChoice.fromOld(PostCallPrompt.ASK))
        assertEquals("Keep automatically, the old default", CallChoice.ASK, CallChoice.fromOld(PostCallPrompt.KEEP))
        assertEquals(CallChoice.ASK, CallChoice.fromOld(null))
        // Safe: doing nothing on the card keeps the audio.
        assertEquals(
            CallChoice.Outcome.CARD,
            CallChoice.outcome(AppSettings(afterEachCall = CallChoice.fromOld(PostCallPrompt.KEEP)), null, false)
        )
    }

    @Test
    fun `three identical choices in a row offer Always do this once, and its Yes sets the setting`() {
        var s = AppSettings()
        s = CallChoice.afterPick(s, Pick.AUDIO)
        s = CallChoice.afterPick(s, Pick.TRANSCRIBE)
        s = CallChoice.afterPick(s, Pick.TRANSCRIBE)
        assertNull("two in a row", CallChoice.alwaysOffer(s))
        s = CallChoice.afterPick(s, Pick.TRANSCRIBE)
        assertEquals(Pick.TRANSCRIBE, CallChoice.alwaysOffer(s))
        assertEquals(CallChoice.TRANSCRIBE, CallChoice.settingFor(Pick.TRANSCRIBE))
        assertEquals(CallChoice.AUDIO, CallChoice.settingFor(Pick.AUDIO))
        assertNull("once", CallChoice.alwaysOffer(s.copy(callChoiceAlwaysOffered = true)))
        assertNull("only while it asks", CallChoice.alwaysOffer(s.copy(afterEachCall = CallChoice.AUDIO)))

        var deleted = AppSettings()
        repeat(4) { deleted = CallChoice.afterPick(deleted, Pick.DELETE) }
        assertNull("deleting calls is no reason to stop recording them", CallChoice.alwaysOffer(deleted))
    }

    @Test
    fun `a call's pill offers the choices, and choosing closes the menu and is kept for the call's end`() {
        val open = PillState(mode = PillState.Mode.MENU, call = true)
        val (next, effects) = PillMachine.reduce(open, PillEvent.Choose(Pick.TRANSCRIBE))
        assertEquals(PillState.Mode.PILL, next.mode)
        assertEquals(Pick.TRANSCRIBE.wire, next.choice)
        assertEquals(listOf(PillEffect.Chose(Pick.TRANSCRIBE)), effects)

        val audio = PillMachine.reduce(next, PillEvent.Choose(Pick.AUDIO))
        assertEquals("the latest choice counts", Pick.AUDIO.wire, audio.first.choice)

        assertEquals(
            "a voice note has no call choice",
            emptyList<PillEffect>(),
            PillMachine.reduce(PillState(), PillEvent.Choose(Pick.AUDIO)).second
        )
        // Stop and delete is the pill's Discard: paused at once, deleted when its Undo is over.
        val (deleting, pause) = PillMachine.reduce(open, PillEvent.DiscardNow(1_000))
        assertTrue(deleting.discarding)
        assertEquals(listOf(PillEffect.Pause), pause)
    }
}
