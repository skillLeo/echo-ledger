package com.echoledger.mobile.net

import androidx.work.ListenableWorker
import androidx.work.testing.TestListenableWorkerBuilder
import androidx.work.workDataOf
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.MarkerDto
import com.echoledger.mobile.data.PauseDto
import com.echoledger.mobile.data.ServerConfig
import com.echoledger.mobile.data.SessionDetailDto
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
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Bookmarks and pause gaps (B2, B3) travel with the recording: create or finish, and back from the server. */
class MarkersUploadTest : AppTest() {
    private val id = DEFAULT_RECORDING_ID
    private val serverRecording = ServerFixtures.RECORDING_ID
    private val openSession = "01JOPENSESSION000000000000"
    private val markers = listOf(MarkerDto(5_000, "Budget", null), MarkerDto(9_000, null, "call back"))
    private val pauses = listOf(PauseDto(7_000, 3_000))

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
        val future = TestListenableWorkerBuilder<UploadWorker>(app).setInputData(workDataOf("recording_id" to id)).build().startWork()
        pumpUntil("the upload worker to finish") { future.isDone }
        return future.get()
    }

    private fun serveUpload() {
        server.on("POST", "/api/recordings/$serverRecording/chunks") { request ->
            FakeServer.json(200, ServerFixtures.chunkAck(listOf(request.headers["Upload-Chunk-Index"]!!.toInt()), 3))
        }
        server.on("POST", "/api/recordings/$serverRecording/complete", FakeServer.json(202, """{"status":"ready"}"""))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
    }

    private fun answer() =
        ServerFixtures.sessionCreated(chunkSize = 1_000, totalChunks = 3, received = emptyList(), uploadStatus = "pending")

    @Test
    fun `the one-shot create sends the bookmarks and pauses`() {
        app.storeRecording(bytes = sampleBytes(2_500))
        app.graph.recordings.update(id) { it.copy(markers = markers, pauses = pauses) }
        server.on("POST", "/api/sessions", FakeServer.json(201, answer()))
        serveUpload()

        assertEquals(ListenableWorker.Result.success(), run())

        val body = ApiJson.parseToJsonElement(server.requestsTo("POST", "/api/sessions").single().bodyText()).jsonObject
        val sent = body.getValue("markers").jsonArray
        assertEquals("5000", sent[0].jsonObject.getValue("at_ms").jsonPrimitive.content)
        assertEquals("Budget", sent[0].jsonObject.getValue("tag").jsonPrimitive.content)
        assertEquals("3000", body.getValue("pauses").jsonArray.single().jsonObject.getValue("paused_ms").jsonPrimitive.content)
    }

    @Test
    fun `finishing an open session sends them too`() {
        app.storeRecording(bytes = sampleBytes(2_500))
        app.graph.recordings.update(id) { it.copy(serverSessionId = openSession, markers = markers, pauses = pauses) }
        server.on("POST", "/api/sessions/$openSession/finish", FakeServer.json(200, answer()))
        serveUpload()

        assertEquals(ListenableWorker.Result.success(), run())

        val body = server.requestsTo("POST", "/api/sessions/$openSession/finish").single().bodyText()
        assertTrue(body.contains("\"markers\":[") && body.contains("\"pauses\":["))
    }

    @Test
    fun `finishing sends the language chosen on the Keep card, and an empty one for automatic`() {
        app.storeRecording(bytes = sampleBytes(2_500))
        app.graph.recordings.update(id) { it.copy(serverSessionId = openSession, language = "ur") }
        server.on("POST", "/api/sessions/$openSession/finish", FakeServer.json(200, answer()))
        serveUpload()

        assertEquals(ListenableWorker.Result.success(), run())

        val body = ApiJson.parseToJsonElement(server.requestsTo("POST", "/api/sessions/$openSession/finish").single().bodyText()).jsonObject
        assertEquals("ur", body.getValue("language").jsonPrimitive.content)
        val auto = ApiJson.encodeToString(
            com.echoledger.mobile.data.FinishSessionRequest.serializer(),
            com.echoledger.mobile.data.FinishSessionRequest("2026-10-27T10:00:00Z", 1_000, null, emptyList())
        )
        assertTrue(auto.contains("\"language\":\"\""))
    }

    @Test
    fun `finishing sends the language chip changes, and nothing when the chip never changed`() {
        app.storeRecording(bytes = sampleBytes(2_500))
        app.graph.recordings.update(id) {
            it.copy(
                serverSessionId = openSession,
                languageMarks = listOf(
                    com.echoledger.mobile.data.LanguageMarkDto(0, "ur"),
                    com.echoledger.mobile.data.LanguageMarkDto(125_000, "en")
                )
            )
        }
        server.on("POST", "/api/sessions/$openSession/finish", FakeServer.json(200, answer()))
        serveUpload()

        assertEquals(ListenableWorker.Result.success(), run())

        val marks = ApiJson.parseToJsonElement(
            server.requestsTo("POST", "/api/sessions/$openSession/finish").single().bodyText()
        ).jsonObject
            .getValue("language_marks").jsonArray
        assertEquals("125000", marks[1].jsonObject.getValue("at_ms").jsonPrimitive.content)
        assertEquals("en", marks[1].jsonObject.getValue("language").jsonPrimitive.content)
    }

    @Test
    fun `a recording without bookmarks sends neither key, so a v1 server sees the old request`() {
        app.storeRecording(bytes = sampleBytes(2_500))
        server.on("POST", "/api/sessions", FakeServer.json(201, answer()))
        serveUpload()

        assertEquals(ListenableWorker.Result.success(), run())

        val body = server.requestsTo("POST", "/api/sessions").single().bodyText()
        assertFalse(body.contains("markers"))
        assertFalse(body.contains("pauses"))
        assertFalse(body.contains("language_marks"))
    }

    @Test
    fun `bookmarks survive in the local store until the upload`() {
        app.storeRecording(bytes = sampleBytes(2_500), state = "pending_decision")
        app.graph.recordings.update(id) { it.copy(markers = markers, pauses = pauses) }

        val reloaded = app.graph.recordings.get(id)!!

        assertEquals(markers, reloaded.markers)
        assertEquals(pauses, reloaded.pauses)
    }

    @Test
    fun `the server's tag presets, library tags and session bookmarks decode`() {
        val config = ApiJson.decodeFromString(
            ServerConfig.serializer(),
            """{"device":{"id":"d","name":"n","platform":"android","source_device":"phone"},"server_time":"","retention_days":30,
               |"upload":{"chunk_size_bytes":1,"max_recording_bytes":1},"markers":{"tags":["Budget","Scope"]}}
            """.trimMargin()
        )
        assertEquals(listOf("Budget", "Scope"), config.markers?.tags)

        val item = ApiJson.decodeFromString(LibraryItem.serializer(), """{"id":"s","updated_at":"","marker_count":2,"tags":["Budget"]}""")
        assertEquals(2, item.markerCount)
        assertEquals(listOf("Budget"), item.tags)
        val old = ApiJson.decodeFromString(LibraryItem.serializer(), """{"id":"s","updated_at":""}""")
        assertEquals(0, old.markerCount)

        val detail = ApiJson.decodeFromString(
            SessionDetailDto.serializer(),
            """{"playback_url":null,"markers":[{"id":"m1","at_ms":65000,"tag":"Budget","note":null,"source":"device"}],"pauses":[]}"""
        )
        assertEquals(65_000L, detail.markers.single().atMs)
        assertNull(detail.playbackUrl)
    }

    @Test
    fun `a call recording sends who it was with, and nothing when the phone did not see a name`() {
        app.storeRecording(bytes = sampleBytes(2_500))
        app.graph.recordings.update(id) { it.copy(callerName = "Omar Khan", callerSource = "whatsapp_notification") }
        server.on("POST", "/api/sessions", FakeServer.json(201, answer()))
        serveUpload()

        assertEquals(ListenableWorker.Result.success(), run())

        val body = ApiJson.parseToJsonElement(server.requestsTo("POST", "/api/sessions").single().bodyText()).jsonObject
        val caller = body.getValue("caller").jsonObject
        assertEquals("Omar Khan", caller.getValue("name").jsonPrimitive.content)
        assertEquals("whatsapp_notification", caller.getValue("source").jsonPrimitive.content)
        val none = ApiJson.encodeToString(
            com.echoledger.mobile.data.FinishSessionRequest.serializer(),
            com.echoledger.mobile.data.FinishSessionRequest("2026-10-27T10:00:00Z", 1_000, null, emptyList())
        )
        assertTrue(!none.contains("caller"))
    }
}
