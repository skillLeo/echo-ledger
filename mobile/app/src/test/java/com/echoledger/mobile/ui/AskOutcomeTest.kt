package com.echoledger.mobile.ui

import com.echoledger.mobile.core.Problems
import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.pairLocally
import kotlinx.coroutines.runBlocking
import mockwebserver3.MockResponse
import mockwebserver3.SocketEffect
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Ask for the v2 screen (P-089): the failure comes back itself, in words for people, never a bare status. */
class AskOutcomeTest : AppTest() {
    private lateinit var vm: MainViewModel

    @Before
    fun createViewModel() {
        vm = MainViewModel(app).clearedAfterTest()
    }

    private fun ask() = runBlocking { vm.askOutcome("c1", "What did we agree?", "en") }

    @Test
    fun `an answer comes back as the answer`() {
        runBlocking { app.pairLocally(server) }
        server.on("POST", "/api/clients/c1/ask", FakeServer.json(200, """{"data":{"answer":"Delivery on Friday.","citations":[]}}"""))
        val outcome = ask()
        assertTrue(outcome.toString(), outcome is AskOutcome.Answer)
        assertEquals("Delivery on Friday.", (outcome as AskOutcome.Answer).answer.answer)
    }

    @Test
    fun `a server failure without words is said for people, with its status, never as Request failed`() {
        runBlocking { app.pairLocally(server) }
        server.on("POST", "/api/clients/c1/ask", FakeServer.empty(502))
        val failed = ask() as AskOutcome.Failed
        assertEquals(502, failed.status)
        assertFalse(failed.offline)
        assertEquals(Problems.SERVER, failed.words)
        assertFalse(failed.words.contains("Request failed"))
        // Nothing is left in the view model's message for the v2 screen to pick up.
        assertNull(vm.message.value)
    }

    @Test
    fun `a refusal by code says so, and a refusal without words falls back to the plain sentence`() {
        runBlocking { app.pairLocally(server) }
        server.on("POST", "/api/clients/c1/ask", FakeServer.json(409, """{"message":"Provider key missing","code":"ai_unavailable"}"""))
        val notSetUp = ask() as AskOutcome.Failed
        assertEquals(MainViewModel.AI_NOT_SET_UP, notSetUp.words)
        assertEquals("ai_unavailable", notSetUp.code)
        assertEquals(409, notSetUp.status)

        server.on("POST", "/api/clients/c1/ask", FakeServer.empty(429))
        assertEquals(MainViewModel.ASK_FAILED, (ask() as AskOutcome.Failed).words)

        server.on("POST", "/api/clients/c1/ask", FakeServer.json(422, """{"message":"Ask a shorter question.","code":"too_long"}"""))
        assertEquals("Ask a shorter question.", (ask() as AskOutcome.Failed).words)
    }

    @Test
    fun `a cut answer is said for people and is not called offline`() {
        runBlocking { app.pairLocally(server) }
        server.on("POST", "/api/clients/c1/ask", MockResponse.Builder().onRequestStart(SocketEffect.CloseSocket()).build())
        val failed = ask() as AskOutcome.Failed
        assertFalse(failed.offline)
        assertNull(failed.status)
        assertFalse(failed.words.contains("Request failed"))
        assertFalse(failed.words.contains("Exception"))
    }

    @Test
    fun `no connection is offline, in words for people`() {
        for (e in listOf(
            java.net.UnknownHostException("echoledger.example"),
            java.net.ConnectException("refused"),
            java.net.SocketTimeoutException()
        )) {
            val failed = MainViewModel.askFailure(e)
            assertTrue(e.toString(), failed.offline)
            assertNull(failed.status)
            assertEquals(Problems.OFFLINE, failed.words)
        }
    }

    @Test
    fun `not signed in says so`() {
        val failed = ask() as AskOutcome.Failed
        assertEquals(Problems.SIGN_IN, failed.words)
        assertFalse(failed.offline)
    }

    @Test
    fun `the classic screen keeps its old way, null and the message`() {
        runBlocking { app.pairLocally(server) }
        server.on("POST", "/api/clients/c1/ask", FakeServer.json(409, """{"message":"x","code":"ai_unavailable"}"""))
        assertNull(runBlocking { vm.ask("c1", "What did we agree?", "en") })
        assertEquals(MainViewModel.AI_NOT_SET_UP, vm.message.value)
    }

    @Test
    fun `the words come from one place`() {
        assertEquals(Problems.SERVER, MainViewModel.askFailure(ApiException(503, "Request failed (503)")).words)
        assertEquals(MainViewModel.ASK_FAILED, MainViewModel.askFailure(ApiException(404, "Request failed (404)")).words)
    }
}
