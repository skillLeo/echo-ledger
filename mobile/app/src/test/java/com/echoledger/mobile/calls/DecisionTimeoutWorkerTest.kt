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

/** Ask me with no card (the notification's backstop): nobody choosing keeps the audio only after 10 s (since 2.0.5). */
class DecisionTimeoutWorkerTest : AppTest() {
    private fun runWorker(id: String): ListenableWorker.Result = runBlocking {
        TestListenableWorkerBuilder<DecisionTimeoutWorker>(app)
            .setInputData(workDataOf(DecisionTimeoutWorker.KEY_ID to id))
            .build()
            .doWork()
    }

    @Test
    fun `a call nobody decided on is kept as audio only when the ten seconds are up`() {
        app.storeRecording(id = "call-1", state = "pending_decision")

        assertEquals(ListenableWorker.Result.success(), runWorker("call-1"))
        assertEquals("queued", app.peekRecording("call-1")?.state)
        assertEquals("no transcript nobody asked for", false, app.peekRecording("call-1")?.transcribe)
        assertEquals(10L, DecisionTimeoutWorker.WINDOW_SECONDS)
    }

    @Test
    fun `a recording the user already decided on is left alone`() {
        app.storeRecording(id = "call-1", state = "uploaded")

        runWorker("call-1")

        assertEquals("uploaded", app.peekRecording("call-1")?.state)
    }

    @Test
    fun `Ask me is the default, and settings from before v1_5 still read`() {
        assertEquals(CallChoice.ASK, AppSettings().afterEachCall)
        assertEquals(10, AppSettings().skipCallsShorterThanS)
        val old = ApiJson.decodeFromString(AppSettings.serializer(), """{"decisionTimeout":"wait","decisionTimeoutChosen":true}""")
        assertEquals(PostCallPrompt.KEEP, old.afterCall)
    }
}
