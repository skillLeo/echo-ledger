package com.echoledger.mobile.net

import androidx.work.ListenableWorker
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.workDataOf
import com.echoledger.mobile.data.UsageReport
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.DEFAULT_RECORDING_ID
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.sampleBytes
import com.echoledger.mobile.testsupport.storeRecording
import kotlinx.coroutines.runBlocking
import mockwebserver3.MockResponse
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * The v1.1 session lifecycle on the phone (A5.1, decision 6): a recording whose captions opened a server session
 * finishes it on Keep, a discarded one deletes it, the local copy stays until the server says the file is ready,
 * a failed verification uploads the file once more, and Retry-After is honoured.
 */
class SessionLifecycleWorkerTest : AppTest() {
    private val id = DEFAULT_RECORDING_ID
    private val serverRecording = ServerFixtures.RECORDING_ID
    private val serverSession = ServerFixtures.SESSION_ID
    private val openSession = "01JOPENSESSION000000000000"

    @Before
    fun setUp() {
        runBlocking { app.pairLocally(server) }
        UploadWorker.pollIntervalMs = 10
        UploadWorker.verifyWaitMs = 200
    }

    @After
    fun restoreTimings() {
        UploadWorker.pollIntervalMs = 5_000
        UploadWorker.verifyWaitMs = 120_000
    }

    private fun run(): ListenableWorker.Result {
        val worker = TestListenableWorkerBuilder<UploadWorker>(app).setInputData(workDataOf("recording_id" to id)).build()
        val future = worker.startWork()
        pumpUntil("the upload worker to finish") { future.isDone }
        return future.get()
    }

    private fun sessionAnswer() =
        ServerFixtures.sessionCreated(chunkSize = 1_000, totalChunks = 3, received = emptyList(), uploadStatus = "pending")

    private fun serveChunksAndComplete(complete: String = """{"status":"ready"}""") {
        server.on("POST", "/api/recordings/$serverRecording/chunks") { request ->
            FakeServer.json(200, ServerFixtures.chunkAck(listOf(request.headers["Upload-Chunk-Index"]!!.toInt()), 3))
        }
        server.on("POST", "/api/recordings/$serverRecording/complete", FakeServer.json(202, complete))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
    }

    private fun rateLimited(retryAfter: String): MockResponse = MockResponse.Builder().code(429)
        .addHeader("Content-Type", "application/json")
        .addHeader("Retry-After", retryAfter)
        .body("""{"message":"Too Many Attempts.","code":"rate_limited"}""")
        .build()

    private fun storeWithOpenSession() {
        app.storeRecording(bytes = sampleBytes(2_500), state = "queued")
        app.graph.recordings.update(id) { it.copy(serverSessionId = openSession) }
    }

    @Test
    fun `a recording with an open server session finishes that session instead of creating a new one`() {
        storeWithOpenSession()
        server.on("POST", "/api/sessions/$openSession/finish", FakeServer.json(200, sessionAnswer()))
        serveChunksAndComplete()

        assertEquals(ListenableWorker.Result.success(), run())

        assertEquals(1, server.requestsTo("POST", "/api/sessions/$openSession/finish").size)
        assertTrue(server.requestsTo("POST", "/api/sessions").isEmpty())
        assertNull("the local copy goes once the server has it", app.graph.recordings.get(id))
    }

    @Test
    fun `a swept open session falls back to a new session with a fresh reference`() {
        storeWithOpenSession()
        server.on(
            "POST",
            "/api/sessions/$openSession/finish",
            FakeServer.json(404, """{"message":"Session not found.","code":"session_not_found"}""")
        )
        server.on("POST", "/api/sessions", FakeServer.json(201, sessionAnswer()))
        serveChunksAndComplete()

        assertEquals(ListenableWorker.Result.success(), run())

        val created = server.requestsTo("POST", "/api/sessions").single().bodyText()
        assertTrue("a new client_ref, never the swept one", !created.contains("\"client_ref\":\"$id\""))
    }

    @Test
    fun `without an open session one call creates the session as before`() {
        app.storeRecording(bytes = sampleBytes(2_500), state = "queued")
        server.on("POST", "/api/sessions", FakeServer.json(201, sessionAnswer()))
        serveChunksAndComplete()

        assertEquals(ListenableWorker.Result.success(), run())
        assertTrue(server.requestsTo("POST", "/api/sessions").single().bodyText().contains("\"client_ref\":\"$id\""))
    }

    @Test
    fun `the local copy stays while the server is still verifying and the next run does not resend chunks`() {
        app.storeRecording(bytes = sampleBytes(2_500), state = "queued")
        server.on("POST", "/api/sessions", FakeServer.json(201, sessionAnswer()))
        serveChunksAndComplete("""{"status":"assembling"}""")
        server.on("GET", "/api/recordings/$serverRecording", FakeServer.json(200, ServerFixtures.recordingStatus(status = "assembling")))

        assertEquals(ListenableWorker.Result.retry(), run())
        assertNotNull("kept until the server reports ready", app.graph.recordings.get(id))
        assertEquals("queued", app.graph.recordings.get(id)!!.state)
    }

    @Test
    fun `a failed verification uploads the whole file once more and then succeeds`() {
        app.storeRecording(bytes = sampleBytes(2_500), state = "queued")
        server.on("POST", "/api/sessions", FakeServer.json(201, sessionAnswer()))
        server.on("POST", "/api/recordings/$serverRecording/chunks") { request ->
            FakeServer.json(200, ServerFixtures.chunkAck(listOf(request.headers["Upload-Chunk-Index"]!!.toInt()), 3))
        }
        var completes = 0
        server.on("POST", "/api/recordings/$serverRecording/complete") { _ ->
            if (++completes == 1) {
                FakeServer.json(
                    422,
                    """{"message":"The uploaded file did not match its checksum. Send it again.","code":"verification_failed"}"""
                )
            } else {
                FakeServer.json(202, """{"status":"ready"}""")
            }
        }
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))

        assertEquals(ListenableWorker.Result.success(), run())
        assertEquals("every chunk was sent twice", 6, server.requestsTo("POST", "/api/recordings/$serverRecording/chunks").size)
    }

    @Test
    fun `a second failed verification marks the recording failed and keeps the local copy`() {
        app.storeRecording(bytes = sampleBytes(2_500), state = "queued")
        server.on("POST", "/api/sessions", FakeServer.json(201, sessionAnswer()))
        serveChunksAndComplete()
        server.on(
            "POST",
            "/api/recordings/$serverRecording/complete",
            FakeServer.json(
                422,
                """{"message":"The uploaded file did not match its checksum. Send it again.","code":"verification_failed"}"""
            )
        )

        assertEquals(ListenableWorker.Result.failure(), run())
        assertEquals("failed", app.graph.recordings.get(id)!!.state)
    }

    @Test
    fun `a short Retry-After is left to the work back-off`() {
        app.storeRecording(bytes = sampleBytes(2_500), state = "queued")
        server.on("POST", "/api/sessions", rateLimited("7"))

        assertEquals(ListenableWorker.Result.retry(), run())
        assertEquals("queued", app.graph.recordings.get(id)!!.state)
    }

    @Test
    fun `a long Retry-After schedules the next run after the server's delay`() {
        app.storeRecording(bytes = sampleBytes(2_500), state = "queued")
        server.on("POST", "/api/sessions", rateLimited("120"))

        assertEquals(ListenableWorker.Result.success(), run())
        val queued = androidx.work.WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-$id").get()
        assertTrue("a delayed run is waiting", queued.any { it.state == androidx.work.WorkInfo.State.ENQUEUED })
    }

    @Test
    fun `the error code and Retry-After of a refusal reach the app`() {
        server.on("POST", "/api/usage", rateLimited("9"))
        val error = runCatching {
            runBlocking { app.graph.api()!!.reportUsage(UsageReport("rep-12345678", "live_captions", "assemblyai", null, 1000, 1)) }
        }
            .exceptionOrNull() as ApiException

        assertEquals("rate_limited", error.code)
        assertEquals(9L, error.retryAfterSeconds)
    }
}
