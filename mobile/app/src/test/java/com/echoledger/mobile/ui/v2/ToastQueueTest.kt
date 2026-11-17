package com.echoledger.mobile.ui.v2

import com.echoledger.mobile.ui.v2.components.EchoToastResult
import com.echoledger.mobile.ui.v2.components.EchoToastState
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Test

/** The toast line: a toast with an end time comes first and is never shown after it. */
class ToastQueueTest {
    private var now = 1_000L
    private val toasts = EchoToastState { now }

    @Test
    fun aToastWithAnEndTimeGoesBeforeTheOnesWaiting() = runTest {
        backgroundScope.launch { toasts.show("Restored") }
        backgroundScope.launch { toasts.show("Renamed") }
        runCurrent()
        assertEquals("Restored", toasts.current?.message)
        backgroundScope.launch { toasts.show("Call discarded", "Undo", deadlineMs = now + 5_000) }
        runCurrent()
        // The toast on screen made way, and the discard went before "Renamed", which was already waiting.
        assertEquals("Call discarded", toasts.current?.message)
        toasts.current?.dismiss()
        runCurrent()
        // The toast that made way shows again, for its full time, before the ones that were waiting.
        assertEquals("Restored", toasts.current?.message)
        toasts.current?.dismiss()
        runCurrent()
        assertEquals("Renamed", toasts.current?.message)
    }

    @Test
    fun aToastWhoseEndTimeHasPassedIsNotShown() = runTest {
        backgroundScope.launch { toasts.show("Call discarded", "Undo", deadlineMs = now + 5_000) }
        runCurrent()
        // Another discard toast with an earlier end time waits first in line; by its turn its time is over.
        val late = async { toasts.show("Older discard", "Undo", deadlineMs = now + 1_000) }
        runCurrent()
        now += 2_000
        toasts.current?.dismiss()
        runCurrent()
        assertEquals(EchoToastResult.Dismissed, late.await())
        assertEquals(null, toasts.current)
    }

    @Test
    fun theTurnPassesToTheEndTimeToastEvenWhenTheOtherResumesAtOnce() = runTest {
        // A toast whose coroutine resumes in place (as on the UI dispatcher) must not take its turn back.
        backgroundScope.launch(Dispatchers.Unconfined) { toasts.show("Restored") }
        assertEquals("Restored", toasts.current?.message)
        backgroundScope.launch(Dispatchers.Unconfined) { toasts.show("Call discarded", "Undo", deadlineMs = now + 5_000) }
        assertEquals("Call discarded", toasts.current?.message)
        toasts.current?.dismiss()
        assertEquals("Restored", toasts.current?.message)
    }
}
