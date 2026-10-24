package com.echoledger.mobile.calls

import com.echoledger.mobile.core.SourceApp
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class CallStateMachineTest {
    private val app = SourceApp.WHATSAPP

    @Test
    fun `a started call begins capture and moves to InCall`() {
        val (state, effects) = CallStateMachine.reduce(CallState.Idle, CallEvent.CallStarted(app, "r1", 0))
        assertTrue(state is CallState.InCall)
        assertEquals(listOf(CallEffect.StartCapture(app, "r1")), effects)
    }

    @Test
    fun `a second start while already recording is ignored`() {
        val inCall = CallState.InCall(app, "r1", 0)
        val (state, effects) = CallStateMachine.reduce(inCall, CallEvent.CallStarted(app, "r2", 10))
        assertEquals(inCall, state)
        assertTrue(effects.isEmpty())
    }

    @Test
    fun `a call longer than the minimum asks the user to save after it ends`() {
        val inCall = CallState.InCall(app, "r1", 0)
        val (state, effects) = CallStateMachine.reduce(inCall, CallEvent.CallEnded(CallStateMachine.MIN_DURATION_MS + 1))
        assertTrue(state is CallState.AwaitingDecision)
        assertEquals(
            listOf(CallEffect.StopCaptureAndAsk("r1", app, CallStateMachine.MIN_DURATION_MS + 1)),
            effects
        )
    }

    @Test
    fun `a call shorter than the minimum is discarded silently`() {
        val inCall = CallState.InCall(app, "r1", 0)
        val (state, effects) = CallStateMachine.reduce(inCall, CallEvent.CallEnded(CallStateMachine.MIN_DURATION_MS - 1))
        assertEquals(CallState.Idle, state)
        assertEquals(listOf(CallEffect.Discard("r1")), effects)
    }

    @Test
    fun `keep then discard decisions emit the matching effect and return to idle`() {
        val awaiting = CallState.AwaitingDecision(app, "r1", 5_000)
        val (keepState, keepEffects) = CallStateMachine.reduce(awaiting, CallEvent.Decided("r1", keep = true))
        assertEquals(CallState.Idle, keepState)
        assertEquals(listOf(CallEffect.Keep("r1")), keepEffects)

        val (_, discardEffects) = CallStateMachine.reduce(awaiting, CallEvent.Decided("r1", keep = false))
        assertEquals(listOf(CallEffect.Discard("r1")), discardEffects)
    }

    @Test
    fun `a decision for an older recording is still honoured`() {
        val (state, effects) = CallStateMachine.reduce(CallState.Idle, CallEvent.Decided("old", keep = true))
        assertEquals(CallState.Idle, state)
        assertEquals(listOf(CallEffect.Keep("old")), effects)
    }

    @Test
    fun `a call that ends while idle does nothing`() {
        val (state, effects) = CallStateMachine.reduce(CallState.Idle, CallEvent.CallEnded(1_000))
        assertEquals(CallState.Idle, state)
        assertTrue(effects.isEmpty())
    }
}
