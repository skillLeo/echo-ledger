package com.echoledger.mobile.net

import androidx.work.ListenableWorker
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.workDataOf
import com.echoledger.mobile.core.Owner
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.DEFAULT_RECORDING_ID
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** A recording made under another account waits for it (since v1.5, core/Owner): it never uploads into this one. */
class HeldUploadTest : AppTest() {
    private val id = DEFAULT_RECORDING_ID

    @Before
    fun setUp() {
        runBlocking {
            app.pairLocally(server)
            app.graph.settings.update { it.copy(account = Owner.of(server.baseUrl, "omar@example.com")) }
        }
    }

    private fun run(): ListenableWorker.Result {
        val future = TestListenableWorkerBuilder<UploadWorker>(app).setInputData(workDataOf("recording_id" to id)).build().startWork()
        pumpUntil("the upload worker to finish") { future.isDone }
        return future.get()
    }

    @Test
    fun `a recording made under another account sends nothing and says who it waits for`() {
        val ana = Owner.of(server.baseUrl, "ana@example.com")
        app.storeRecording(id = id)
        app.graph.recordings.update(id) { it.copy(owner = ana) }

        assertEquals(ListenableWorker.Result.success(), run())

        assertTrue("nothing reached the server", server.requests.isEmpty())
        val rec = app.graph.recordings.get(id)!!
        assertEquals("queued", rec.state)
        assertEquals(UploadWorker.held(ana), rec.lastError)
        assertTrue(app.graph.recordings.encryptedFile(id).exists())
    }
}
