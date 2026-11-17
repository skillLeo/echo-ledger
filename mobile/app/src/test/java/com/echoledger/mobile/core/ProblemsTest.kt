package com.echoledger.mobile.core

import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.net.EMPTY_ANSWER
import com.echoledger.mobile.net.NotPairedException
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.SetupText
import java.io.IOException
import java.net.UnknownHostException
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** What went wrong, in words for people (since v1.8.1, bug P-024): never a connection's or an exception's own text. */
class ProblemsTest : AppTest() {
    private val fallback = "The link could not be made."

    @Test
    fun `a failure reads as one plain sentence, whatever threw it`() {
        assertEquals(
            Problems.OFFLINE,
            Problems.text(UnknownHostException("Unable to resolve host \"ledger.example\": No address"), fallback)
        )
        assertEquals(Problems.OFFLINE, Problems.text(IOException("unexpected end of stream on http://10.0.2.2/..."), fallback))
        assertEquals(
            Problems.CERTIFICATE,
            Problems.text(javax.net.ssl.SSLPeerUnverifiedException("Certificate pinning failure!"), fallback)
        )
        assertEquals("the client's own words stay", EMPTY_ANSWER, Problems.text(IOException(EMPTY_ANSWER), fallback))
        assertEquals(Problems.SIGN_IN, Problems.text(NotPairedException(), fallback))
        assertEquals(Problems.SERVER, Problems.text(ApiException(500, "Server Error", null), fallback))
        assertEquals(
            "the server's words for people stay",
            "That link has ended.",
            Problems.text(ApiException(410, "That link has ended.", null), fallback)
        )
        assertEquals(fallback, Problems.text(ApiException(404, "Request failed (404)", null), fallback))
        assertEquals(fallback, Problems.text(ApiException(422, "java.lang.IllegalStateException: boom", null), fallback))
        assertEquals(fallback, Problems.text(NullPointerException("Attempt to invoke virtual method"), fallback))
        assertEquals(fallback, Problems.text(null, fallback))
    }

    @Test
    fun `an offline phone never shows the connection library's text`() {
        runBlocking { app.pairLocally(server) }
        server.close() // nothing answers
        val vm = MainViewModel(app).clearedAfterTest()
        vm.delete(
            com.echoledger.mobile.data.LibraryItem(
                id = "s1",
                status = "completed",
                startedAt = "2026-10-24T09:00:00Z",
                updatedAt = "2026-10-24T09:00:00Z"
            )
        )
        pumpUntil("the message") { vm.message.value != null }
        val shown = vm.message.value!!
        assertEquals(Problems.OFFLINE, shown)
        assertFalse(shown, "Failed to connect" in shown || "localhost" in shown)
    }

    @Test
    fun `the other side test says what happened in plain words`() {
        assertEquals("Works: the other side of calls can be recorded.", SetupText.farSide("ok peak 0.42"))
        assertEquals(
            "Did not work: the other side could not be heard. Copy diagnostics in Help has the details.",
            SetupText.farSide("fail: sink: AudioRecord init failed (-38)")
        )
        assertTrue(SetupText.farSide("fail: hearing the other side needs Android 14 or newer").endsWith("needs Android 14 or newer"))
        assertFalse(SetupText.farSide("fail: test player: IllegalStateException").contains("Exception"))
    }
}
