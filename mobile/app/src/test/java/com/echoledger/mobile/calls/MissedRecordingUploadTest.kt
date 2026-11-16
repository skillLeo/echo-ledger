package com.echoledger.mobile.calls

import androidx.work.ListenableWorker
import androidx.work.testing.TestListenableWorkerBuilder
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import java.io.File
import java.time.Instant
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * `POST /api/devices/missed-recordings` (v1.8 0.2): what the phone sends, read against the contract in docs/API.md
 * itself, and how the queue behaves (a retry keeps its `client_ref`; refusals stop; offline waits).
 */
class MissedRecordingUploadTest : AppTest() {
    private val contract: String by lazy {
        val api = File("../../docs/API.md").readText()
        val start = api.indexOf("### `POST /api/devices/missed-recordings`")
        check(start >= 0) { "the contract section is missing from docs/API.md" }
        api.substring(start, api.indexOf("\n### ", start + 10))
    }

    private fun ticks(text: String): List<String> = Regex("`([^`]+)`").findAll(text).map { it.groupValues[1] }.toList()

    /** The bullet of the contract that starts with [start], up to the next bullet. */
    private fun bullet(start: String): String {
        val from = contract.indexOf(start)
        check(from >= 0) { "no bullet $start in the contract" }
        val next = contract.indexOf("\n- ", from + 1).let { if (it < 0) contract.length else it }
        return contract.substring(from, next)
    }

    private val required by lazy { ticks(bullet("- Required:").substringAfter("Required:").substringBefore("Optional:")).toSet() }
    private val optional by lazy {
        ticks(bullet("- Required:").substringAfter("Optional:")).filter { !it.contains(' ') }.toSet() - "detail"
    }
    private val reasons by lazy { ticks(bullet("- `reason`:")).drop(1).toSet() }
    private val sources by lazy {
        ticks(bullet("- `platform` as elsewhere")).filter { it.matches(Regex("[a-z_]+")) }.toSet() - "source_app" -
            "platform"
    }

    private val now = System.currentTimeMillis()

    private fun item(
        ref: String = "4f5c1d7e-0c1a-4b7e-9a51-2f3c4d5e6f70",
        app: String = "whatsapp",
        reason: String = "helper_not_armed",
        seconds: Long? = 312
    ) = MissedItem(ref, app, now - 3_600_000, seconds, reason, MissedReason.of(reason).detail, now)

    @Test
    fun `the contract is read, its fields, reasons and apps`() {
        assertEquals(setOf("client_ref", "platform", "source_app", "call_started_at", "reason"), required)
        assertEquals(setOf("app_version", "call_duration_s"), optional)
        assertEquals(MissedReason.entries.map { it.wire }.toSet(), reasons)
        assertTrue(
            "every app the phone records is a source the server takes",
            com.echoledger.mobile.core.SourceApp.entries.all {
                it.wire in
                    sources
            }
        )
        assertTrue(contract.contains("`${MissedRecordings.CLIENT_REF.pattern}`"))
    }

    @Test
    fun `the payload holds only the contract's fields, in their types and limits, and no free text`() {
        listOf(
            item(),
            item(app = "phone_call", reason = "mic_busy", seconds = 99_999),
            item(app = "zoom", reason = "unknown", seconds = null)
        ).forEach { it ->
            val body = MissedRecordings.payload(it, "1.8.0-a-very-long-build-name-that-goes-on-and-on")
            assertTrue("required present: ${body.keys}", body.keys.containsAll(required))
            assertEquals("nothing but the contract's fields", emptySet<String>(), body.keys - required - optional)
            assertFalse("the server keeps no free text", "detail" in body.keys)
            assertTrue(MissedRecordings.CLIENT_REF.matches(body.string("client_ref")))
            assertEquals("android", body.string("platform"))
            assertTrue(body.string("source_app") in sources)
            assertTrue(body.string("reason") in reasons)
            assertTrue(body.string("app_version").length <= 32)
            val at = body.string("call_started_at")
            assertTrue("ISO-8601 in UTC: $at", at.endsWith("Z"))
            assertEquals(Instant.ofEpochMilli(it.startedAtMs).epochSecond, Instant.parse(at).epochSecond)
            val length = body["call_duration_s"]
            if (it.durationS == null) {
                assertEquals(JsonNull, length)
            } else {
                assertTrue((length as JsonPrimitive).intOrNull!! in 0..86_400)
            }
        }
    }

    @Test
    fun `nothing in the payload names a person or a number`() {
        val body = MissedRecordings.payload(item(), "1.8.0").toString()
        assertFalse(Regex("\\+?\\d{7,}").containsMatchIn(body.replace(Regex("\"call_started_at\":\"[^\"]+\""), "")))
    }

    private fun JsonObject.string(key: String) = getValue(key).jsonPrimitive.content

    private fun upload(): ListenableWorker.Result = runBlocking { TestListenableWorkerBuilder<MissedUploadWorker>(app).build().doWork() }

    private fun sentBodies() = server.requestsTo("POST", "/api/devices/missed-recordings").map {
        ApiJson.parseToJsonElement(it.bodyText()).jsonObject
    }

    @Test
    fun `an accepted report is sent once and keeps the server's id`() {
        runBlocking { app.pairLocally(server) }
        server.on("POST", "/api/devices/missed-recordings", FakeServer.json(201, """{"data":{"id":"01JMISSED0000000000000000A"}}"""))
        MissedRecordingStore.add(
            app,
            listOf(MissedRecordings.Found("whatsapp", now - 3_600_000, 312, MissedReason.DETECTION, MissedReason.DETECTION.detail))
        )

        assertEquals(ListenableWorker.Result.success(), upload())
        val stored = MissedRecordingStore.all(app).single()
        assertTrue(stored.sent)
        assertEquals("01JMISSED0000000000000000A", stored.serverId)
        assertEquals(stored.ref, sentBodies().single().string("client_ref"))
        assertEquals("detection", sentBodies().single().string("reason"))

        upload()
        assertEquals("nothing is sent twice", 1, sentBodies().size)
    }

    @Test
    fun `a failed try is retried with the same client_ref, so the server never makes a second item`() {
        runBlocking { app.pairLocally(server) }
        var calls = 0
        server.on("POST", "/api/devices/missed-recordings") {
            calls++
            if (calls ==
                1
            ) {
                FakeServer.json(503, """{"message":"Try again","code":"unavailable"}""")
            } else {
                FakeServer.json(200, """{"data":{"id":"01JMISSED0000000000000000B"}}""")
            }
        }
        MissedRecordingStore.add(
            app,
            listOf(MissedRecordings.Found("zoom", now - 60_000, 90, MissedReason.UNKNOWN, MissedReason.UNKNOWN.detail))
        )

        assertEquals(ListenableWorker.Result.retry(), upload())
        assertTrue(MissedRecordingStore.all(app).single().waiting)
        assertEquals(ListenableWorker.Result.success(), upload())
        val refs = sentBodies().map { it.string("client_ref") }
        assertEquals(2, refs.size)
        assertEquals(1, refs.toSet().size)
        assertTrue(MissedRecordingStore.all(app).single().sent)
    }

    @Test
    fun `a refusal stops for good, a rate limit and no connection wait`() {
        runBlocking { app.pairLocally(server) }
        server.on("POST", "/api/devices/missed-recordings", FakeServer.json(422, """{"message":"Invalid","code":"validation_failed"}"""))
        MissedRecordingStore.add(
            app,
            listOf(MissedRecordings.Found("whatsapp", now - 60_000, 90, MissedReason.CRASH, MissedReason.CRASH.detail))
        )
        assertEquals(ListenableWorker.Result.success(), upload())
        assertTrue(MissedRecordingStore.all(app).single().refused)
        upload()
        assertEquals("a refused report is never sent again", 1, sentBodies().size)

        MissedRecordingStore.clear(app)
        server.on("POST", "/api/devices/missed-recordings", FakeServer.json(429, """{"message":"Slow down","code":"rate_limited"}"""))
        MissedRecordingStore.add(
            app,
            listOf(MissedRecordings.Found("zoom", now - 60_000, 90, MissedReason.CRASH, MissedReason.CRASH.detail))
        )
        assertEquals(ListenableWorker.Result.retry(), upload())
        assertTrue(MissedRecordingStore.all(app).single().waiting)

        server.close() // offline
        assertEquals(ListenableWorker.Result.retry(), upload())
        assertTrue(MissedRecordingStore.all(app).single().waiting)
    }

    @Test
    fun `a call older than the server takes is never sent, and nothing goes out before sign-in`() {
        MissedRecordingStore.add(
            app,
            listOf(MissedRecordings.Found("zoom", now - 60_000, 90, MissedReason.CRASH, MissedReason.CRASH.detail))
        )
        assertEquals("not signed in: it waits on the phone", ListenableWorker.Result.success(), upload())
        assertTrue(MissedRecordingStore.all(app).single().waiting)

        runBlocking { app.pairLocally(server) }
        MissedRecordingStore.clear(app)
        MissedRecordingStore.add(app, listOf(MissedRecordings.Found("zoom", now - 29L * 24 * 3_600_000, 90, MissedReason.CRASH, "")))
        MissedRecordingStore.change(app, MissedRecordingStore.all(app).single().ref) { it.copy(startedAtMs = now - 31L * 24 * 3_600_000) }
        upload()
        assertEquals(emptyList<Any>(), sentBodies())
        assertNull(MissedRecordingStore.all(app).singleOrNull { it.waiting })
    }
}
