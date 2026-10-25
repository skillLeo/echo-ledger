package com.echoledger.mobile.net

import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ServerFixtures
import java.io.IOException
import javax.net.ssl.SSLPeerUnverifiedException
import kotlinx.coroutines.runBlocking
import mockwebserver3.Dispatcher
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import mockwebserver3.RecordedRequest
import okhttp3.CertificatePinner
import okhttp3.OkHttpClient
import okhttp3.tls.HandshakeCertificates
import okhttp3.tls.HeldCertificate
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** SPKI pinning end to end over a real TLS handshake with a throw-away certificate. */
class ApiClientPinningTest {
    private lateinit var held: HeldCertificate
    private lateinit var server: MockWebServer
    private lateinit var clientCertificates: HandshakeCertificates
    private lateinit var base: String

    @Before
    fun setUp() {
        held = HeldCertificate.Builder().commonName("localhost").addSubjectAlternativeName("localhost").build()
        val serverCertificates = HandshakeCertificates.Builder().heldCertificate(held).build()
        clientCertificates = HandshakeCertificates.Builder().addTrustedCertificate(held.certificate).build()
        server = MockWebServer()
        server.useHttps(serverCertificates.sslSocketFactory())
        server.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse = FakeServer.json(200, ServerFixtures.config())
        }
        server.start()
        base = "https://localhost:${server.port}"
    }

    @After
    fun tearDown() {
        server.close()
    }

    private val correctPin get() = CertificatePinner.pin(held.certificate)

    private fun pinnedClient(vararg pins: String): ApiClient {
        val http = OkHttpClient.Builder()
            .sslSocketFactory(clientCertificates.sslSocketFactory(), clientCertificates.trustManager)
            .apply { Pins.pinnerFor(base, pins.toList())?.let { certificatePinner(it) } }
            .build()
        return ApiClient(base, pins.toList(), "7|eld_token", http)
    }

    @Test
    fun `the pin computed from the certificate matches the app pin format`() {
        assertTrue(correctPin, Pins.normalize(listOf(correctPin)) == listOf(correctPin))
    }

    @Test
    fun `a request to a server presenting the pinned key succeeds`() = runBlocking {
        val config = pinnedClient(correctPin).config()
        assertEquals(30, config.retentionDays)
    }

    @Test
    fun `either the current or the backup pin is enough`() = runBlocking {
        assertEquals(30, pinnedClient(ServerFixtures.PIN_A, correctPin).config().retentionDays)
        assertEquals(30, pinnedClient(correctPin, ServerFixtures.PIN_B).config().retentionDays)
    }

    @Test
    fun `a server presenting a different key than the pinned ones is refused`() {
        val client = pinnedClient(ServerFixtures.PIN_A, ServerFixtures.PIN_B)

        val error = assertThrows(IOException::class.java) { runBlocking { client.config() } }

        // OkHttp reports the first failed route (here the IPv6 loopback) and attaches the others as suppressed.
        val pinFailure = generateSequence(listOf<Throwable>(error)) { level ->
            level.flatMap {
                it.suppressed.toList() +
                    listOfNotNull(it.cause)
            }
        }
            .takeWhile { it.isNotEmpty() }
            .flatten()
            .filterIsInstance<SSLPeerUnverifiedException>()
            .firstOrNull()
        assertNotNull("expected a certificate pinning failure, got $error", pinFailure)
        assertTrue(pinFailure!!.message.orEmpty(), pinFailure.message.orEmpty().contains("Certificate pinning failure"))
        assertEquals("no request may reach the dispatcher after a pin failure", 0, server.requestCount)
    }

    @Test
    fun `malformed pins are ignored rather than pinning to nothing`() = runBlocking {
        val client = pinnedClient("sha256/short", "nonsense", "")
        assertEquals(30, client.config().retentionDays)
    }

    @Test
    fun `pins for another host do not affect this server`() = runBlocking {
        val http = OkHttpClient.Builder()
            .sslSocketFactory(clientCertificates.sslSocketFactory(), clientCertificates.trustManager)
            .certificatePinner(Pins.pinnerFor("https://other.example.com", listOf(ServerFixtures.PIN_A))!!)
            .build()
        assertEquals(30, ApiClient(base, emptyList(), "t", http).config().retentionDays)
    }

    @Test
    fun `the default client pins the server host and nothing else`() {
        val client = ApiClient("https://echo.example.com", listOf(ServerFixtures.PIN_A, ServerFixtures.PIN_B), "t")

        val pins = client.http.certificatePinner.pins
        assertEquals(2, pins.size)
        assertEquals(setOf("echo.example.com"), pins.map { it.pattern }.toSet())
        assertEquals(setOf("sha256"), pins.map { it.hashAlgorithm }.toSet())
    }

    @Test
    fun `the default client is not pinned when the server sent no valid pins`() {
        assertTrue(ApiClient("https://echo.example.com", emptyList(), "t").http.certificatePinner.pins.isEmpty())
        assertTrue(ApiClient("https://echo.example.com", listOf("junk"), "t").http.certificatePinner.pins.isEmpty())
        assertTrue(ApiClient("https://echo.example.com", listOf(ServerFixtures.PIN_A), "t").http.certificatePinner.pins.isNotEmpty())
    }

    @Test
    fun `the default client uses the documented timeouts`() {
        val http = ApiClient("https://echo.example.com", emptyList(), "t").http
        assertEquals(10_000, http.connectTimeoutMillis)
        assertEquals(60_000, http.readTimeoutMillis)
        assertEquals(120_000, http.writeTimeoutMillis)
    }
}
