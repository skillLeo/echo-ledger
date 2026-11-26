package com.echoledger.mobile.net

import androidx.work.ListenableWorker
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.workDataOf
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.DEFAULT_RECORDING_ID
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyBytes
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.sampleBytes
import com.echoledger.mobile.testsupport.storeRecording
import java.util.concurrent.atomic.AtomicInteger
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import mockwebserver3.RecordedRequest
import mockwebserver3.SocketEffect
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Ignore
import org.junit.Test

/** The resumable upload job, run through WorkManager's test builder against a scripted server. */
class UploadWorkerTest : AppTest() {
    private val id = DEFAULT_RECORDING_ID
    private val serverRecording = ServerFixtures.RECORDING_ID
    private val serverSession = ServerFixtures.SESSION_ID
    private val payload = sampleBytes(2_500)

    @Before
    fun pairWithServer() {
        runBlocking { app.pairLocally(server) }
    }

    private fun run(attempt: Int = 0, recordingId: String? = id): ListenableWorker.Result {
        val data = if (recordingId == null) workDataOf() else workDataOf("recording_id" to recordingId)
        val worker = TestListenableWorkerBuilder<UploadWorker>(app).setInputData(data).setRunAttemptCount(attempt).build()
        val future = worker.startWork()
        pumpUntil("the upload worker to finish") { future.isDone }
        return future.get()
    }

    private fun chunkRoute() = "/api/recordings/$serverRecording/chunks"

    private fun completeRoute() = "/api/recordings/$serverRecording/complete"

    private fun chunks(): List<RecordedRequest> = server.requestsTo("POST", chunkRoute())

    /** Routes for a healthy server: 3 chunks of 1000 bytes for the default 2,500 byte recording. */
    private fun serveHealthyUpload(received: List<Int> = emptyList(), status: String = "pending", total: Int = 3, chunkSize: Long = 1_000) {
        server.on(
            "POST",
            "/api/sessions",
            FakeServer.json(
                201,
                ServerFixtures.sessionCreated(chunkSize = chunkSize, totalChunks = total, received = received, uploadStatus = status)
            )
        )
        server.on("POST", chunkRoute()) { request ->
            FakeServer.json(200, ServerFixtures.chunkAck(listOf(request.headers["Upload-Chunk-Index"]!!.toInt()), total))
        }
        server.on("POST", completeRoute(), FakeServer.json(202, """{"status":"assembling"}"""))
        server.on("GET", "/api/recordings/$serverRecording", FakeServer.json(200, ServerFixtures.recordingStatus(status = "ready")))
        server.on("POST", "/api/sessions/$serverSession/transcripts", FakeServer.json(201, ServerFixtures.transcript(kind = "live")))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
    }

    private fun recording() = app.graph.recordings.get(id)

    // ---- a Discard after the call, during its upload (since v1.8.1) --------------------------------------------------

    private fun discardWork(): List<androidx.work.WorkInfo> =
        androidx.work.WorkManager.getInstance(app).getWorkInfosForUniqueWork("discard-$id").get(10, java.util.concurrent.TimeUnit.SECONDS)

    @Test
    fun `a call discarded before its upload runs sends nothing`() {
        app.storeRecording(bytes = payload, state = "queued")
        runBlocking { com.echoledger.mobile.calls.Decisions.discardLater(app, id) }
        serveHealthyUpload()

        assertEquals(ListenableWorker.Result.success(), run())
        assertTrue("nothing reached the server", server.requests.isEmpty())
        assertEquals(com.echoledger.mobile.calls.Decisions.DISCARDING, recording()?.state)
        assertTrue(app.graph.recordings.encryptedFile(id).exists())
    }

    @Test
    fun `a call discarded while it uploads stays discarded, and the server's copy goes too`() {
        app.storeRecording(bytes = payload, state = "queued")
        serveHealthyUpload()
        // The person discards it while the chunks go up; the upload still finishes on the server.
        server.on("POST", chunkRoute()) { request ->
            if (request.headers["Upload-Chunk-Index"] == "1") runBlocking { com.echoledger.mobile.calls.Decisions.discardLater(app, id) }
            FakeServer.json(200, ServerFixtures.chunkAck(listOf(request.headers["Upload-Chunk-Index"]!!.toInt()), 3))
        }

        assertEquals(ListenableWorker.Result.success(), run())
        assertEquals("never put back to queued or uploading", com.echoledger.mobile.calls.Decisions.DISCARDING, recording()?.state)
        assertTrue("the phone's copy waits for the Discard's own end", app.graph.recordings.encryptedFile(id).exists())
        // The server's session is deleted (DiscardWorker waits for a connection, so it is still queued here)...
        assertEquals(androidx.work.WorkInfo.State.ENQUEUED, discardWork().single().state)
        // ...and it is the session this upload reached.
        assertEquals(serverSession, runServerDelete())

        // Then the Discard ends: the phone's copy goes, and nothing kept the server's.
        runBlocking { com.echoledger.mobile.calls.Decisions.finishDiscard(app, id) }
        assertNull(recording())
    }

    /** Runs the queued server delete (as with a connection) and answers which session it deleted. */
    private fun runServerDelete(): String {
        server.on("DELETE", "/api/sessions/.+", FakeServer.empty(204))
        androidx.work.testing.WorkManagerTestInitHelper.getTestDriver(app)!!.setAllConstraintsMet(discardWork().single().id)
        pumpUntil("the server delete to run") { discardWork().single().state == androidx.work.WorkInfo.State.SUCCEEDED }
        return server.requestsTo("DELETE", "/api/sessions/.+").single().url.encodedPath.substringAfterLast('/')
    }

    @Test
    fun `a call discarded while the server still verifies it has its server session deleted too`() {
        val (poll, wait) = UploadWorker.pollIntervalMs to UploadWorker.verifyWaitMs
        UploadWorker.pollIntervalMs = 10
        UploadWorker.verifyWaitMs = 100
        try {
            app.storeRecording(bytes = payload, state = "queued")
            serveHealthyUpload()
            server.on(
                "GET",
                "/api/recordings/$serverRecording",
                FakeServer.json(200, ServerFixtures.recordingStatus(status = "assembling"))
            )
            server.on("POST", completeRoute()) {
                runBlocking { com.echoledger.mobile.calls.Decisions.discardLater(app, id) }
                FakeServer.json(202, """{"status":"assembling"}""")
            }

            assertEquals("no retry for a call on its way out", ListenableWorker.Result.success(), run())
            assertEquals(com.echoledger.mobile.calls.Decisions.DISCARDING, recording()?.state)
            assertEquals(serverSession, runServerDelete())
        } finally {
            UploadWorker.pollIntervalMs = poll
            UploadWorker.verifyWaitMs = wait
        }
    }

    @Test
    fun `a run stopped after its session was made has that session deleted with the call`() {
        app.storeRecording(bytes = payload, state = "queued")
        serveHealthyUpload()
        val reached = java.util.concurrent.CountDownLatch(1)
        val release = java.util.concurrent.CountDownLatch(1)
        server.on("POST", chunkRoute()) { request ->
            reached.countDown()
            release.await(10, java.util.concurrent.TimeUnit.SECONDS)
            FakeServer.json(200, ServerFixtures.chunkAck(listOf(request.headers["Upload-Chunk-Index"]!!.toInt()), 3))
        }
        val future = TestListenableWorkerBuilder<UploadWorker>(app).setInputData(workDataOf("recording_id" to id)).build().startWork()
        assertTrue(reached.await(10, java.util.concurrent.TimeUnit.SECONDS))
        runBlocking { com.echoledger.mobile.calls.Decisions.discardLater(app, id) }
        future.cancel(true)
        release.countDown()
        pumpUntil("the stopped run to settle") { future.isDone && discardWork().isNotEmpty() }

        assertEquals(com.echoledger.mobile.calls.Decisions.DISCARDING, recording()?.state)
        assertEquals(serverSession, runServerDelete())
    }

    @Test
    fun `a run stopped while it asked for its session finds that session by the call's reference and deletes it`() {
        app.storeRecording(bytes = payload, state = "queued")
        serveHealthyUpload()
        val reached = java.util.concurrent.CountDownLatch(1)
        val release = java.util.concurrent.CountDownLatch(1)
        val asks = java.util.concurrent.atomic.AtomicInteger()
        server.on("POST", "/api/sessions") {
            // The first request arrives and makes the session, but its answer comes only after the run was stopped.
            if (asks.incrementAndGet() == 1) {
                reached.countDown()
                release.await(10, java.util.concurrent.TimeUnit.SECONDS)
            }
            FakeServer.json(201, ServerFixtures.sessionCreated(chunkSize = 1_000, totalChunks = 3))
        }
        val future = TestListenableWorkerBuilder<UploadWorker>(app).setInputData(workDataOf("recording_id" to id)).build().startWork()
        assertTrue(reached.await(10, java.util.concurrent.TimeUnit.SECONDS))
        runBlocking { com.echoledger.mobile.calls.Decisions.discardLater(app, id) }
        future.cancel(true)
        release.countDown()
        pumpUntil("the stopped run to find its session") { future.isDone && discardWork().isNotEmpty() }

        assertEquals("asked again with the same reference", listOf(id, id), clientRefs())
        assertEquals("the session holding the call's title and caller is deleted", serverSession, runServerDelete())
        assertTrue("no chunk was sent", chunks().isEmpty())
    }

    /** A call discarded while its upload finished on the server: the server's delete is queued, the phone's copy kept. */
    private fun discardDuringUpload() {
        app.storeRecording(bytes = payload, state = "queued")
        serveHealthyUpload()
        server.on("POST", chunkRoute()) { request ->
            if (request.headers["Upload-Chunk-Index"] == "1") runBlocking { com.echoledger.mobile.calls.Decisions.discardLater(app, id) }
            FakeServer.json(200, ServerFixtures.chunkAck(listOf(request.headers["Upload-Chunk-Index"]!!.toInt()), 3))
        }
        assertEquals(ListenableWorker.Result.success(), run())
        assertEquals(serverSession, recording()?.serverCopy)
        // From here on the chunks go up as usual.
        server.on("POST", chunkRoute()) { request ->
            FakeServer.json(200, ServerFixtures.chunkAck(listOf(request.headers["Upload-Chunk-Index"]!!.toInt()), 3))
        }
        server.on("DELETE", "/api/sessions/$serverSession", FakeServer.empty(204))
    }

    private fun clientRefs() = server.requestsTo("POST", "/api/sessions").map {
        com.echoledger.mobile.data.ApiJson.parseToJsonElement(it.bodyText()).jsonObject["client_ref"]!!.toString().trim('"')
    }

    @Test
    fun `Undo before the server delete ran stops it, and the upload finishes against the same session`() {
        discardDuringUpload()
        runBlocking { com.echoledger.mobile.calls.Decisions.undoDiscard(app, id) }
        assertEquals(androidx.work.WorkInfo.State.CANCELLED, discardWork().single().state)
        assertEquals("queued", recording()?.state)

        assertEquals(ListenableWorker.Result.success(), run())
        assertTrue("nothing was deleted or restored", server.requestsTo("DELETE", "/api/sessions/$serverSession").isEmpty())
        assertTrue(server.requestsTo("POST", "/api/sessions/$serverSession/restore").isEmpty())
        assertEquals("the same session, found by its reference", listOf(id, id), clientRefs())
        assertNull("the server has it, so the phone's copy goes", recording())
    }

    @Test
    fun `Undo after the server delete ran restores the session first, then finishes against it`() {
        discardDuringUpload()
        val delete = discardWork().single()
        androidx.work.testing.WorkManagerTestInitHelper.getTestDriver(app)!!.setAllConstraintsMet(delete.id)
        pumpUntil("the server delete to run") { discardWork().single().state == androidx.work.WorkInfo.State.SUCCEEDED }
        assertEquals(1, server.requestsTo("DELETE", "/api/sessions/$serverSession").size)
        server.on("POST", "/api/sessions/$serverSession/restore", FakeServer.json(200, """{"data":{"id":"$serverSession"}}"""))
        // Restored, so the server has it again when the upload checks at the end.
        server.on("GET", "/api/sessions/$serverSession", FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null)))

        runBlocking { com.echoledger.mobile.calls.Decisions.undoDiscard(app, id) }
        assertTrue(recording()!!.restoreServerCopy)
        assertEquals(ListenableWorker.Result.success(), run())

        assertEquals(1, server.requestsTo("POST", "/api/sessions/$serverSession/restore").size)
        val order = server.requests.map { it.method + " " + it.url.encodedPath }
        assertTrue(
            "restored before the upload asks for it: $order",
            order.indexOf("POST /api/sessions/$serverSession/restore") < order.lastIndexOf("POST /api/sessions")
        )
        assertEquals(listOf(id, id), clientRefs())
        assertNull(recording())
    }

    @Test
    fun `a server copy that can no longer be restored is sent again fresh, never lost`() {
        discardDuringUpload()
        val delete = discardWork().single()
        androidx.work.testing.WorkManagerTestInitHelper.getTestDriver(app)!!.setAllConstraintsMet(delete.id)
        pumpUntil("the server delete to run") { discardWork().single().state == androidx.work.WorkInfo.State.SUCCEEDED }
        server.on(
            "POST",
            "/api/sessions/$serverSession/restore",
            FakeServer.json(410, """{"message":"The undo period has ended.","code":"restore_expired"}""")
        )

        runBlocking { com.echoledger.mobile.calls.Decisions.undoDiscard(app, id) }
        assertEquals(ListenableWorker.Result.success(), run())

        val refs = clientRefs()
        assertEquals(2, refs.size)
        assertNotEquals("a new reference, so the server makes a new session", id, refs.last())
        assertNull("sent, so the phone's copy goes", recording())
    }

    /**
     * Review of FIX-03: a Delete taken back only because nobody could see its Undo leaves the call to the person on the
     * phone, while the server follows their last choice, Delete: the copy an upload reached is deleted there as queued.
     */
    private fun takenBackNobodySaw(): com.echoledger.mobile.data.LocalRecording {
        discardDuringUpload()
        val back = runBlocking { com.echoledger.mobile.calls.Decisions.undoDiscard(app, id, leftToPerson = true) }!!
        assertEquals("waiting on the phone", "pending_decision", back.state)
        assertTrue("left to the person", back.restoredDelete && back.notDeletedYet)
        assertTrue("a later keep brings the server's copy back first", back.restoreServerCopy)
        assertEquals("the server's delete goes ahead", androidx.work.WorkInfo.State.ENQUEUED, discardWork().single().state)
        assertTrue("the phone's file stays", app.graph.recordings.encryptedFile(id).exists())
        assertEquals(serverSession, runServerDelete())
        return back
    }

    @Test
    fun `a take-back nobody saw deletes the server copy, and a Delete after it leaves nothing on either side`() {
        takenBackNobodySaw()
        runBlocking {
            com.echoledger.mobile.calls.Decisions.discardLater(app, id)
            com.echoledger.mobile.calls.Decisions.finishDiscard(app, id)
        }
        assertNull("gone from the phone", recording())
        assertTrue(!app.graph.recordings.encryptedFile(id).exists())
        assertEquals("and from the server, once", 1, server.requestsTo("DELETE", "/api/sessions/$serverSession").size)
    }

    @Test
    fun `a take-back nobody saw deletes the server copy, and a keep after it restores it from the server's bin`() {
        takenBackNobodySaw()
        server.on("POST", "/api/sessions/$serverSession/restore", FakeServer.json(200, """{"data":{"id":"$serverSession"}}"""))
        server.on("GET", "/api/sessions/$serverSession", FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null)))
        runBlocking { com.echoledger.mobile.calls.Decisions.apply(app, id, keep = true, transcribe = false) }
        assertEquals(ListenableWorker.Result.success(), run())
        assertEquals("restored first", 1, server.requestsTo("POST", "/api/sessions/$serverSession/restore").size)
        assertEquals("the same session, found by its reference", listOf(id, id), clientRefs())
        assertNull("the server has it, so the phone's copy goes", recording())
    }

    @Test
    fun `a take-back nobody saw deletes the server copy, and a keep after its bin expired sends the file fresh`() {
        takenBackNobodySaw()
        server.on(
            "POST",
            "/api/sessions/$serverSession/restore",
            FakeServer.json(410, """{"message":"The undo period has ended.","code":"restore_expired"}""")
        )
        runBlocking { com.echoledger.mobile.calls.Decisions.apply(app, id, keep = true, transcribe = false) }
        assertEquals(ListenableWorker.Result.success(), run())
        val refs = clientRefs()
        assertNotEquals("a new reference, so the server makes a new session", id, refs.last())
        assertNull("sent, so the phone's copy goes", recording())
    }

    @Test
    fun `a take-back nobody saw, then a keep before the server delete ran, finishes against the same session`() {
        discardDuringUpload()
        runBlocking { com.echoledger.mobile.calls.Decisions.undoDiscard(app, id, leftToPerson = true) }!!
        // Offline: the server's delete is still queued when the person keeps the call.
        assertEquals(androidx.work.WorkInfo.State.ENQUEUED, discardWork().single().state)
        server.on(
            "POST",
            "/api/sessions/$serverSession/restore",
            FakeServer.json(404, """{"message":"Not found","code":"session_not_found"}""")
        )
        server.on("GET", "/api/sessions/$serverSession", FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null)))
        runBlocking { com.echoledger.mobile.calls.Decisions.apply(app, id, keep = true, transcribe = false) }
        assertEquals(ListenableWorker.Result.success(), run())

        assertTrue("the queued delete never ran", server.requestsTo("DELETE", "/api/sessions/$serverSession").isEmpty())
        assertEquals(androidx.work.WorkInfo.State.CANCELLED, discardWork().single().state)
        assertEquals(
            "the restore tried once: not in the bin, the session still there",
            1,
            server.requestsTo("POST", "/api/sessions/$serverSession/restore").size
        )
        assertEquals("the same session, found by its reference", listOf(id, id), clientRefs())
        assertNull("the server has it, so the phone's copy goes", recording())
    }

    /** Undo after a delete that may have run (it started, so the phone can't know whether it landed). */
    private fun undoAfterAMaybeDelete() {
        discardDuringUpload()
        runBlocking { com.echoledger.mobile.calls.Decisions.undoDiscard(app, id) }
        app.graph.recordings.update(id) { it.copy(restoreServerCopy = true) }
        server.on(
            "POST",
            "/api/sessions/$serverSession/restore",
            FakeServer.json(404, """{"message":"Not found","code":"session_not_found"}""")
        )
    }

    @Test
    fun `a delete that never landed is not restored into a second copy`() {
        undoAfterAMaybeDelete()
        // The session was never deleted, so restore can't find it in the bin; the server still has it.
        server.on("GET", "/api/sessions/$serverSession", FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null)))

        assertEquals(ListenableWorker.Result.success(), run())
        assertEquals("the old reference, so the server keeps its one session", listOf(id, id), clientRefs())
        assertNull(recording())
    }

    @Test
    fun `a session really gone after a 404 is sent again fresh`() {
        undoAfterAMaybeDelete()
        server.on("GET", "/api/sessions/$serverSession", FakeServer.json(404, """{"message":"Not found","code":"session_not_found"}"""))

        assertEquals(ListenableWorker.Result.success(), run())
        val refs = clientRefs()
        assertEquals(2, refs.size)
        assertNotEquals(id, refs.last())
        assertNull(recording())
    }

    @Test
    fun `a delete that lands after the restore check is undone before the phone's copy goes`() {
        undoAfterAMaybeDelete()
        // The server as it is: in the bin or not. The restore check finds the session alive; then the late delete
        // lands while the chunks go up, so the upload finishes into a trashed session.
        val trashed = java.util.concurrent.atomic.AtomicBoolean(false)
        val restores = java.util.concurrent.atomic.AtomicInteger()
        server.on("POST", "/api/sessions/$serverSession/restore") {
            restores.incrementAndGet()
            if (trashed.getAndSet(false)) {
                FakeServer.json(200, """{"data":{"id":"$serverSession"}}""")
            } else {
                FakeServer.json(404, """{"message":"Not found","code":"session_not_found"}""")
            }
        }
        server.on("GET", "/api/sessions/$serverSession") {
            if (trashed.get()) {
                FakeServer.json(
                    404,
                    """{"message":"Not found"}"""
                )
            } else {
                FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
            }
        }
        server.on("POST", chunkRoute()) { request ->
            if (request.headers["Upload-Chunk-Index"] == "1") trashed.set(true) // the delete lands now
            FakeServer.json(200, ServerFixtures.chunkAck(listOf(request.headers["Upload-Chunk-Index"]!!.toInt()), 3))
        }

        assertEquals(ListenableWorker.Result.success(), run())
        assertFalse("the session is out of the bin again", trashed.get())
        assertEquals("the restore check, then the one after the upload", 2, restores.get())
        assertNull("the server has it, so the phone's copy goes", recording())
    }

    @Test
    fun `a session gone for good after the upload keeps the phone's copy for a fresh upload`() {
        undoAfterAMaybeDelete()
        server.on("GET", "/api/sessions/$serverSession", FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null)))
        server.on("POST", chunkRoute()) { request ->
            if (request.headers["Upload-Chunk-Index"] == "1") {
                // Gone for good while it uploaded: the bin's time ended.
                server.on("GET", "/api/sessions/$serverSession", FakeServer.json(404, """{"message":"Not found"}"""))
                server.on(
                    "POST",
                    "/api/sessions/$serverSession/restore",
                    FakeServer.json(410, """{"message":"Ended","code":"restore_expired"}""")
                )
            }
            FakeServer.json(200, ServerFixtures.chunkAck(listOf(request.headers["Upload-Chunk-Index"]!!.toInt()), 3))
        }

        assertEquals(ListenableWorker.Result.retry(), run())
        assertTrue("never deleted while the server has none", app.graph.recordings.encryptedFile(id).exists())
        assertNotEquals("the next run uses a new reference", id, recording()?.clientRef)
    }

    @Test
    fun `an upload that fails after a Discard leaves the call discarded`() {
        app.storeRecording(bytes = payload, state = "queued")
        server.on("POST", "/api/sessions") {
            runBlocking { com.echoledger.mobile.calls.Decisions.discardLater(app, id) }
            FakeServer.json(500, """{"message":"Server Error"}""")
        }

        assertEquals(ListenableWorker.Result.success(), run())
        assertEquals(com.echoledger.mobile.calls.Decisions.DISCARDING, recording()?.state)
    }

    // ---- success ----------------------------------------------------------------------------------------------------

    @Test
    fun `a healthy upload sends every chunk then completes and removes the local copy`() {
        app.storeRecording(bytes = payload)
        serveHealthyUpload()

        val result = run()

        assertEquals(ListenableWorker.Result.success(), result)
        assertEquals(listOf("0", "1", "2"), chunks().map { it.headers["Upload-Chunk-Index"] })
        assertEquals(listOf(1_000, 1_000, 500), chunks().map { it.bodyBytes().size })
        chunks().forEachIndexed { index, request ->
            val slice = payload.copyOfRange(index * 1_000, minOf(payload.size, (index + 1) * 1_000))
            assertTrue("chunk $index bytes", slice.contentEquals(request.bodyBytes()))
            assertEquals("chunk $index checksum", UploadWorker.sha256(slice), request.headers["Upload-Chunk-Sha256"])
            assertEquals("application/octet-stream", request.headers["Content-Type"]!!.substringBefore(';'))
        }
        assertEquals(1, server.requestsTo("POST", completeRoute()).size)
        val order = server.requests.map { it.method + " " + it.url.encodedPath }
        assertTrue(
            "complete must follow the last chunk: $order",
            order.indexOf("POST ${completeRoute()}") > order.lastIndexOf("POST ${chunkRoute()}")
        )
        assertNull("the encrypted copy is removed once the server holds it", recording())
        assertTrue("the library is refreshed so the new session shows up", server.requestsTo("GET", "/api/library").isNotEmpty())
        assertTrue(server.requests.all { it.headers["Authorization"] == "Bearer 7|eld_testtoken" })
    }

    @Test
    fun `the session request describes the recording`() {
        val stored = app.storeRecording(
            bytes = payload,
            sourceApp = "whatsapp_business",
            clientId = "01JCLIENT0000000000000000A",
            durationMs = 61_000,
            channels = 2,
            sampleRate = 48_000
        )
        serveHealthyUpload()

        run()

        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/sessions").single().bodyText()).jsonObject
        assertEquals(id, body["client_ref"]!!.jsonPrimitive.content)
        assertEquals("whatsapp_business", body["source_app"]!!.jsonPrimitive.content)
        assertEquals("01JCLIENT0000000000000000A", body["client_id"]!!.jsonPrimitive.content)
        assertEquals("2026-10-24T09:00:00Z", body["started_at"]!!.jsonPrimitive.content)
        assertEquals("2026-10-24T09:01:00Z", body["ended_at"]!!.jsonPrimitive.content)
        assertEquals(61_000, body["duration_ms"]!!.jsonPrimitive.int)
        val artifact = body["artifacts"]!!.jsonArray.single().jsonObject
        assertEquals("primary", artifact["role"]!!.jsonPrimitive.content)
        assertEquals("audio/ogg", artifact["mime"]!!.jsonPrimitive.content)
        assertEquals("opus", artifact["codec"]!!.jsonPrimitive.content)
        assertEquals(2, artifact["channels"]!!.jsonPrimitive.int)
        assertEquals(48_000, artifact["sample_rate"]!!.jsonPrimitive.int)
        assertEquals(payload.size.toLong(), artifact["size_bytes"]!!.jsonPrimitive.content.toLong())
        assertEquals(stored.sha256, artifact["sha256"]!!.jsonPrimitive.content)
        assertEquals(UploadWorker.sha256(payload), stored.sha256)
        assertEquals(61_000, artifact["duration_ms"]!!.jsonPrimitive.int)
    }

    @Test
    fun `an unknown sample rate of zero is left out of the request`() {
        app.storeRecording(
            bytes = payload,
            sampleRate = 0,
            durationMs = 0,
            mime = "audio/ogg",
            codec = "unknown",
            channels = 1,
            sourceApp = "voice_note"
        )
        serveHealthyUpload()

        assertEquals(ListenableWorker.Result.success(), run())

        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/sessions").single().bodyText()).jsonObject
        val artifact = body["artifacts"]!!.jsonArray.single().jsonObject
        assertFalse("sample_rate 0 must be sent as absent, not 0: $artifact", artifact.containsKey("sample_rate"))
        assertFalse("unknown duration must be absent, not 0", artifact.containsKey("duration_ms"))
        assertFalse(body.containsKey("duration_ms"))
        assertEquals("voice_note", body["source_app"]!!.jsonPrimitive.content)
        assertEquals("unknown", artifact["codec"]!!.jsonPrimitive.content)
    }

    @Test
    fun `live captions are posted after the upload completes and a failure to post them is ignored`() {
        val segments = listOf(SegmentDto(1, "Me", 0, 900, "Hello."), SegmentDto(2, "Client", 1_000, 2_000, "Hi there."))
        app.storeRecording(bytes = payload, liveSegments = segments, liveProvider = "elevenlabs")
        serveHealthyUpload()
        server.on(
            "POST",
            "/api/sessions/$serverSession/transcripts",
            FakeServer.json(422, ServerFixtures.validationError("Segment timing exceeds the session duration.", "segments"))
        )

        assertEquals(ListenableWorker.Result.success(), run())

        val post = server.requestsTo("POST", "/api/sessions/$serverSession/transcripts").single()
        val body = Json.parseToJsonElement(post.bodyText()).jsonObject
        assertEquals("live", body["kind"]!!.jsonPrimitive.content)
        assertEquals("elevenlabs", body["provider"]!!.jsonPrimitive.content)
        assertEquals(2, body["segments"]!!.jsonArray.size)
        val order = server.requests.map { it.url.encodedPath }
        assertTrue(order.indexOf("/api/sessions/$serverSession/transcripts") > order.indexOf(completeRoute()))
        assertNull("the recording is still released after a rejected caption transcript", recording())
    }

    @Test
    fun `captions default to the AssemblyAI provider name when none was recorded`() {
        app.storeRecording(bytes = payload, liveSegments = listOf(SegmentDto(1, "Me", 0, 900, "Hello.")), liveProvider = null)
        serveHealthyUpload()

        run()

        val body = Json.parseToJsonElement(
            server.requestsTo("POST", "/api/sessions/$serverSession/transcripts").single().bodyText()
        ).jsonObject
        assertEquals("assemblyai", body["provider"]!!.jsonPrimitive.content)
    }

    @Test
    fun `no transcript is posted for a recording without captions`() {
        app.storeRecording(bytes = payload)
        serveHealthyUpload()

        run()

        assertTrue(server.requestsTo("POST", "/api/sessions/$serverSession/transcripts").isEmpty())
    }

    // ---- resume ------------------------------------------------------------------------------------------------------

    @Test
    fun `only the chunks the server is missing are sent`() {
        val bigger = sampleBytes(4_000)
        app.storeRecording(bytes = bigger)
        serveHealthyUpload(received = listOf(0, 2), status = "uploading", total = 4)

        assertEquals(ListenableWorker.Result.success(), run())

        assertEquals(listOf("1", "3"), chunks().map { it.headers["Upload-Chunk-Index"] })
        assertTrue(bigger.copyOfRange(1_000, 2_000).contentEquals(chunks()[0].bodyBytes()))
        assertTrue(bigger.copyOfRange(3_000, 4_000).contentEquals(chunks()[1].bodyBytes()))
        assertEquals(1, server.requestsTo("POST", completeRoute()).size)
    }

    @Test
    fun `an upload the server already holds sends nothing more and just releases the local copy`() {
        listOf("assembling", "ready").forEach { status ->
            server.requests.clear()
            app.storeRecording(bytes = payload)
            serveHealthyUpload(received = listOf(0, 1, 2), status = status)

            assertEquals(status, ListenableWorker.Result.success(), run())

            assertTrue("$status: no chunks expected", chunks().isEmpty())
            assertTrue("$status: no second complete expected", server.requestsTo("POST", completeRoute()).isEmpty())
            assertNull(status, recording())
        }
    }

    @Test
    fun `a retry after a failed chunk continues where the server stopped`() {
        app.storeRecording(bytes = payload)
        val received = mutableListOf<Int>()
        val sessionCalls = AtomicInteger()
        server.on("POST", "/api/sessions") {
            val first = sessionCalls.incrementAndGet() == 1
            FakeServer.json(
                if (first) 201 else 200,
                ServerFixtures.sessionCreated(chunkSize = 1_000, totalChunks = 3, received = received.toList(), uploadStatus = "uploading")
            )
        }
        server.on("POST", chunkRoute()) { request ->
            val index = request.headers["Upload-Chunk-Index"]!!.toInt()
            if (index == 2 && sessionCalls.get() == 1) {
                FakeServer.json(503, """{"message":"Service Unavailable"}""")
            } else {
                received += index
                FakeServer.json(200, ServerFixtures.chunkAck(received.toList(), 3))
            }
        }
        server.on("POST", completeRoute(), FakeServer.json(202, """{"status":"assembling"}"""))
        server.on("GET", "/api/recordings/$serverRecording", FakeServer.json(200, ServerFixtures.recordingStatus(status = "ready")))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))

        assertEquals(ListenableWorker.Result.retry(), run(attempt = 0))
        assertEquals("queued", recording()!!.state)
        assertEquals(listOf("0", "1", "2"), chunks().map { it.headers["Upload-Chunk-Index"] })

        server.requests.clear()
        assertEquals(ListenableWorker.Result.success(), run(attempt = 1))
        assertEquals("only the missing chunk is re-sent", listOf("2"), chunks().map { it.headers["Upload-Chunk-Index"] })
        assertNull(recording())
    }

    @Test
    fun `a recording that fits in a single chunk uploads in one request`() {
        val small = sampleBytes(300)
        app.storeRecording(bytes = small)
        serveHealthyUpload(total = 1, chunkSize = 5_242_880)

        assertEquals(ListenableWorker.Result.success(), run())

        assertEquals(1, chunks().size)
        assertTrue(small.contentEquals(chunks().single().bodyBytes()))
    }

    @Test
    fun `a recording of fifty chunks arrives complete and in order`() {
        val large = sampleBytes(50 * 1_000 - 123)
        app.storeRecording(bytes = large)
        serveHealthyUpload(total = 50, chunkSize = 1_000)

        assertEquals(ListenableWorker.Result.success(), run())

        assertEquals((0 until 50).map { it.toString() }, chunks().map { it.headers["Upload-Chunk-Index"] })
        val reassembled = chunks().map { it.bodyBytes() }.reduce { a, b -> a + b }
        assertTrue(large.contentEquals(reassembled))
    }

    // ---- transient failures --------------------------------------------------------------------------------------------

    @Test
    fun `a server error while creating the session is retried and the recording stays queued`() {
        app.storeRecording(bytes = payload)
        server.on("POST", "/api/sessions", FakeServer.json(500, """{"message":"Server Error"}"""))

        assertEquals(ListenableWorker.Result.retry(), run())

        val kept = recording()!!
        assertEquals("queued", kept.state)
        // A server's crash is said in plain words (P-024), never its "Server Error".
        assertEquals(com.echoledger.mobile.core.Problems.SERVER, kept.lastError)
        assertTrue(chunks().isEmpty())
    }

    @Test
    fun `a dropped connection is retried and keeps the recording`() {
        app.storeRecording(bytes = payload)
        server.on("POST", "/api/sessions", MockResponse.Builder().onResponseStart(SocketEffect.CloseSocket()).build())

        assertEquals(ListenableWorker.Result.retry(), run())

        assertEquals("queued", recording()!!.state)
    }

    @Test
    fun `rate limiting and request timeouts are transient`() {
        listOf(408, 429, 500, 502, 503, 504).forEach { status ->
            app.storeRecording(bytes = payload)
            server.on("POST", "/api/sessions", FakeServer.json(status, """{"message":"try later"}"""))

            assertEquals("status $status", ListenableWorker.Result.retry(), run())

            val kept = recording()
            assertNotNull("status $status must keep the recording", kept)
            assertEquals("status $status", "queued", kept!!.state)
            // The server's own words, except for its crashes (5xx: plain words, P-024).
            assertEquals("status $status", if (status >= 500) com.echoledger.mobile.core.Problems.SERVER else "try later", kept.lastError)
        }
    }

    @Test
    fun `an app the server no longer takes waits for an update instead of failing (v1_6)`() {
        app.storeRecording(bytes = payload)
        server.on(
            "POST",
            "/api/sessions",
            FakeServer.json(
                426,
                """{"message":"Please update EchoLedger to 1.6.0 or later.","code":"update_required","minimum":"1.6.0","url":"https://x/downloads"}"""
            )
        )

        assertEquals(ListenableWorker.Result.success(), run())

        assertEquals("queued", recording()!!.state)
        assertEquals(UploadWorker.WAITS_FOR_UPDATE, recording()!!.lastError)
        val again = androidx.work.WorkManager.getInstance(app).getWorkInfosForUniqueWork("upload-$id").get()
        assertTrue("it tries again later", again.any { !it.state.isFinished })
    }

    @Test
    fun `maintenance makes the upload wait for Retry-After, not count as a failed attempt (v1_6)`() {
        app.storeRecording(bytes = payload)
        server.on(
            "POST",
            "/api/sessions",
            MockResponse.Builder().code(503).addHeader("Content-Type", "application/json").addHeader("Retry-After", "900")
                .body("""{"message":"Back soon.","code":"maintenance","until":null}""").build()
        )

        assertEquals(ListenableWorker.Result.success(), run(attempt = 11))

        assertEquals("queued", recording()!!.state)
        assertEquals(UploadWorker.WAITS_FOR_MAINTENANCE, recording()!!.lastError)
    }

    @Test
    fun `a chunk rejected with a server error is retried and earlier chunks stay uploaded`() {
        app.storeRecording(bytes = payload)
        serveHealthyUpload()
        server.on("POST", chunkRoute()) { request ->
            if (request.headers["Upload-Chunk-Index"] ==
                "1"
            ) {
                FakeServer.json(503, """{"message":"busy"}""")
            } else {
                FakeServer.json(200, ServerFixtures.chunkAck(listOf(0), 3))
            }
        }

        assertEquals(ListenableWorker.Result.retry(), run())

        assertEquals(listOf("0", "1"), chunks().map { it.headers["Upload-Chunk-Index"] })
        assertTrue("complete must not be called with chunks missing", server.requestsTo("POST", completeRoute()).isEmpty())
        assertEquals("queued", recording()!!.state)
    }

    @Test
    fun `after twelve attempts a transient failure becomes final but the recording is kept`() {
        app.storeRecording(bytes = payload)
        server.on("POST", "/api/sessions", FakeServer.json(503, """{"message":"down"}"""))

        assertEquals(ListenableWorker.Result.retry(), run(attempt = 11))
        assertEquals(ListenableWorker.Result.failure(), run(attempt = 12))

        assertEquals("queued", recording()!!.state)
    }

    @Test
    fun `after twelve attempts a network failure becomes final as well`() {
        app.storeRecording(bytes = payload)
        server.on("POST", "/api/sessions", MockResponse.Builder().onResponseStart(SocketEffect.CloseSocket()).build())

        assertEquals(ListenableWorker.Result.retry(), run(attempt = 11))
        assertEquals(ListenableWorker.Result.failure(), run(attempt = 12))
    }

    // ---- permanent failures ----------------------------------------------------------------------------------------------

    private fun assertPermanent(status: Int, message: String) {
        app.storeRecording(bytes = payload)
        server.on("POST", "/api/sessions", FakeServer.json(status, """{"message":"$message"}"""))

        assertEquals("status $status", ListenableWorker.Result.failure(), run())

        val kept = recording()
        assertNotNull("a refused recording is kept so the user can retry or discard it", kept)
        assertEquals("failed", kept!!.state)
        assertEquals(message, kept.lastError)
    }

    @Test
    fun `an import the Library already has is skipped, says so and keeps no audio`() {
        app.storeRecording(bytes = payload)
        app.graph.recordings.update(id) { it.copy(skipDuplicate = true) }
        server.on(
            "POST",
            "/api/sessions",
            FakeServer.json(409, """{"message":"This file is already in your Library.","code":"duplicate_recording","session_id":"x"}""")
        )

        assertEquals(ListenableWorker.Result.success(), run())

        assertTrue(server.requestsTo("POST", "/api/sessions").single().bodyText().contains("\"skip_duplicate\":true"))
        assertEquals("duplicate", recording()?.state)
        assertEquals(UploadWorker.ALREADY_THERE, recording()?.lastError)
        assertFalse(app.graph.recordings.encryptedFile(id).exists())
        assertTrue(chunks().isEmpty())
    }

    @Test
    fun `Save audio sends transcribe false, Transcribe sends true, and a recording without a choice leaves it out`() {
        // 2.0.0-j: absent means transcribe, as before.
        for ((choice, sent) in listOf(false to "\"transcribe\":false", true to "\"transcribe\":true", null to null)) {
            server.requests.clear()
            app.graph.recordings.delete(id)
            app.storeRecording(bytes = payload)
            app.graph.recordings.update(id) { it.copy(transcribe = choice) }
            server.on("POST", "/api/sessions", FakeServer.json(422, """{"message":"x"}"""))
            run()
            val body = server.requestsTo("POST", "/api/sessions").single().bodyText()
            if (sent == null) assertFalse(body, body.contains("transcribe")) else assertTrue(body, body.contains(sent))
        }
    }

    @Test
    fun `a recording that is not an import never asks to skip duplicates`() {
        app.storeRecording(bytes = payload)
        server.on("POST", "/api/sessions", FakeServer.json(422, """{"message":"x"}"""))
        run()

        assertFalse(server.requestsTo("POST", "/api/sessions").single().bodyText().contains("skip_duplicate"))
    }

    @Test
    fun `a validation error is final and shows the server reason`() =
        assertPermanent(422, "The artifacts.0.sha256 field format is invalid.")

    @Test
    fun `a bad request is final`() = assertPermanent(400, "Bad request.")

    @Test
    fun `a forbidden request is final`() = assertPermanent(403, "Invalid ability provided.")

    @Test
    fun `an unknown resource is final`() = assertPermanent(404, "No query results for model.")

    @Test
    fun `an oversized body is final`() = assertPermanent(413, "Payload too large.")

    @Test
    fun `a chunk refused with 422 is final`() {
        app.storeRecording(bytes = payload)
        serveHealthyUpload()
        server.on("POST", chunkRoute(), FakeServer.json(422, """{"message":"Chunk checksum mismatch."}"""))

        assertEquals(ListenableWorker.Result.failure(), run())

        assertEquals("failed", recording()!!.state)
        assertEquals("Chunk checksum mismatch.", recording()!!.lastError)
    }

    @Test
    fun `a revoked device stops the upload but keeps the recording queued with an explanation`() {
        app.storeRecording(bytes = payload)
        server.on("POST", "/api/sessions", FakeServer.json(401, """{"message":"Unauthenticated."}"""))

        assertEquals(ListenableWorker.Result.failure(), run())

        val kept = recording()!!
        assertEquals("queued", kept.state)
        assertEquals("This phone is not signed in to an EchoLedger server.", kept.lastError)
    }

    // ---- preconditions -------------------------------------------------------------------------------------------------------

    @Test
    fun `an unpaired phone retries later without touching the recording`() {
        runBlocking { app.graph.settings.clearPairing() }
        app.graph.invalidateApi()
        app.storeRecording(bytes = payload)

        assertEquals(ListenableWorker.Result.retry(), run())

        assertEquals("queued", recording()!!.state)
        assertTrue(server.requests.isEmpty())
    }

    @Test
    fun `a recording that no longer exists finishes successfully without any request`() {
        assertEquals(ListenableWorker.Result.success(), run(recordingId = "does-not-exist"))
        assertTrue(server.requests.isEmpty())
    }

    @Test
    fun `work without a recording id fails`() {
        assertEquals(ListenableWorker.Result.failure(), run(recordingId = null))
        assertTrue(server.requests.isEmpty())
    }

    // ---- hostile server answers ------------------------------------------------------------------------------------------------
    @Test
    fun `an HTML page answered to the session request does not leave the recording stuck as uploading`() {
        app.storeRecording(bytes = payload)
        server.on(
            "POST",
            "/api/sessions",
            MockResponse.Builder().code(200).addHeader("Content-Type", "text/html").body("<html>Sign in to continue</html>").build()
        )

        runCatching { run() }

        assertNotEquals("uploading", recording()!!.state)
    }

    @Test
    fun `a session answer without a primary upload slot does not leave the recording stuck as uploading`() {
        app.storeRecording(bytes = payload)
        val withoutSlot = """{"session":${ServerFixtures.libraryItem(serverSession)},"recordings":[],"required_artifacts":["primary"]}"""
        server.on("POST", "/api/sessions", FakeServer.json(201, withoutSlot))

        runCatching { run() }

        assertNotEquals("uploading", recording()!!.state)
    }

    @Test
    fun `a missing encrypted file is reported as a final failure instead of retrying for hours`() {
        app.storeRecording(bytes = payload)
        app.graph.recordings.encryptedFile(id).delete()
        serveHealthyUpload()

        val result = run()

        assertEquals("a file that is gone can never be uploaded", ListenableWorker.Result.failure(), result)
        assertEquals("failed", recording()!!.state)
    }
}
