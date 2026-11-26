package com.echoledger.mobile.net

import androidx.work.ListenableWorker
import androidx.work.WorkInfo
import androidx.work.WorkManager
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.workDataOf
import com.echoledger.mobile.calls.Decisions
import com.echoledger.mobile.core.Owner
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import java.io.File
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.jsonObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Contract 2.0.0-z: the end of a call reaches its server session at once (PATCH ended_at and duration_ms), so every
 * device shows it waiting; once per session, dropped when the session is gone or refuses it for good, never for a
 * recording deleted here or made under another account, and queued again when a delete is undone.
 */
class CallEndWorkerTest : AppTest() {
    private val sid = ServerFixtures.SESSION_ID
    private val path = "/api/sessions/$sid"

    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
    }

    private fun stored(session: String? = sid, owner: Owner? = null, state: String = "pending_decision"): LocalRecording {
        val rec = app.storeRecording(state = state)
        return app.graph.recordings.update(rec.id) { it.copy(serverSessionId = session, owner = owner) }!!
    }

    private fun run(rec: LocalRecording): ListenableWorker.Result {
        val data = workDataOf(
            CallEndWorker.KEY_SESSION to sid,
            CallEndWorker.KEY_RECORDING to rec.id,
            CallEndWorker.KEY_ENDED_AT to "2026-10-24T09:01:00Z",
            CallEndWorker.KEY_DURATION_MS to 60_000L
        )
        val future = TestListenableWorkerBuilder<CallEndWorker>(app).setInputData(data).build().startWork()
        pumpUntil("the worker to finish") { future.isDone }
        return future.get()
    }

    private fun queued(): List<WorkInfo> = WorkManager.getInstance(app).getWorkInfosForUniqueWork(CallEndWorker.workName(sid)).get()

    @Test
    fun `the end of the call goes to its session with ended_at and duration_ms`() {
        server.on("PATCH", path, FakeServer.json(200, ServerFixtures.libraryItem(sid)))
        assertEquals(ListenableWorker.Result.success(), run(stored()))
        val body = ApiJson.parseToJsonElement(server.requestsTo("PATCH", path).single().bodyText()).jsonObject
        assertEquals(setOf("ended_at", "duration_ms"), body.keys)
        assertEquals("\"2026-10-24T09:01:00Z\"", body.getValue("ended_at").toString())
        assertEquals("60000", body.getValue("duration_ms").toString())
    }

    @Test
    fun `gone, no longer recording or refused for good drops it, a server error or too many requests tries again`() {
        val rec = stored()
        val success = ListenableWorker.Result.success()
        val retry = ListenableWorker.Result.retry()
        val answers = listOf(404 to success, 409 to success, 422 to success, 403 to success, 400 to success, 429 to retry, 503 to retry)
        for ((status, want) in answers) {
            server.on("PATCH", path, FakeServer.json(status, """{"message":"No.","code":"x"}"""))
            assertEquals("$status", want, run(rec))
        }
    }

    @Test
    fun `nothing is sent for a recording deleted here, one whose session went, or one made under another account`() {
        server.on("PATCH", path, FakeServer.json(200, ServerFixtures.libraryItem(sid)))
        val gone = stored()
        app.graph.recordings.delete(gone.id)
        assertEquals(ListenableWorker.Result.success(), run(gone))
        assertEquals(ListenableWorker.Result.success(), run(stored(session = null)))
        assertEquals(ListenableWorker.Result.success(), run(stored(owner = Owner("https://other.example", "someone@other.example"))))
        assertTrue(server.requestsTo("PATCH", path).isEmpty())
    }

    @Test
    fun `it is queued once per session and needs a connection`() {
        CallEndWorker.enqueue(app, "rec-1", sid, 1_000L, 60_000)
        CallEndWorker.enqueue(app, "rec-1", sid, 2_000L, 61_000)
        val work = queued().single()
        assertEquals(WorkInfo.State.ENQUEUED, work.state)
        assertEquals(androidx.work.NetworkType.CONNECTED, work.constraints.requiredNetworkType)
    }

    @Test
    fun `an undone delete waiting for its choice queues the end again, kept or left to the person it doesn't`() {
        val rec = stored(state = Decisions.DISCARDING)
        app.graph.recordings.update(rec.id) { it.copy(discardedFrom = "pending_decision") }
        runBlocking { Decisions.undoDiscard(app, rec.id) }
        assertEquals(1, queued().size)
        WorkManager.getInstance(app).cancelUniqueWork(CallEndWorker.workName(sid)).result.get()
        WorkManager.getInstance(app).pruneWork().result.get()

        // Back to kept: its upload's finish carries the end.
        val kept = stored(state = Decisions.DISCARDING)
        app.graph.recordings.update(kept.id) { it.copy(discardedFrom = "queued") }
        runBlocking { Decisions.undoDiscard(app, kept.id) }
        assertTrue(queued().none { it.state == WorkInfo.State.ENQUEUED })

        val left = stored(state = Decisions.DISCARDING)
        runBlocking { Decisions.undoDiscard(app, left.id, leftToPerson = true) }
        assertTrue(queued().none { it.state == WorkInfo.State.ENQUEUED })
    }

    /**
     * Where the end is queued on stop (RecordingService.stopCapture): after the recording is saved and the deletes are
     * settled, before the card shows, and only for a recording that is kept or waits for its choice with a session open.
     */
    @Test
    fun `the service queues the end before the card, never for a recording it deletes, and only with a session open`() {
        val source = File("src/main/java/com/echoledger/mobile/recording/RecordingService.kt").readText()
        val stop = source.substring(source.indexOf("private fun stopCapture("), source.indexOf("private fun simAnsweredAt("))
        val end = stop.indexOf("CallEndWorker.enqueue(")
        assertTrue("queued on stop", end > 0)
        assertTrue("after the deletes", end > stop.indexOf("DiscardWorker.enqueue(this, stored.id, sessionId)"))
        assertTrue("before the card", end < stop.indexOf("PostCallPrompt.show("))
        val guard = stop.substring(stop.lastIndexOf("if (", end), end)
        for (part in listOf("stored != null", "!silent", "skip == null", "deleteAtMs == null")) assertTrue(part, part in guard)
        assertTrue("only while its choice waits", "state == \"pending_decision\"" in guard)
        assertTrue("only with a session open", "stored.serverSessionId?.takeIf { waiting }" in guard)
        assertTrue("best-effort", "runCatching { CallEndWorker.enqueue(" in stop.substring(end - 30, end + 30))
    }
}
