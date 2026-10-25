package com.echoledger.mobile.net

import com.echoledger.mobile.data.CreateSessionRequest
import com.echoledger.mobile.data.DeviceTranscript
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import java.io.IOException
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.SerializationException
import mockwebserver3.MockResponse
import mockwebserver3.SocketEffect
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** How the typed client reports 401, other 4xx, 5xx, malformed, truncated and empty responses. */
class ApiClientErrorHandlingTest {
    private lateinit var server: FakeServer

    @Before
    fun setUp() {
        server = FakeServer()
    }

    @After
    fun tearDown() {
        server.close()
    }

    private fun client() = ApiClient(server.baseUrl, emptyList(), "7|eld_0123456789abcdef")

    private val sessionRequest = CreateSessionRequest("ref", "whatsapp", null, null, "2026-10-24T09:00:00Z", null, null, null, emptyList())

    private val endpoints: List<Pair<String, suspend (ApiClient) -> Any?>> = listOf(
        "register" to { c -> c.register("ABCD-1234", "Pixel", "0.1.3", "16") },
        "config" to { c -> c.config() },
        "clients" to { c -> c.clients() },
        "sttToken" to { c -> c.sttToken("live_captions", listOf("me"), null) },
        "createSession" to { c -> c.createSession(sessionRequest) },
        "recordingStatus" to { c -> c.recordingStatus("r1") },
        "uploadChunk" to { c -> c.uploadChunk("r1", 0, byteArrayOf(1, 2, 3), "00") },
        "complete" to { c -> c.complete("r1") },
        "postTranscript" to { c -> c.postTranscript("s1", DeviceTranscript("live", "assemblyai", null, emptyList())) },
        "library" to { c -> c.library(null, null) },
        "transcript" to { c -> c.transcript("t1") },
        "sessionDetail" to { c -> c.sessionDetail("s1") },
        "deleteSession" to { c -> c.deleteSession("s1") }
    )

    private fun failure(call: suspend (ApiClient) -> Any?): Throwable =
        runCatching { runBlocking { call(client()) } }.exceptionOrNull() ?: throw AssertionError("expected the call to fail")

    // ---- 401: the device was revoked ------------------------------------------------------------------------------

    @Test
    fun `every endpoint maps 401 to NotPairedException`() {
        server.onAny(FakeServer.json(401, """{"message":"Unauthenticated."}"""))
        endpoints.forEach { (name, call) ->
            assertTrue("$name: ${failure(call)}", failure(call) is NotPairedException)
        }
    }

    @Test
    fun `a 401 with an HTML or empty body is still a revoked device`() {
        server.onAny(MockResponse.Builder().code(401).addHeader("Content-Type", "text/html").body("<html>Unauthorized</html>").build())
        assertTrue(failure { it.config() } is NotPairedException)
        server.onAny(FakeServer.empty(401))
        assertTrue(failure { it.config() } is NotPairedException)
    }

    @Test
    fun `NotPairedException is an IOException so retry loops treat it as a stoppable failure`() {
        server.onAny(FakeServer.json(401, "{}"))
        val error = failure { it.config() }
        assertTrue(error is IOException)
        assertEquals("This phone is not paired with an EchoLedger server.", error.message)
    }

    // ---- other 4xx ---------------------------------------------------------------------------------------------------

    @Test
    fun `a validation error surfaces the server message and status`() {
        server.onAny(FakeServer.json(422, ServerFixtures.validationError("The pairing code is invalid, expired or already used.")))

        val error = failure { it.register("ZZZZ-ZZZZ", "Pixel", "0.1.3", "16") } as ApiException

        assertEquals(422, error.status)
        assertEquals("The pairing code is invalid, expired or already used.", error.message)
    }

    @Test
    fun `the missing chunks answer of complete is a 422 ApiException`() {
        server.onAny(FakeServer.json(422, """{"message":"Upload is incomplete.","missing_chunks":[3,4]}"""))

        val error = failure { it.complete("r1") } as ApiException

        assertEquals(422, error.status)
        assertEquals("Upload is incomplete.", error.message)
    }

    @Test
    fun `403 404 409 and 413 keep their status codes`() {
        mapOf(
            403 to """{"message":"Invalid ability provided."}""",
            404 to """{"message":"No query results for model [App\\Models\\Recording] 01JX."}""",
            409 to """{"message":"This recording is already complete."}""",
            413 to """{"message":"Chunk too large."}"""
        ).forEach { (status, body) ->
            server.onAny(FakeServer.json(status, body))
            val error = failure { it.uploadChunk("r1", 0, ByteArray(4), "00") } as ApiException
            assertEquals(status, error.status)
            assertTrue("$status: ${error.message}", error.message!!.isNotBlank() && !error.message!!.startsWith("Request failed"))
        }
    }

    @Test
    fun `a 429 is an ApiException that the upload policy treats as transient`() {
        server.onAny(
            MockResponse.Builder().code(
                429
            ).addHeader(
                "Retry-After",
                "42"
            ).addHeader("Content-Type", "application/json").body("""{"message":"Too Many Attempts."}""").build()
        )

        val error = failure { it.config() } as ApiException

        assertEquals(429, error.status)
        assertEquals("Too Many Attempts.", error.message)
        assertFalse(UploadWorker.isPermanent(error.status))
    }

    @Test
    fun `an error without a message field falls back to a generic message with the status`() {
        server.onAny(FakeServer.json(400, """{"error":"nope"}"""))
        assertEquals("Request failed (400)", failure { it.config() }.message)
    }

    @Test
    fun `an error body that is not JSON falls back to a generic message`() {
        server.onAny(
            MockResponse.Builder().code(
                413
            ).addHeader("Content-Type", "text/html").body("<html><h1>413 Request Entity Too Large</h1></html>").build()
        )
        val error = failure { it.config() } as ApiException
        assertEquals(413, error.status)
        assertEquals("Request failed (413)", error.message)
    }

    @Test
    fun `a message that is not a string does not crash error parsing`() {
        server.onAny(FakeServer.json(422, """{"message":{"nested":true},"errors":[]}"""))
        val error = failure { it.config() }
        assertTrue(error.toString(), error is ApiException)
    }

    // ---- 5xx ---------------------------------------------------------------------------------------------------------

    @Test
    fun `server errors with a JSON body keep the server message`() {
        server.onAny(FakeServer.json(500, """{"message":"Server Error"}"""))
        val error = failure { it.library(null, null) } as ApiException
        assertEquals(500, error.status)
        assertEquals("Server Error", error.message)
        assertFalse(UploadWorker.isPermanent(error.status))
    }

    @Test
    fun `gateway errors with an HTML body fall back to the generic message`() {
        listOf(502, 503, 504).forEach { status ->
            server.onAny(
                MockResponse.Builder().code(
                    status
                ).addHeader("Content-Type", "text/html").body("<html><body>Bad gateway</body></html>").build()
            )
            val error = failure { it.config() } as ApiException
            assertEquals(status, error.status)
            assertEquals("Request failed ($status)", error.message)
            assertFalse(UploadWorker.isPermanent(status))
        }
    }

    @Test
    fun `a 503 for a debug page larger than a megabyte is read without failing`() {
        server.onAny(MockResponse.Builder().code(503).body("x".repeat(2 * 1024 * 1024)).build())
        assertEquals(503, (failure { it.config() } as ApiException).status)
    }

    // ---- malformed, truncated and empty successes ---------------------------------------------------------------------

    @Test
    fun `a 200 with malformed JSON is an error and never a half-parsed object`() {
        server.onAny(FakeServer.json(200, """{"device": {"id": "d", """))
        val error = failure { it.config() }
        assertTrue(error.toString(), error is SerializationException || error is IllegalArgumentException)
    }

    @Test
    fun `a 200 HTML page from a captive portal is an error`() {
        server.onAny(
            MockResponse.Builder().code(
                200
            ).addHeader("Content-Type", "text/html").body("<html><body>Sign in to Wi-Fi</body></html>").build()
        )
        val error = failure { it.library(null, null) }
        assertTrue(error.toString(), error is SerializationException || error is IllegalArgumentException)
    }

    @Test
    fun `a 200 whose required fields have the wrong type is an error`() {
        server.onAny(FakeServer.json(200, """{"data":"not a list","server_time":5}"""))
        assertTrue(failure { it.library(null, null) } is SerializationException)
    }

    @Test
    fun `a 200 that is missing required fields is an error`() {
        server.onAny(FakeServer.json(200, """{"data":[]}"""))
        assertTrue(failure { it.library(null, null) } is SerializationException)
    }

    @Test
    fun `a body cut off mid-transfer is an IOException so it is retried as a network fault`() {
        val body = ServerFixtures.config()
        server.onAny(
            MockResponse.Builder().code(200).addHeader("Content-Type", "application/json").body(body)
                .setHeader("Content-Length", body.length + 400)
                .onResponseEnd(SocketEffect.CloseSocket()).build()
        )
        assertTrue(failure { it.config() } is IOException)
    }

    @Test
    fun `a connection closed before any response is an IOException`() {
        server.onAny(MockResponse.Builder().code(200).onResponseStart(SocketEffect.CloseSocket()).build())
        assertTrue(failure { it.clients() } is IOException)
    }

    @Test
    fun `a server that is not listening is an IOException`() {
        val dead = FakeServer()
        val address = dead.baseUrl
        dead.close()
        val error = runCatching { runBlocking { ApiClient(address, emptyList(), "t").config() } }.exceptionOrNull()
        assertTrue(error.toString(), error is IOException)
    }

    @Test
    fun `an empty 200 body for a typed endpoint fails rather than returning a blank object`() {
        server.onAny(FakeServer.empty(200))
        endpoints.filter {
            it.first in setOf("config", "clients", "library", "transcript", "sessionDetail", "createSession")
        }.forEach { (name, call) ->
            assertTrue("$name should fail on an empty body", runCatching { runBlocking { call(client()) } }.isFailure)
        }
    }

    @Test
    fun `empty success bodies are fine for endpoints that return nothing`() {
        server.onAny(FakeServer.empty(204))
        runBlocking {
            client().complete("r1")
            client().deleteSession("s1")
            client().postTranscript("s1", DeviceTranscript("live", "assemblyai", null, emptyList()))
        }
    }

    @Test
    fun `unknown fields at every level are ignored`() {
        server.on(
            "GET",
            "/api/clients",
            FakeServer.json(
                200,
                """{"data":[{"id":"c1","name":"Acme","keyterm_count":2,"future":{"a":[1,2,3]}}],"meta":{"page":1},"links":null}"""
            )
        )
        val clients = runBlocking { client().clients() }
        assertEquals("Acme", clients.data.single().name)
    }

    @Test
    fun `a null where a list is expected is an error, not an empty list`() {
        server.on(
            "GET",
            "/api/recordings/r1",
            FakeServer.json(200, """{"id":"r1","role":"primary","upload":{"total_chunks":1,"received_chunks":null,"status":"pending"}}""")
        )

        val error = runCatching { runBlocking { client().recordingStatus("r1") } }.exceptionOrNull()

        assertTrue(error.toString(), error is SerializationException)
    }

    @Test
    fun `a rejected server address never reaches the network`() {
        assertThrows(IllegalArgumentException::class.java) { ApiClient("http://echo.example.com", emptyList(), "t") }
        assertThrows(IllegalArgumentException::class.java) { ApiClient("ftp://echo.example.com", emptyList(), "t") }
        assertThrows(IllegalArgumentException::class.java) { ApiClient("", emptyList(), "t") }
        ApiClient("https://echo.example.com", emptyList(), "t")
        ApiClient("http://10.0.2.2:8000", emptyList(), "t")
        ApiClient("http://localhost:8000", emptyList(), null)
    }

    @Test
    fun `a 5xx never reports a revoked device`() {
        server.onAny(FakeServer.json(500, "{}"))
        endpoints.forEach { (name, call) ->
            val error = failure(call)
            assertTrue("$name: $error", error is ApiException && error.status == 500)
        }
    }

    @Test
    fun `failed calls send exactly one request`() {
        server.onAny(FakeServer.json(500, "{}"))
        failure { it.config() }
        assertEquals(1, server.requests.size)
        assertNull(server.requests.single().headers["Upload-Chunk-Index"])
    }
}
