package com.echoledger.mobile.net

import androidx.work.WorkInfo
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.workDataOf
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.DEFAULT_RECORDING_ID
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Phase 1C I2: a shared voice note showed "Upload failed (JobCancellationException)" when its page was left (opening
 * the app replaced the running upload). A stopped upload is not a failure: it stays queued, without a message, and runs
 * again; a running upload is never replaced; no message ever names an exception.
 */
class UploadStoppedTest : AppTest() {
    private val id = DEFAULT_RECORDING_ID

    @Test
    fun `an upload stopped in the middle stays queued and says nothing`() {
        runBlocking { app.pairLocally(server) }
        app.storeRecording(id = id)
        val reached = CountDownLatch(1)
        val release = CountDownLatch(1)
        server.on("POST", "/api/sessions") {
            reached.countDown()
            release.await(10, TimeUnit.SECONDS)
            FakeServer.json(503, """{"message":"late"}""")
        }

        val future = TestListenableWorkerBuilder<UploadWorker>(app).setInputData(workDataOf("recording_id" to id)).build().startWork()
        assertTrue("the upload reached the server", reached.await(10, TimeUnit.SECONDS))
        pumpUntil("the recording to show as uploading") { app.graph.recordings.get(id)?.state == "uploading" }
        future.cancel(true) // what Android does when it stops the work
        release.countDown()
        pumpUntil("the stopped upload to settle") { app.graph.recordings.get(id)?.state != "uploading" }

        val rec = app.graph.recordings.get(id)!!
        assertEquals("queued", rec.state)
        assertNull(rec.lastError)
        assertTrue(app.graph.recordings.encryptedFile(id).exists())
    }

    @Test
    fun `a running upload is never replaced, one waiting out a back-off is`() {
        assertFalse(UploadWorker.mayReplace(listOf(WorkInfo.State.RUNNING)))
        assertTrue(UploadWorker.mayReplace(listOf(WorkInfo.State.ENQUEUED)))
        assertTrue(UploadWorker.mayReplace(listOf(WorkInfo.State.FAILED)))
        assertTrue(UploadWorker.mayReplace(emptyList()))
    }

    @Test
    fun `no upload message names an exception`() {
        listOf(
            kotlinx.coroutines.CancellationException("Job was cancelled"),
            IllegalStateException("boom"),
            java.io.IOException("unexpected end of stream on http://x"),
            java.io.EOFException()
        ).forEach { e ->
            val text = UploadWorker.friendly(e)
            assertFalse(text, text.contains("Exception") || text.contains("unexpected end") || text.contains("("))
        }
    }
}
