package com.echoledger.mobile.share

import android.content.Intent
import android.content.pm.ApplicationInfo
import android.content.pm.PackageInfo
import android.net.Uri
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.WhatsAppSheet
import com.echoledger.mobile.ui.v2.V2Testing
import com.echoledger.mobile.ui.v2.library.RecordingShareSheet
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.io.File
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import mockwebserver3.MockResponse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * v1.6 P5.5: Send to WhatsApp — text, text and voice, or a link; WhatsApp, else WhatsApp Business, else the share sheet.
 * U15: Text and voice is one message with the voice attached, in both designs.
 */
class WhatsAppShareTest : ScreenTest() {
    @Before
    fun freshFileProvider() = V2Testing.freshFileProvider()

    @Test
    fun `WhatsApp first, then WhatsApp Business, else the share sheet`() {
        assertEquals(WhatsAppShare.WHATSAPP, WhatsAppShare.target { true })
        assertEquals(WhatsAppShare.BUSINESS, WhatsAppShare.target { it == WhatsAppShare.BUSINESS })
        assertNull(WhatsAppShare.target { false })
    }

    @Test
    fun `the intents carry the text, the voice as audio with read permission and its ClipData, and the app`() {
        val text = WhatsAppShare.textIntent("Me: hello", WhatsAppShare.WHATSAPP)
        assertEquals(Intent.ACTION_SEND, text.action)
        assertEquals("text/plain", text.type)
        assertEquals("Me: hello", text.getStringExtra(Intent.EXTRA_TEXT))
        assertEquals(WhatsAppShare.WHATSAPP, text.`package`)

        val audio = Uri.parse("content://com.echoledger.mobile.exports/whatsapp/a.ogg")
        val voice = WhatsAppShare.voiceIntent(audio, "Me: hello", null)
        assertEquals("audio/*", voice.type)
        assertEquals(audio, voice.getParcelableExtra(Intent.EXTRA_STREAM, Uri::class.java))
        assertTrue(voice.flags and Intent.FLAG_GRANT_READ_URI_PERMISSION != 0)
        assertEquals(audio, voice.clipData!!.getItemAt(0).uri)
        assertNull(voice.`package`)
        assertTrue(WhatsAppShare.clip("x".repeat(WhatsAppShare.MAX_TEXT + 5)).endsWith("(cut: the whole transcript is in EchoLedger)"))
    }

    private fun install(vararg packages: String) = packages.forEach { pkg ->
        shadowOf(app.packageManager).installPackage(
            PackageInfo().apply {
                packageName = pkg
                applicationInfo = ApplicationInfo().apply { packageName = pkg }
            }
        )
    }

    /** What [WhatsAppShare.start] handed to Android, through the target the installed apps give. */
    private fun startedFor(vararg installed: String): Intent {
        install(*installed)
        val target = WhatsAppShare.target(WhatsAppShare.installed(app))
        WhatsAppShare.start(app, WhatsAppShare.textIntent("Me: hello", target))
        return shadowOf(app).nextStartedActivity
    }

    @Test
    fun `the message goes to WhatsApp, else WhatsApp Business, else the system chooser`() {
        assertEquals(WhatsAppShare.WHATSAPP, startedFor(WhatsAppShare.WHATSAPP, WhatsAppShare.BUSINESS).`package`)
    }

    @Test
    fun `without WhatsApp the message goes to WhatsApp Business`() {
        assertEquals(WhatsAppShare.BUSINESS, startedFor(WhatsAppShare.BUSINESS).`package`)
    }

    @Test
    fun `without either app the system chooser opens with the message`() {
        val started = startedFor()
        assertEquals(Intent.ACTION_CHOOSER, started.action)
        val inner = started.getParcelableExtra(Intent.EXTRA_INTENT, Intent::class.java)!!
        assertEquals(Intent.ACTION_SEND, inner.action)
        assertNull(inner.`package`)
        assertEquals("Me: hello", inner.getStringExtra(Intent.EXTRA_TEXT))
    }

    @Test
    fun `Text and voice sends one message with the voice attached from the cache share folder and the text`() {
        install(WhatsAppShare.WHATSAPP)
        val sent = runBlocking {
            WhatsAppShare.sendTextAndVoice(app, "Me: kal milte hain", WhatsAppShare.WHATSAPP, save = { dir ->
                File(dir, "EchoLedger recording.ogg").apply { writeText("OggS") }
            }, notSaved = { error("saved") })
        }
        assertTrue(sent)
        val intent = shadowOf(app).nextStartedActivity
        assertEquals(Intent.ACTION_SEND, intent.action)
        assertEquals("audio/*", intent.type)
        assertEquals(WhatsAppShare.WHATSAPP, intent.`package`)
        val uri = intent.getParcelableExtra(Intent.EXTRA_STREAM, Uri::class.java)!!
        assertEquals("content", uri.scheme)
        assertEquals("${app.packageName}.exports", uri.authority)
        assertTrue(uri.toString(), uri.path!!.startsWith("/whatsapp/"))
        assertEquals("Me: kal milte hain", intent.getStringExtra(Intent.EXTRA_TEXT))
        assertTrue(intent.flags and Intent.FLAG_GRANT_READ_URI_PERMISSION != 0)
        assertEquals(uri, intent.clipData!!.getItemAt(0).uri)
        // The copy stays for WhatsApp to read after the chat is picked.
        assertTrue(File(WhatsAppShare.shareDir(app), "EchoLedger recording.ogg").isFile)
        assertNull(shadowOf(app).nextStartedActivity)
    }

    @Test
    fun `a voice that could not be saved sends nothing and says so`() {
        var said = 0
        val notSaved = runBlocking {
            WhatsAppShare.sendTextAndVoice(app, "Me: hello", null, save = { null }, notSaved = { said++ })
        }
        assertFalse(notSaved)
        assertEquals(0, said) // the saver said why
        val empty = runBlocking {
            WhatsAppShare.sendTextAndVoice(app, "Me: hello", null, save = { dir ->
                File(dir, "cut.ogg").apply { writeBytes(ByteArray(0)) }
            }, notSaved = { said++ })
        }
        assertFalse(empty)
        assertEquals(1, said)
        assertFalse(File(WhatsAppShare.shareDir(app), "cut.ogg").exists())
        assertNull(shadowOf(app).nextStartedActivity)
    }

    @Test
    fun `old voice copies are cleared from the share folder only`() {
        val now = System.currentTimeMillis()
        val dir = WhatsAppShare.shareDir(app).apply { mkdirs() }
        val old = File(dir, "old.ogg").apply { writeText("a") }.also { it.setLastModified(now - WhatsAppShare.KEEP_MS - 1_000) }
        val fresh = File(dir, "fresh.ogg").apply { writeText("b") }.also { it.setLastModified(now - 60_000) }
        val elsewhere = File(app.cacheDir, "exports").apply { mkdirs() }.let { File(it, "old.txt") }
            .apply { writeText("c") }.also { it.setLastModified(now - 2 * WhatsAppShare.KEEP_MS) }

        assertEquals(1, WhatsAppShare.clearOldCopies(app))
        assertFalse(old.exists())
        assertTrue(fresh.exists())
        assertTrue(elsewhere.exists())

        // The next share clears every earlier copy before saving its own.
        runBlocking { WhatsAppShare.sendTextAndVoice(app, "x", null, save = { null }, notSaved = {}) }
        assertFalse(fresh.exists())
        assertTrue(elsewhere.exists())
    }

    /** The transcript, the recording's signed address and its audio, which waits for [gate]. */
    private fun scriptVoice(gate: CountDownLatch, audioCode: Int = 200) {
        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/transcripts/T1/export",
            MockResponse.Builder().code(200)
                .addHeader("Content-Disposition", "attachment; filename=\"call.txt\"")
                .body("Me: kal milte hain")
                .build()
        )
        server.on("GET", "/api/sessions/S1", FakeServer.json(200, """{"playback_url":"${server.baseUrl}/audio/S1.ogg"}"""))
        server.on("GET", "/audio/S1.ogg") {
            gate.await(15, TimeUnit.SECONDS)
            MockResponse.Builder().code(audioCode).body("OggS-voice").build()
        }
    }

    private fun has(text: String) = runCatching { compose.onNodeWithText(text).fetchSemanticsNode() }.isSuccess

    @Test
    fun `classic - while the voice is saved only Text and voice shows it, the others look as before but wait`() {
        install(WhatsAppShare.BUSINESS)
        val gate = CountDownLatch(1)
        scriptVoice(gate)
        var dismissed = false
        show { WhatsAppSheet(vm, "S1", "T1", hasAudio = true, view = null, onDismiss = { dismissed = true }) }
        try {
            compose.onNodeWithText("Send to WhatsApp Business").assertExists()
            compose.onNodeWithText("Text and voice").performClick()
            pumpUntil("the saving state") { has(WhatsAppShare.SAVING_VOICE) }
            compose.onNodeWithText("Text only").assertIsEnabled()
            compose.onNodeWithText("Link (works for 7 days)").assertIsEnabled()
            compose.onNodeWithText("Text only").performClick()
            compose.onNodeWithText("Link (works for 7 days)").performClick()
            compose.waitForIdle()
            assertNull(shadowOf(app).peekNextStartedActivity())
        } finally {
            gate.countDown()
        }
        pumpUntil("the message") { shadowOf(app).peekNextStartedActivity() != null }
        val sent = shadowOf(app).nextStartedActivity
        assertEquals("audio/*", sent.type)
        assertEquals(WhatsAppShare.BUSINESS, sent.`package`)
        assertEquals("content", sent.getParcelableExtra(Intent.EXTRA_STREAM, Uri::class.java)!!.scheme)
        assertEquals("Me: kal milte hain", sent.getStringExtra(Intent.EXTRA_TEXT))
        pumpUntil("the sheet to close") { dismissed }
        assertEquals(1, server.requestsTo("GET", "/api/transcripts/T1/export").size)
        assertTrue(server.requestsTo("POST", "/api/.*").isEmpty())
        assertNull(shadowOf(app).nextStartedActivity)
    }

    @Test
    fun `classic - a voice that cannot be downloaded sends nothing and says so`() {
        val gate = CountDownLatch(0)
        scriptVoice(gate, audioCode = 500)
        show { WhatsAppSheet(vm, "S1", "T1", hasAudio = true, view = null, onDismiss = {}) }
        compose.onNodeWithText("Text and voice").performClick()
        pumpUntil("the message") { vm.message.value != null }
        assertEquals("The audio could not be downloaded. Check the connection and try again.", vm.message.value)
        assertNull(shadowOf(app).peekNextStartedActivity())
        assertTrue(WhatsAppShare.shareDir(app).listFiles().orEmpty().isEmpty())
    }

    @Test
    fun `v2 - while the voice is saved only its row shows it, the sheet stays, and the voice is attached`() {
        install(WhatsAppShare.WHATSAPP)
        val gate = CountDownLatch(1)
        scriptVoice(gate)
        var dismissed = false
        val item = LibraryItem(id = "S1", title = "Bilal Traders", updatedAt = "2026-11-17T09:00:00+00:00")
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                RecordingShareSheet(
                    vm,
                    item,
                    shareText = { "Me: kal milte hain" },
                    transcriptId = "T1",
                    hasAudio = true,
                    view = null,
                    onDismiss = { dismissed = true }
                )
            }
        }
        try {
            compose.onNodeWithText("Text and voice").performClick()
            pumpUntil("the saving state") { has("Saving the voice…") }
            assertFalse(has("Text and voice"))
            compose.onNodeWithText("Text only").assertIsEnabled()
            compose.onNodeWithText("Link (works for 7 days)").assertIsEnabled()
            assertFalse(has("Loading"))
            compose.onNodeWithText("Text only").performClick()
            compose.onNodeWithText("Share link").performClick()
            compose.waitForIdle()
            assertFalse(dismissed)
            assertNull(shadowOf(app).peekNextStartedActivity())
        } finally {
            gate.countDown()
        }
        pumpUntil("the message") { shadowOf(app).peekNextStartedActivity() != null }
        val sent = shadowOf(app).nextStartedActivity
        assertEquals(Intent.ACTION_SEND, sent.action)
        assertEquals("audio/*", sent.type)
        assertEquals(WhatsAppShare.WHATSAPP, sent.`package`)
        assertEquals("content", sent.getParcelableExtra(Intent.EXTRA_STREAM, Uri::class.java)!!.scheme)
        assertEquals("Me: kal milte hain", sent.getStringExtra(Intent.EXTRA_TEXT))
        pumpUntil("the sheet to close") { dismissed }
        assertEquals(1, server.requestsTo("GET", "/api/transcripts/T1/export").size)
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
