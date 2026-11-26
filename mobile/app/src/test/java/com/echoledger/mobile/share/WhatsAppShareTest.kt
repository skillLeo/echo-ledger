package com.echoledger.mobile.share

import android.content.ActivityNotFoundException
import android.content.ContextWrapper
import android.content.Intent
import android.content.pm.ApplicationInfo
import android.content.pm.PackageInfo
import android.net.Uri
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.R
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

    private fun failingContext(error: RuntimeException) = object : ContextWrapper(app) {
        override fun startActivity(intent: Intent) = throw error
    }

    @Test
    fun `when neither WhatsApp nor the share sheet can open, nothing is sent and it says so`() {
        for (error in listOf(ActivityNotFoundException(), SecurityException("not allowed"))) {
            val said = mutableListOf<String>()
            val intent = WhatsAppShare.textIntent("Me: hello", WhatsAppShare.WHATSAPP)
            assertFalse(WhatsAppShare.send(failingContext(error), intent, said::add))
            assertEquals(listOf(app.getString(R.string.v2_rec_wa_not_opened)), said)
        }
    }

    @Test
    fun `a choice that fails says Not sent, and one closed first says so too`() {
        val said = mutableListOf<String>()
        assertFalse(runBlocking { WhatsAppShare.attempt(app, said::add) { throw IllegalStateException("boom") } })
        assertEquals(listOf("Not sent: something went wrong. Try again."), said)
        said.clear()
        val closed = runCatching {
            runBlocking { WhatsAppShare.attempt(app, said::add) { throw kotlinx.coroutines.CancellationException("closed") } }
        }
        assertTrue(closed.exceptionOrNull() is kotlinx.coroutines.CancellationException)
        assertEquals(listOf("Not sent: the share was closed before it was ready."), said)
    }

    @Test
    fun `Text and voice sends one message with the voice attached from the cache share folder and the text`() {
        install(WhatsAppShare.WHATSAPP)
        val said = mutableListOf<String>()
        var saved: File? = null
        val sent = runBlocking {
            WhatsAppShare.sendTextAndVoice(app, "Me: kal milte hain", WhatsAppShare.WHATSAPP, save = { dir ->
                File(dir, "EchoLedger recording.m4a").apply { writeText("ftyp") }.also { saved = it }
            }, say = said::add)
        }
        assertTrue(sent)
        val intent = shadowOf(app).nextStartedActivity
        assertEquals(Intent.ACTION_SEND, intent.action)
        assertEquals("audio/*", intent.type)
        assertEquals(WhatsAppShare.WHATSAPP, intent.`package`)
        val uri = intent.getParcelableExtra(Intent.EXTRA_STREAM, Uri::class.java)!!
        assertEquals("content", uri.scheme)
        assertEquals("${app.packageName}.exports", uri.authority)
        assertTrue(uri.toString(), uri.path!!.startsWith("/whatsapp/") && uri.path!!.endsWith("/EchoLedger recording.m4a"))
        assertEquals("audio/mp4", app.contentResolver.getType(uri))
        assertEquals("Me: kal milte hain", intent.getStringExtra(Intent.EXTRA_TEXT))
        assertTrue(intent.flags and Intent.FLAG_GRANT_READ_URI_PERMISSION != 0)
        assertEquals(uri, intent.clipData!!.getItemAt(0).uri)
        assertEquals(listOf("The transcript is on the clipboard too, in case WhatsApp drops it."), said)
        // The copy stays for WhatsApp to read after the chat is picked.
        assertTrue(saved!!.isFile)
        assertNull(shadowOf(app).nextStartedActivity)
    }

    @Test
    fun `the shared voice file has the audio type its extension gives`() {
        val dir = File(WhatsAppShare.shareDir(app), "t").apply { mkdirs() }
        val types = mapOf("m4a" to "audio/mp4", "ogg" to "audio/ogg", "mp3" to "audio/mpeg")
        for ((ext, type) in types) {
            val file = File(dir, "EchoLedger recording.$ext").apply { writeText("x") }
            val uri = androidx.core.content.FileProvider.getUriForFile(app, "${app.packageName}.exports", file)
            assertEquals(ext, type, app.contentResolver.getType(uri))
        }
        val wav = androidx.core.content.FileProvider.getUriForFile(
            app,
            "${app.packageName}.exports",
            File(dir, "EchoLedger recording.wav").apply { writeText("x") }
        )
        assertTrue(app.contentResolver.getType(wav)!!.startsWith("audio/"))
    }

    @Test
    fun `the voice's extension comes from its type, else the recording's, else m4a`() {
        assertEquals("m4a", WhatsAppShare.voiceExtension("audio/mp4"))
        assertEquals("m4a", WhatsAppShare.voiceExtension("audio/aac"))
        assertEquals("m4a", WhatsAppShare.voiceExtension("audio/x-m4a"))
        assertEquals("ogg", WhatsAppShare.voiceExtension("audio/ogg; codecs=opus"))
        assertEquals("ogg", WhatsAppShare.voiceExtension("audio/opus"))
        assertEquals("mp3", WhatsAppShare.voiceExtension("audio/mpeg"))
        assertEquals("wav", WhatsAppShare.voiceExtension("audio/wav"))
        assertEquals("ogg", WhatsAppShare.voiceExtension("application/octet-stream", known = "ogg"))
        assertEquals("m4a", WhatsAppShare.voiceExtension("application/octet-stream", known = "bin"))
        assertEquals("m4a", WhatsAppShare.voiceExtension(null))
    }

    @Test
    fun `the downloaded voice is named for its type`() {
        runBlocking { app.pairLocally(server) }
        var type: String? = null
        var path = "/audio/S1"
        server.on("GET", "/api/sessions/S1") { FakeServer.json(200, """{"playback_url":"${server.baseUrl}$path"}""") }
        server.on("GET", "/audio/.*") {
            MockResponse.Builder().code(200).apply { type?.let { addHeader("Content-Type", it) } }.body("voice").build()
        }
        val dir = File(app.cacheDir, "dl").apply { mkdirs() }
        fun name(t: String?, at: String = "/audio/S1"): String? {
            type = t
            path = at
            return runBlocking { vm.downloadAudio("S1", "EchoLedger recording", dir) }?.name
        }
        assertEquals("EchoLedger recording.m4a", name("audio/mp4"))
        assertEquals("EchoLedger recording.m4a", name("audio/aac"))
        assertEquals("EchoLedger recording.ogg", name("audio/ogg"))
        assertEquals("EchoLedger recording.mp3", name("audio/mpeg"))
        assertEquals("EchoLedger recording.wav", name("audio/wav"))
        assertEquals("EchoLedger recording.ogg", name("application/octet-stream", at = "/audio/S1.ogg"))
        assertEquals("EchoLedger recording.m4a", name("application/octet-stream"))
    }

    @Test
    fun `a voice that could not be saved sends nothing and says so`() {
        val said = mutableListOf<String>()
        assertFalse(runBlocking { WhatsAppShare.sendTextAndVoice(app, "Me: hello", null, save = { null }, say = said::add) })
        assertEquals(emptyList<String>(), said) // the saver said why
        val empty = runBlocking {
            WhatsAppShare.sendTextAndVoice(app, "Me: hello", null, save = { dir ->
                File(dir, "cut.m4a").apply { writeBytes(ByteArray(0)) }
            }, say = said::add)
        }
        assertFalse(empty)
        assertEquals(listOf("The voice could not be saved, so nothing was sent. Try again."), said)
        assertTrue(WhatsAppShare.shareDir(app).listFiles().orEmpty().isEmpty())
        assertNull(shadowOf(app).nextStartedActivity)
    }

    @Test
    fun `old voice copies are cleared from the share folder only, and the next share leaves the newest`() {
        val now = System.currentTimeMillis()
        val dir = WhatsAppShare.shareDir(app).apply { mkdirs() }
        fun copy(name: String, age: Long) = File(dir, name).apply { mkdirs() }.also {
            File(it, "EchoLedger recording.m4a").writeText("a")
            it.setLastModified(now - age)
        }
        val old = copy("old", WhatsAppShare.KEEP_MS + 1_000)
        val earlier = copy("earlier", 2 * 60_000)
        val reading = copy("reading", 10_000)
        val elsewhere = File(app.cacheDir, "exports").apply { mkdirs() }.let { File(it, "old.txt") }
            .apply { writeText("c") }.also { it.setLastModified(now - 2 * WhatsAppShare.KEEP_MS) }

        assertEquals(1, WhatsAppShare.clearOldCopies(app))
        assertFalse(old.exists())
        assertTrue(earlier.exists())
        assertTrue(reading.exists())
        assertTrue(elsewhere.exists())

        // The next share clears the copies WhatsApp is done with, not one it may still be reading.
        runBlocking { WhatsAppShare.sendTextAndVoice(app, "x", null, save = { null }, say = {}) }
        assertFalse(earlier.exists())
        assertTrue(reading.exists())
        assertTrue(elsewhere.exists())
    }

    /** The transcript, the recording's signed address and its voice, which waits for [gate]. */
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
        server.on("GET", "/api/sessions/S1", FakeServer.json(200, """{"playback_url":"${server.baseUrl}/audio/S1"}"""))
        server.on("GET", "/audio/S1") {
            gate.await(15, TimeUnit.SECONDS)
            MockResponse.Builder().code(audioCode).addHeader("Content-Type", "audio/mp4").body("ftyp-voice").build()
        }
    }

    private fun has(text: String) = runCatching { compose.onNodeWithText(text).fetchSemanticsNode() }.isSuccess

    private fun sharedFiles() = WhatsAppShare.shareDir(app).walk().filter { it.isFile }.toList()

    private val waitNote = "Wait: the voice is still saving."

    @Test
    fun `classic - while the voice is saved only Text and voice shows it, the others are disabled and say wait`() {
        install(WhatsAppShare.BUSINESS)
        val gate = CountDownLatch(1)
        scriptVoice(gate)
        var dismissed = false
        show { WhatsAppSheet(vm, "S1", "T1", hasAudio = true, view = null, onDismiss = { dismissed = true }) }
        try {
            compose.onNodeWithText("Send to WhatsApp Business").assertExists()
            compose.onNodeWithText("Text only").assertIsEnabled()
            compose.onNodeWithText("Text and voice").performClick()
            pumpUntil("the saving state") { has("Saving the voice…") }
            compose.onNodeWithText("Text only").assertIsDisplayed().assertIsNotEnabled()
            compose.onNodeWithText("Link (works for 7 days)").assertIsDisplayed().assertIsNotEnabled()
            compose.onNodeWithText("Text only").performClick()
            compose.waitForIdle()
            assertEquals(waitNote, vm.message.value)
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
        val uri = sent.getParcelableExtra(Intent.EXTRA_STREAM, Uri::class.java)!!
        assertEquals("content", uri.scheme)
        assertEquals("audio/mp4", app.contentResolver.getType(uri))
        assertEquals("Me: kal milte hain", sent.getStringExtra(Intent.EXTRA_TEXT))
        pumpUntil("the sheet to close") { dismissed }
        assertEquals("The transcript is on the clipboard too, in case WhatsApp drops it.", vm.message.value)
        assertEquals(1, server.requestsTo("GET", "/api/transcripts/T1/export").size)
        assertTrue(server.requestsTo("POST", "/api/.*").isEmpty())
        assertNull(shadowOf(app).nextStartedActivity)
    }

    @Test
    fun `classic - a voice that cannot be downloaded sends nothing and says so`() {
        scriptVoice(CountDownLatch(0), audioCode = 500)
        show { WhatsAppSheet(vm, "S1", "T1", hasAudio = true, view = null, onDismiss = {}) }
        compose.onNodeWithText("Text and voice").performClick()
        pumpUntil("the message") { vm.message.value != null }
        assertEquals("The voice could not be downloaded. Check the connection and try again.", vm.message.value)
        assertNull(shadowOf(app).peekNextStartedActivity())
        assertTrue(sharedFiles().isEmpty())
        // The sheet is ready again.
        pumpUntil("the choice back") { has("Text and voice") }
    }

    @Test
    fun `classic - closing the sheet while the voice saves sends nothing and says Not sent`() {
        val gate = CountDownLatch(1)
        scriptVoice(gate)
        var open by mutableStateOf(true)
        show { if (open) WhatsAppSheet(vm, "S1", "T1", hasAudio = true, view = null, onDismiss = { open = false }) }
        try {
            compose.onNodeWithText("Text and voice").performClick()
            pumpUntil("the saving state") { has("Saving the voice…") }
            pumpUntil("the voice to be asked for") { server.requestsTo("GET", "/audio/S1").isNotEmpty() }
            open = false
            // Said at once: the download is cancelled, not waited for.
            pumpUntil("Not sent") { vm.message.value == "Not sent: the share was closed before it was ready." }
        } finally {
            gate.countDown()
        }
        Thread.sleep(300)
        compose.waitForIdle()
        assertNull(shadowOf(app).peekNextStartedActivity())
        assertTrue(sharedFiles().isEmpty())
    }

    private fun showV2(onDismiss: () -> Unit) {
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
                    onDismiss = onDismiss
                )
            }
        }
    }

    @Test
    fun `v2 - while the voice is saved only its row shows it, the others are disabled, and the voice is attached`() {
        install(WhatsAppShare.WHATSAPP)
        val gate = CountDownLatch(1)
        scriptVoice(gate)
        var dismissed = false
        showV2 { dismissed = true }
        try {
            compose.onNodeWithText("Text only").assertIsEnabled()
            compose.onNodeWithText("Text and voice").performClick()
            pumpUntil("the saving state") { has("Saving the voice…") }
            assertFalse(has("Text and voice"))
            for (other in listOf("Text only", "Link (works for 7 days)", "Share link")) {
                compose.onNodeWithText(other).assertIsDisplayed().assertIsNotEnabled()
            }
            assertFalse(has("Loading"))
            compose.onNodeWithText("Text only").performClick()
            compose.waitForIdle()
            assertEquals(waitNote, vm.message.value)
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
    fun `v2 - the saving row only says Saving the voice, without a second loading word`() {
        val gate = CountDownLatch(1)
        scriptVoice(gate)
        showV2 {}
        try {
            compose.onNodeWithText("Text and voice").performClick()
            pumpUntil("the saving state") { has("Saving the voice…") }
            val row = compose.onNodeWithText("Saving the voice…", useUnmergedTree = false).fetchSemanticsNode()
            assertFalse(row.config.contains(SemanticsProperties.StateDescription))
        } finally {
            gate.countDown()
        }
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
    }
}
