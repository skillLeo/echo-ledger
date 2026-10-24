package com.echoledger.mobile.calls

import com.echoledger.mobile.core.SourceApp

/**
 * Save-after-call state machine (pure, unit-tested). Recording starts when a WhatsApp call is detected and
 * the "Save this recording?" decision is requested only AFTER the call ends — never before or during it.
 */
sealed interface CallState {
    data object Idle : CallState

    data class InCall(val app: SourceApp, val recordingId: String, val startedAtMs: Long) : CallState

    data class AwaitingDecision(val app: SourceApp, val recordingId: String, val durationMs: Long) : CallState
}

sealed interface CallEvent {
    data class CallStarted(val app: SourceApp, val recordingId: String, val atMs: Long) : CallEvent

    data class CallEnded(val atMs: Long) : CallEvent

    data class Decided(val recordingId: String, val keep: Boolean) : CallEvent
}

sealed interface CallEffect {
    data class StartCapture(val app: SourceApp, val recordingId: String) : CallEffect

    data class StopCaptureAndAsk(val recordingId: String, val app: SourceApp, val durationMs: Long) : CallEffect

    data class Keep(val recordingId: String) : CallEffect

    data class Discard(val recordingId: String) : CallEffect
}

object CallStateMachine {
    /** Calls shorter than this are discarded silently (missed / rejected calls). */
    const val MIN_DURATION_MS = 3_000L

    fun reduce(state: CallState, event: CallEvent): Pair<CallState, List<CallEffect>> = when (event) {
        is CallEvent.CallStarted -> when (state) {
            is CallState.InCall -> state to emptyList()

            // already recording this call
            else -> CallState.InCall(event.app, event.recordingId, event.atMs) to
                listOf(CallEffect.StartCapture(event.app, event.recordingId))
        }

        is CallEvent.CallEnded -> when (state) {
            is CallState.InCall -> {
                val duration = (event.atMs - state.startedAtMs).coerceAtLeast(0)
                if (duration < MIN_DURATION_MS) {
                    CallState.Idle to listOf(CallEffect.Discard(state.recordingId))
                } else {
                    CallState.AwaitingDecision(state.app, state.recordingId, duration) to
                        listOf(CallEffect.StopCaptureAndAsk(state.recordingId, state.app, duration))
                }
            }

            else -> state to emptyList()
        }

        is CallEvent.Decided -> when {
            state is CallState.AwaitingDecision && state.recordingId == event.recordingId ->
                CallState.Idle to listOf(if (event.keep) CallEffect.Keep(event.recordingId) else CallEffect.Discard(event.recordingId))

            // Decisions for older recordings (from the pending list) are still honoured.
            else -> state to listOf(if (event.keep) CallEffect.Keep(event.recordingId) else CallEffect.Discard(event.recordingId))
        }
    }
}
