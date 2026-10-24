package com.echoledger.mobile.net

import kotlinx.coroutines.runBlocking
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

class ApiClientTest {
    private lateinit var server: MockWebServer

    @Before
    fun setUp() {
        server = MockWebServer()
        server.start()
    }

    @After
    fun tearDown() {
        server.close()
    }

    private fun baseUrl(): String = server.url("/").toString().trimEnd('/')

    @Test
    fun `register posts the pairing code and returns the device token`() = runBlocking {
        server.enqueue(
            MockResponse.Builder().code(200).body(
                """
                {
                  "device": {"id":"dev_1","name":"Pixel","platform":"android","source_device":"phone"},
                  "token": "eld_secret_token",
                  "config": {
                    "device": {"id":"dev_1","name":"Pixel","platform":"android","source_device":"phone"},
                    "server_time": "2026-10-24T00:00:00Z",
                    "retention_days": 30,
                    "upload": {"chunk_size_bytes": 1048576, "max_recording_bytes": 2147483648, "accepted_mimes": ["audio/ogg"]},
                    "pins": ["sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="]
                  }
                }
                """.trimIndent()
            ).build()
        )

        val client = ApiClient(baseUrl(), pins = emptyList(), token = null)
        val response = client.register("ABC-123", "Pixel", "0.1.0", "15")

        assertEquals("eld_secret_token", response.token)
        assertEquals("dev_1", response.device.id)
        assertEquals("phone", response.device.sourceDevice)
        assertEquals(1, response.config.pins.size)

        val recorded = server.takeRequest()
        assertEquals("POST", recorded.method)
        assertTrue(recorded.target.endsWith("/api/devices/register"))
    }

    @Test
    fun `library parses items and tombstones with the sync cursor`() = runBlocking {
        server.enqueue(
            MockResponse.Builder().code(200).body(
                """
                {
                  "data": [
                    {"id":"s1","source_device":"desktop","title":"Call with Acme","started_at":"2026-10-20T10:00:00Z","updated_at":"2026-10-20T10:05:00Z"},
                    {"id":"s2","deleted":true,"updated_at":"2026-10-21T08:00:00Z"}
                  ],
                  "next_cursor": null,
                  "server_time": "2026-10-24T00:00:00Z"
                }
                """.trimIndent()
            ).build()
        )

        val client = ApiClient(baseUrl(), pins = emptyList(), token = "eld_secret_token")
        val page = client.library(since = null, cursor = null)

        assertEquals(2, page.data.size)
        assertEquals("Call with Acme", page.data[0].title)
        assertTrue(page.data[1].deleted)
        assertEquals("2026-10-24T00:00:00Z", page.serverTime)

        val recorded = server.takeRequest()
        assertEquals("Bearer eld_secret_token", recorded.headers["Authorization"])
    }
}
