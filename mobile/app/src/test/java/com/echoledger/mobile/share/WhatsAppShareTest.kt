package com.echoledger.mobile.share

import android.content.Intent
import android.net.Uri
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.WhatsAppSheet
import kotlinx.coroutines.runBlocking
import mockwebserver3.MockResponse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/** v1.6 P5.5: Send to WhatsApp — text, text and voice, or a link; WhatsApp, else WhatsApp Business, else the share sheet. */
class WhatsAppShareTest : ScreenTest() {
    @Test
    fun `WhatsApp first, then WhatsApp Business, else the share sheet`() {
        assertEquals(WhatsAppShare.WHATSAPP, WhatsAppShare.target { true })
        assertEquals(WhatsAppShare.BUSINESS, WhatsAppShare.target { it == WhatsAppShare.BUSINESS })
        assertNull(WhatsAppShare.target { false })
    }

    @Test
    fun `the intents carry the text, the voice as Ogg with read permission, and the app`() {
        val text = WhatsAppShare.textIntent("Me: hello", WhatsAppShare.WHATSAPP)
        assertEquals(Intent.ACTION_SEND, text.action)
        assertEquals("text/plain", text.type)
        assertEquals("Me: hello", text.getStringExtra(Intent.EXTRA_TEXT))
        assertEquals(WhatsAppShare.WHATSAPP, text.`package`)

        val audio = Uri.parse("content://com.echoledger.mobile.exports/exports/a.ogg")
        val voice = WhatsAppShare.voiceIntent(audio, "Me: hello", null)
        assertEquals("audio/ogg", voice.type)
        assertTrue(voice.flags and Intent.FLAG_GRANT_READ_URI_PERMISSION != 0)
        assertNull(voice.`package`)
        assertTrue(WhatsAppShare.clip("x".repeat(WhatsAppShare.MAX_TEXT + 5)).endsWith("(cut: the whole transcript is in EchoLedger)"))
    }

    @Test
    fun `Text only sends the transcript of the view being read`() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/transcripts/T1/export",
            MockResponse.Builder().code(
                200
            ).addHeader("Content-Disposition", "attachment; filename=\"call.txt\"").body("Me: kal milte hain").build()
        )
        show { WhatsAppSheet(vm, "S1", "T1", hasAudio = true, view = "roman", onDismiss = {}) }

        compose.onNodeWithText("Text only").performClick()
        pumpUntil("the share") { shadowOf(app).peekNextStartedActivity() != null }

        val started = shadowOf(app).nextStartedActivity
        val sent = if (started.action ==
            Intent.ACTION_CHOOSER
        ) {
            started.getParcelableExtra(Intent.EXTRA_INTENT, Intent::class.java)!!
        } else {
            started
        }
        assertEquals("Me: kal milte hain", sent.getStringExtra(Intent.EXTRA_TEXT))
        assertEquals("roman", server.requestsTo("GET", "/api/transcripts/T1/export").single().url.queryParameter("view"))
        compose.onNodeWithText("Text and voice").performClick()
        // The voice needs the recording's signed address: none here, so it says so and sends nothing.
        server.on("GET", "/api/sessions/S1", FakeServer.json(200, """{"playback_url":null}"""))
    }
}
