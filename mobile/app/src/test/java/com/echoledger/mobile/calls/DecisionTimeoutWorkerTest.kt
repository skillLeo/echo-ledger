package com.echoledger.mobile.calls

import androidx.work.ListenableWorker
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.workDataOf
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.peekRecording
import com.echoledger.mobile.testsupport.storeRecording
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Test

/** "After a call: Ask me" (since v1.5): nobody choosing keeps the call after 10 s; keep-automatically is the default. */
class DecisionTimeoutWorkerTest : AppTest() {
    private fun runWorker(id: String): ListenableWorker.Result = runBlocking {
        TestListenableWorkerBuilder<DecisionTimeoutWorker>(app)
            .setInputData(workDataOf(DecisionTimeoutWorker.KEY_ID to id))
            .build()
            .doWork()
    }

    @Test
    fun `a call nobody decided on is kept when the ten seconds are up`() {
        app.storeRecording(id = "call-1", state = "pending_decision")

        assertEquals(ListenableWorker.Result.success(), runWorker("call-1"))
        assertEquals("queued", app.peekRecording("call-1")?.state)
        assertEquals(10L, DecisionTimeoutWorker.WINDOW_SECONDS)
    }

    @Test
    fun `a recording the user already decided on is left alone`() {
        app.storeRecording(id = "call-1", state = "uploaded")

        runWorker("call-1")

        assertEquals("uploaded", app.peekRecording("call-1")?.state)
    }

    @Test
    fun `calls are kept automatically by default, also with settings saved before v1_5`() {
        assertEquals(PostCallPrompt.KEEP, AppSettings().afterCall)
        assertEquals(10, AppSettings().skipCallsShorterThanS)
        val old = ApiJson.decodeFromString(AppSettings.serializer(), """{"decisionTimeout":"wait","decisionTimeoutChosen":true}""")
        assertEquals(PostCallPrompt.KEEP, old.afterCall)
        assertEquals("queued", PostCallPrompt.stateFor(PostCallPrompt.KEEP))
        assertEquals("pending_decision", PostCallPrompt.stateFor(PostCallPrompt.ASK))
    }
}
