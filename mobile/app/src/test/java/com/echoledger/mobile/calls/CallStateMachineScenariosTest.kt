package com.echoledger.mobile.calls

import com.echoledger.mobile.core.SourceApp
import kotlin.random.Random
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** Whole call lifecycles and invariants of the save-after-call state machine. */
class CallStateMachineScenariosTest {
    private val whatsapp = SourceApp.WHATSAPP
    private val business = SourceApp.WHATSAPP_BUSINESS

    private fun run(vararg events: CallEvent): Pair<CallState, List<CallEffect>> {
        var state: CallState = CallState.Idle
        val effects = mutableListOf<CallEffect>()
        events.forEach {
            val (next, out) = CallStateMachine.reduce(state, it)
            state = next
            effects += out
        }
        return state to effects
    }

    @Test
    fun `the minimum duration boundary is inclusive of exactly three seconds`() {
        val (shortState, shortEffects) = run(CallEvent.CallStarted(whatsapp, "r", 1_000), CallEvent.CallEnded(1_000 + 2_999))
        assertEquals(CallState.Idle, shortState)
        assertEquals(listOf(CallEffect.StartCapture(whatsapp, "r"), CallEffect.Discard("r")), shortEffects)

        val (longState, longEffects) = run(CallEvent.CallStarted(whatsapp, "r", 1_000), CallEvent.CallEnded(1_000 + 3_000))
        assertEquals(CallState.AwaitingDecision(whatsapp, "r", 3_000), longState)
        assertEquals(listOf(CallEffect.StartCapture(whatsapp, "r"), CallEffect.StopCaptureAndAsk("r", whatsapp, 3_000)), longEffects)
    }

    @Test
    fun `a call that ends at the instant it started or before is discarded`() {
        assertEquals(CallEffect.Discard("r"), run(CallEvent.CallStarted(whatsapp, "r", 5_000), CallEvent.CallEnded(5_000)).second.last())
        // The wall clock went backwards (manual change or NTP step): duration is clamped to zero, never negative.
        val (state, effects) = run(CallEvent.CallStarted(whatsapp, "r", 5_000), CallEvent.CallEnded(1_000))
        assertEquals(CallState.Idle, state)
        assertEquals(CallEffect.Discard("r"), effects.last())
    }

    @Test
    fun `a full call that is kept goes idle to in-call to awaiting to idle with one keep`() {
        val (state, effects) = run(
            CallEvent.CallStarted(business, "r1", 0),
            CallEvent.CallEnded(125_000),
            CallEvent.Decided("r1", keep = true)
        )
        assertEquals(CallState.Idle, state)
        assertEquals(
            listOf(
                CallEffect.StartCapture(business, "r1"),
                CallEffect.StopCaptureAndAsk("r1", business, 125_000),
                CallEffect.Keep("r1")
            ),
            effects
        )
    }

    @Test
    fun `the question is never asked before the call has ended`() {
        val (_, effects) = run(CallEvent.CallStarted(whatsapp, "r1", 0))
        assertTrue(effects.none { it is CallEffect.StopCaptureAndAsk })
        val (_, whileInCall) = CallStateMachine.reduce(CallState.InCall(whatsapp, "r1", 0), CallEvent.CallStarted(whatsapp, "r2", 60_000))
        assertTrue(whileInCall.isEmpty())
    }

    @Test
    fun `an end event repeated after the call was handled changes nothing`() {
        val (state, effects) = run(
            CallEvent.CallStarted(whatsapp, "r1", 0),
            CallEvent.CallEnded(10_000),
            CallEvent.CallEnded(11_000),
            CallEvent.CallEnded(12_000)
        )
        assertEquals(CallState.AwaitingDecision(whatsapp, "r1", 10_000), state)
        assertEquals(1, effects.count { it is CallEffect.StopCaptureAndAsk })
    }

    @Test
    fun `a new call starting while the previous one awaits a decision begins capturing at once`() {
        val (state, effects) = run(
            CallEvent.CallStarted(whatsapp, "r1", 0),
            CallEvent.CallEnded(10_000),
            CallEvent.CallStarted(whatsapp, "r2", 20_000)
        )
        assertEquals(CallState.InCall(whatsapp, "r2", 20_000), state)
        assertEquals(CallEffect.StartCapture(whatsapp, "r2"), effects.last())
        // The first recording is still waiting for its answer; deciding it later is honoured.
        val (_, late) = CallStateMachine.reduce(state, CallEvent.Decided("r1", keep = false))
        assertEquals(listOf(CallEffect.Discard("r1")), late)
    }

    @Test
    fun `a decision for another recording does not disturb the call in progress`() {
        val inCall = CallState.InCall(whatsapp, "live", 0)
        val (state, effects) = CallStateMachine.reduce(inCall, CallEvent.Decided("old", keep = true))
        assertEquals(inCall, state)
        assertEquals(listOf(CallEffect.Keep("old")), effects)
    }

    @Test
    fun `a decision for a different id while awaiting leaves the awaited recording pending`() {
        val awaiting = CallState.AwaitingDecision(whatsapp, "r1", 5_000)
        val (state, effects) = CallStateMachine.reduce(awaiting, CallEvent.Decided("other", keep = false))
        assertEquals(awaiting, state)
        assertEquals(listOf(CallEffect.Discard("other")), effects)
    }

    @Test
    fun `random event streams never ask for a decision on a short call or start capture twice`() {
        val random = Random(42)
        repeat(2_000) {
            var state: CallState = CallState.Idle
            var clock = 0L
            var activeCaptures = 0
            repeat(random.nextInt(1, 30)) { step ->
                clock += random.nextLong(0, 10_000)
                val event = when (random.nextInt(3)) {
                    0 -> CallEvent.CallStarted(if (random.nextBoolean()) whatsapp else business, "r$step", clock)
                    1 -> CallEvent.CallEnded(clock)
                    else -> CallEvent.Decided("r${random.nextInt(step + 1)}", random.nextBoolean())
                }
                val (next, effects) = CallStateMachine.reduce(state, event)
                effects.forEach { effect ->
                    when (effect) {
                        is CallEffect.StartCapture -> {
                            assertFalse("capture started while a call was already recording", state is CallState.InCall)
                            activeCaptures++
                        }

                        is CallEffect.StopCaptureAndAsk -> {
                            assertTrue("asked about a ${effect.durationMs} ms call", effect.durationMs >= CallStateMachine.MIN_DURATION_MS)
                            assertTrue(state is CallState.InCall)
                            activeCaptures--
                        }

                        is CallEffect.Discard -> if (state is CallState.InCall && event is CallEvent.CallEnded) activeCaptures--

                        is CallEffect.Keep -> Unit
                    }
                }
                if (next is CallState.AwaitingDecision) assertTrue(next.durationMs >= CallStateMachine.MIN_DURATION_MS)
                assertTrue("more than one capture at a time", activeCaptures in 0..1)
                state = next
            }
        }
    }
}
