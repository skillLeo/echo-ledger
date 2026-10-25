package com.echoledger.mobile.net

import androidx.work.ListenableWorker
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.workDataOf
import com.echoledger.mobile.data.OpenSessionRequest
import com.echoledger.mobile.data.UsageReport
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Streaming usage reports (A5.4), server-session deletes on Discard, and caption tokens with sessions (A5.1). */
class UsageAndCaptionsTest : AppTest() {
    private val open = OpenSessionRequest("rec-1", "whatsapp", null, "2026-10-25T09:00:00Z")
    private val openAnswer = """{"session":${ServerFixtures.libraryItem(
        ServerFixtures.SESSION_ID
    )},"recordings":[],"required_artifacts":["primary"]}"""

    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
    }

    private inline fun <reified W : ListenableWorker> runWorker(vararg data: Pair<String, String>): ListenableWorker.Result {
        val future = TestListenableWorkerBuilder<W>(app).setInputData(workDataOf(*data)).build().startWork()
        pumpUntil("the worker to finish") { future.isDone }
        return future.get()
    }

    private fun report(id: String) = UsageReport(id, "live_captions", "assemblyai", null, 61_000, 2)

    // ---- usage queue ------------------------------------------------------------------------------------------------

    @Test
    fun `the usage queue keeps at most 500 reports and drops the oldest`() {
        val queue = UsageQueue(File(app.cacheDir, "usage-test.json"))
        repeat(UsageQueue.CAP + 5) { queue.add(report("rep-%08d".format(it))) }

        val all = queue.all()
        assertEquals(UsageQueue.CAP, all.size)
        assertEquals("rep-00000005", all.first().reportId)
    }

    @Test
    fun `sent reports leave the queue and refused ones are dropped`() {
        val queue = UsageQueue.of(app)
        queue.add(report("rep-accepted"))
        queue.add(report("rep-refused1"))
        server.on("POST", "/api/usage") { request ->
            if (request.bodyText().contains(
                    "rep-accepted"
                )
            ) {
                FakeServer.json(
                    201,
                    """{"report":{},"usage":{}}"""
                )
            } else {
                FakeServer.json(422, """{"message":"bad","code":"validation_failed"}""")
            }
        }

        assertEquals(ListenableWorker.Result.success(), runWorker<UsageWorker>())
        assertTrue(queue.all().isEmpty())
    }

    @Test
    fun `reports stay queued while the server is unreachable`() {
        val queue = UsageQueue.of(app)
        queue.add(report("rep-waiting1"))
        server.on("POST", "/api/usage", FakeServer.json(503, """{"message":"down"}"""))

        assertEquals(ListenableWorker.Result.retry(), runWorker<UsageWorker>())
        assertEquals(listOf("rep-waiting1"), queue.all().map { it.reportId })
    }

    // ---- discard ----------------------------------------------------------------------------------------------------

    @Test
    fun `discarding deletes the server session and treats an already deleted one as done`() {
        server.on(
            "DELETE",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(404, """{"message":"Session not found.","code":"session_not_found"}""")
        )

        assertEquals(ListenableWorker.Result.success(), runWorker<DiscardWorker>(DiscardWorker.KEY_SESSION to ServerFixtures.SESSION_ID))
        assertEquals(1, server.requestsTo("DELETE", "/api/sessions/${ServerFixtures.SESSION_ID}").size)
    }

    // ---- caption tokens ---------------------------------------------------------------------------------------------

    private fun fetch(v11: Boolean, onClientGone: suspend () -> Unit = {}) = runBlocking {
        CaptionTokens(app.graph.api()!!).fetch(v11, open, listOf("me", "client"), existingSession = null, onClientGone = onClientGone)
    }

    @Test
    fun `on a v1_1 server the session is opened before the token is asked for`() {
        server.on("POST", "/api/sessions", FakeServer.json(201, openAnswer))
        server.on("POST", "/api/stt/token", FakeServer.json(200, ServerFixtures.assemblyAiGrants()))

        val outcome = fetch(v11 = true)

        assertTrue(outcome is CaptionTokens.Outcome.Token)
        assertEquals(ServerFixtures.SESSION_ID, outcome.sessionId)
        assertTrue(
            server.requestsTo("POST", "/api/stt/token").single().bodyText().contains("\"session_id\":\"${ServerFixtures.SESSION_ID}\"")
        )
        assertTrue("no artifacts in an open session", !server.requestsTo("POST", "/api/sessions").single().bodyText().contains("artifacts"))
    }

    @Test
    fun `a v1 server gets the old token request without a session`() {
        server.on("POST", "/api/stt/token", FakeServer.json(200, ServerFixtures.assemblyAiGrants()))

        val outcome = fetch(v11 = false)

        assertNull(outcome.sessionId)
        assertTrue(server.requestsTo("POST", "/api/sessions").isEmpty())
    }

    @Test
    fun `the monthly limit is a caption message and the session is kept for the recording`() {
        server.on("POST", "/api/sessions", FakeServer.json(201, openAnswer))
        server.on(
            "POST",
            "/api/stt/token",
            FakeServer.json(403, """{"message":"Monthly transcription limit reached","code":"budget_exceeded"}""")
        )

        val outcome = fetch(v11 = true)

        assertEquals(CaptionTokens.Outcome.Refused(CaptionTokens.BUDGET, ServerFixtures.SESSION_ID), outcome)
    }

    @Test
    fun `a client deleted on the server is forgotten and the request repeated without it`() {
        var cleared = false
        var calls = 0
        server.on("POST", "/api/stt/token") { _ ->
            if (++calls ==
                1
            ) {
                FakeServer.json(404, """{"message":"Client not found.","code":"client_not_found"}""")
            } else {
                FakeServer.json(200, ServerFixtures.assemblyAiGrants())
            }
        }

        val outcome = runBlocking {
            CaptionTokens(app.graph.api()!!).fetch(false, open.copy(clientId = "01JCLIENT00000000000000000"), listOf("me"), null) {
                cleared =
                    true
            }
        }

        assertTrue(outcome is CaptionTokens.Outcome.Token)
        assertTrue(cleared)
        assertTrue(!server.requestsTo("POST", "/api/stt/token").last().bodyText().contains("client_id"))
    }

    @Test
    fun `a server upgraded since the last config gets its session on the first refusal`() {
        var tokens = 0
        server.on("POST", "/api/sessions", FakeServer.json(201, openAnswer))
        server.on("POST", "/api/stt/token") { _ ->
            if (++tokens == 1) {
                FakeServer.json(422, """{"message":"The session id field is required for live captions.","code":"session_required"}""")
            } else {
                FakeServer.json(200, ServerFixtures.assemblyAiGrants())
            }
        }

        val outcome = fetch(v11 = false)

        assertTrue(outcome is CaptionTokens.Outcome.Token)
        assertEquals(ServerFixtures.SESSION_ID, outcome.sessionId)
    }

    @Test
    fun `any other failure leaves captions off with a calm message`() {
        server.on("POST", "/api/stt/token", FakeServer.json(502, """{"message":"Bad gateway","code":"provider_error"}"""))

        assertEquals(CaptionTokens.Outcome.Refused(CaptionTokens.UNAVAILABLE, null), fetch(v11 = false))
    }
}
