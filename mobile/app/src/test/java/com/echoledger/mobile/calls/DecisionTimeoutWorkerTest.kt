package com.echoledger.mobile.calls

import androidx.work.ListenableWorker
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.workDataOf
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.peekRecording
import com.echoledger.mobile.testsupport.storeRecording
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** "If I don't choose within 60 s" on the phone: the same three choices as the desktop, applied when time is up. */
class DecisionTimeoutWorkerTest : AppTest() {
    private fun setTimeout(value: String) = runBlocking {
        (app as EchoLedgerApp).graph.settings.update { it.copy(decisionTimeout = value) }
    }

    private fun runWorker(id: String): ListenableWorker.Result = runBlocking {
        TestListenableWorkerBuilder<DecisionTimeoutWorker>(app)
            .setInputData(workDataOf(DecisionTimeoutWorker.KEY_ID to id))
            .build()
            .doWork()
    }

    @Test
    fun `keep is the default and queues the recording for upload`() {
        app.storeRecording(id = "call-1", state = "pending_decision")

        assertEquals(ListenableWorker.Result.success(), runWorker("call-1"))
        assertEquals("queued", app.peekRecording("call-1")?.state)
    }

    @Test
    fun `discard deletes the recording`() {
        setTimeout("discard")
        app.storeRecording(id = "call-1", state = "pending_decision")

        runWorker("call-1")

        assertNull(app.peekRecording("call-1"))
    }

    @Test
    fun `keep waiting leaves the recording waiting`() {
        setTimeout("wait")
        app.storeRecording(id = "call-1", state = "pending_decision")

        runWorker("call-1")

        assertEquals("pending_decision", app.peekRecording("call-1")?.state)
    }

    @Test
    fun `a recording the user already decided on is left alone`() {
        setTimeout("discard")
        app.storeRecording(id = "call-1", state = "queued")

        runWorker("call-1")

        assertEquals("queued", app.peekRecording("call-1")?.state)
    }

    @Test
    fun `the policy in force when time is up applies, and unknown values never lose a call`() {
        assertEquals(true, DecisionTimeoutWorker.timeoutDecision("keep"))
        assertEquals(false, DecisionTimeoutWorker.timeoutDecision("discard"))
        assertNull(DecisionTimeoutWorker.timeoutDecision("wait"))
        assertEquals(true, DecisionTimeoutWorker.timeoutDecision("something else"))
    }
}
