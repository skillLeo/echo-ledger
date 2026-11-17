package com.echoledger.mobile.help

import android.app.Application
import androidx.test.core.app.ApplicationProvider
import com.echoledger.mobile.core.Problems
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.ui.v2.help.GuideHit
import com.echoledger.mobile.ui.v2.help.HelpResult
import java.util.concurrent.LinkedBlockingQueue
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.Dispatcher
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import mockwebserver3.RecordedRequest
import mockwebserver3.SocketEffect
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** Help v2 on the phone's API client (contract 2.0.0-d), against a scripted server. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], application = Application::class)
class PhoneHelpSourceTest {
    private lateinit var server: MockWebServer
    private val requests = LinkedBlockingQueue<RecordedRequest>()
    private var answer: (RecordedRequest) -> MockResponse = { json(200, "{}") }
    private val context get() = ApplicationProvider.getApplicationContext<Application>()
    private lateinit var reports: DeviceReports
    private lateinit var help: PhoneHelpSource

    private fun json(code: Int, body: String) =
        MockResponse.Builder().code(code).addHeader("Content-Type", "application/json").body(body).build()

    @Before
    fun setUp() {
        server = MockWebServer()
        server.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse {
                requests += request
                return answer(request)
            }
        }
        server.start()
        context.getSharedPreferences("device_reports", 0).edit().clear().commit()
        reports = DeviceReports(context) { java.time.Instant.parse("2026-11-17T09:30:00Z") }
        help = PhoneHelpSource({ ApiClient(server.url("/").toString(), emptyList(), "7|token") }, reports)
    }

    @After
    fun tearDown() = server.close()

    private fun next(): RecordedRequest = requests.poll(5, TimeUnit.SECONDS) ?: error("no request")

    @Test
    fun `guides are listed, searched and opened`() = runBlocking {
        answer = {
            json(
                200,
                """{"data":[{"id":"after-a-call","title":"After a call","excerpt":"What happens when a call ends."},
                   {"id":"setup","title":"Setup","excerpt":""}]}"""
            )
        }
        val all = help.guides("  ") as HelpResult.Ok
        assertEquals(
            listOf(GuideHit("after-a-call", "After a call", "What happens when a call ends."), GuideHit("setup", "Setup", null)),
            all.value
        )
        assertNull("no q without a query", next().url.queryParameter("q"))

        help.guides("after call")
        val searched = next()
        assertEquals("/api/help/guides", searched.url.encodedPath)
        assertEquals("after call", searched.url.queryParameter("q"))
        assertEquals("Bearer 7|token", searched.headers["Authorization"])

        answer = { json(200, """{"id":"after-a-call","title":"After a call","markdown":"# After a call\nThe card asks."}""") }
        val guide = (help.guide("after-a-call") as HelpResult.Ok).value
        assertEquals("After a call", guide.title)
        assertTrue(guide.markdown.startsWith("# After a call"))
        assertEquals("/api/help/guides/after-a-call", next().url.encodedPath)
    }

    @Test
    fun `an unknown guide says so in plain words`() = runBlocking {
        answer = { json(404, """{"code":"not_found","message":"Not found."}""") }
        val failed = help.guide("gone") as HelpResult.Failed
        assertFalse(failed.offline)
        assertEquals(PhoneHelpSource.NOT_FOUND, failed.words)
    }

    @Test
    fun `what's new asks for Android's notes`() = runBlocking {
        answer = {
            json(
                200,
                """{"data":[{"version":"1.8.1","platform":"android","released_at":"2026-11-10T08:00:00Z","notes":"- Faster upload"}]}"""
            )
        }
        val notes = (help.whatsNew() as HelpResult.Ok).value.single()
        assertEquals("1.8.1", notes.version)
        assertEquals("2026-11-10T08:00:00Z", notes.releasedAt)
        assertEquals("- Faster upload", notes.notes)
        val request = next()
        assertEquals("/api/help/whats-new", request.url.encodedPath)
        assertEquals("android", request.url.queryParameter("platform"))
    }

    @Test
    fun `a support message is sent, with the device report only when there is one`() = runBlocking {
        answer = { MockResponse.Builder().code(202).build() }
        assertNull(help.lastReportAt())
        assertTrue(help.send("Upload stuck", "It says waiting.", includeReport = true) is HelpResult.Ok)
        val first = Json.parseToJsonElement(next().body!!.utf8()).jsonObject
        assertEquals("Upload stuck", first["subject"]!!.jsonPrimitive.content)
        assertEquals("It says waiting.", first["message"]!!.jsonPrimitive.content)
        assertEquals("no report was ever sent, so none goes", "false", first["include_device_report"]!!.jsonPrimitive.content)

        reports.sent()
        assertEquals("2026-11-17T09:30:00Z", help.lastReportAt())
        help.send("Again", "x".repeat(6000), includeReport = true)
        val second = Json.parseToJsonElement(next().body!!.utf8()).jsonObject
        assertEquals("true", second["include_device_report"]!!.jsonPrimitive.content)
        assertEquals(5000, second["message"]!!.jsonPrimitive.content.length)
    }

    @Test
    fun `the last report's time is kept across restarts`() {
        reports.sent()
        assertEquals("2026-11-17T09:30:00Z", DeviceReports(context).lastAt())
    }

    @Test
    fun `refusals are said in plain words, never as a status code`() = runBlocking {
        answer = { json(429, """{"code":"too_many_requests","message":"Too Many Attempts."}""") }
        assertEquals(PhoneHelpSource.TOO_MANY, (help.send("a", "b", false) as HelpResult.Failed).words)

        answer = { json(403, """{"code":"email_not_verified","message":"Your email address is not verified."}""") }
        assertEquals(PhoneHelpSource.NOT_VERIFIED, (help.send("a", "b", false) as HelpResult.Failed).words)

        answer = { json(503, """{"code":"server_busy","message":"Busy."}""") }
        assertEquals(PhoneHelpSource.BUSY, (help.send("a", "b", false) as HelpResult.Failed).words)

        answer = { MockResponse.Builder().code(500).build() }
        val server = help.whatsNew() as HelpResult.Failed
        assertEquals(Problems.SERVER, server.words)
        assertFalse(server.words!!.contains("Request failed"))
        assertFalse(server.offline)

        answer = { MockResponse.Builder().code(400).build() }
        assertEquals(PhoneHelpSource.GUIDES_FAILED, (help.guides("x") as HelpResult.Failed).words)
    }

    @Test
    fun `no connection is offline`() = runBlocking {
        val closed = server.port
        server.close()
        val away = PhoneHelpSource({ ApiClient("http://127.0.0.1:$closed", emptyList(), "7|token") }, reports)
        val failed = away.guides("") as HelpResult.Failed
        assertTrue(failed.offline)
        assertEquals(Problems.OFFLINE, failed.words)
    }

    @Test
    fun `a cut answer is plain words and not offline`() = runBlocking {
        answer = { MockResponse.Builder().onRequestStart(SocketEffect.CloseSocket()).build() }
        val failed = help.guides("") as HelpResult.Failed
        assertFalse(failed.offline)
        assertFalse(failed.words!!.contains("Exception"))
    }

    @Test
    fun `not signed in says so`() = runBlocking {
        val failed = PhoneHelpSource({ null }, reports).whatsNew() as HelpResult.Failed
        assertEquals(Problems.SIGN_IN, failed.words)
        assertFalse(failed.offline)
    }
}
