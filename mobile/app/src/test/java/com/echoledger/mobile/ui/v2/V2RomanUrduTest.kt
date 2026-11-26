package com.echoledger.mobile.ui.v2

import android.content.ClipboardManager
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.RowViewsDto
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.library.V2RecordingScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * U19 on the v2 screens: an Urdu recording is read in Roman Urdu by default. While its view is made the page and the
 * Library row say "Converting to Roman Urdu… 60%" with a small progress ring (also before the server started it); once it is
 * ready the lines swap, Copy, Copy with timestamps and Copy for AI (its Language line too) use it, and the row's snippet
 * is the server's Roman snippet. A view that failed says "Couldn't convert to Roman Urdu · Try again".
 */
class V2RomanUrduTest : ScreenTest() {
    private val making =
        """{"status":"making","available":["roman","english"],"default":"roman","progress":{"roman":{"done":3,"total":5}}}"""
    private val ready =
        """{"status":"ready","available":["roman","english"],"default":"roman","roman":["Hello ji.","Shukriya call karne ka."],"english":null}"""

    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("POST", "/api/transcripts/.+/views", FakeServer.json(202, making))
    }

    /** The transcript says its default view is Roman Urdu ([status]); the views answer [answers] in turn, then the last. */
    private fun views(status: String, vararg answers: String) {
        server.on(
            "GET",
            "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}",
            FakeServer.json(
                200,
                ServerFixtures.transcript().trimEnd().removeSuffix("}") +
                    ""","views":{"available":["roman","english"],"status":"$status","default":"roman"}}"""
            )
        )
        var asked = 0
        server.on("GET", "/api/transcripts/.+/views") { FakeServer.json(200, answers[minOf(asked++, answers.size - 1)]) }
    }

    private fun item(views: RowViewsDto?) = LibraryItem(
        id = ServerFixtures.SESSION_ID,
        sourceDevice = "phone",
        sourceApp = "whatsapp",
        title = "Call with Acme",
        client = NamedRef("01JCLIENT0000000000000000A", "Acme Holdings"),
        startedAt = "2026-10-20T10:00:00+00:00",
        durationMs = 2_530_000,
        status = "completed",
        transcript = TranscriptSummary(
            finalStatus = "completed",
            finalTranscriptId = ServerFixtures.TRANSCRIPT_ID,
            finalLanguage = "ur",
            preview = "Hello there.",
            views = views
        ),
        updatedAt = "2026-10-20T10:05:00+00:00"
    )

    private fun open(item: LibraryItem, language: EchoLanguage = EchoLanguage.English) = show {
        EchoV2Theme(darkTheme = false, language = language) {
            val toasts = rememberEchoToastState()
            CompositionLocalProvider(LocalEchoToasts provides toasts) {
                androidx.compose.foundation.layout.Box {
                    V2RecordingScreen(vm, item, onBack = {})
                    EchoToastHost(toasts)
                }
            }
        }
    }

    private fun press(words: String) {
        val node = compose.onAllNodes((hasText(words) or hasContentDescription(words)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    private fun awaitText(words: String) = pumpUntil("\"$words\"", 30_000) {
        compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    /** Waits for [words] while the page's 5 s polls run (the test clock moves a second at a time). */
    private fun awaitPolled(words: String) = pumpUntil("\"$words\" after the polls", 30_000) {
        compose.mainClock.advanceTimeBy(1_000)
        compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    private fun clipboard(): String? = app.getSystemService(ClipboardManager::class.java).primaryClip?.getItemAt(0)?.text?.toString()

    @Test
    fun thePageSaysItIsConvertingThenSwapsToRomanUrduAndEveryCopyUsesIt() {
        views("making", making, making, ready)
        // Once the view is written the server has the automatic title in Roman Urdu too.
        val renamed = ServerFixtures.libraryItem(ServerFixtures.SESSION_ID, title = "Acme se rate ki baat")
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(listOf(renamed))))
        open(item(RowViewsDto("making", "roman", mapOf("roman" to com.echoledger.mobile.data.ProgressDto(3, 5)))))
        awaitText("Converting to Roman Urdu… 60%")
        awaitText("Hi, thanks for calling.")
        awaitPolled("Shukriya call karne ka.")
        awaitText("Acme se rate ki baat")
        compose.onAllNodesWithText("Hi, thanks for calling.").assertCountEquals(0)
        press("Copy text without timestamps")
        pumpUntil("text in Roman Urdu") { clipboard()?.contains("Shukriya call karne ka.") == true }
        press("More ways to copy")
        press("Copy with timestamps")
        pumpUntil("timestamps in Roman Urdu") { clipboard()?.let { it.contains("0:12") && it.contains("Shukriya") } == true }
        press("More ways to copy")
        press("Copy for AI")
        pumpUntil("for AI in Roman Urdu") { clipboard()?.startsWith("Title:") == true }
        val forAi = clipboard()!!
        assertTrue(forAi, forAi.contains("Language: Roman Urdu"))
        assertTrue(forAi, forAi.contains("Shukriya call karne ka."))
        assertTrue(forAi, !forAi.contains("Hi, thanks for calling."))
    }

    @Test
    fun beforeTheServerCountsTheLinesItSaysConvertingWithoutAPercent() {
        views("making", """{"status":"making","available":["roman","english"],"default":"roman","progress":{}}""")
        open(item(RowViewsDto("making", "roman")))
        awaitText("Converting to Roman Urdu…")
        compose.onAllNodesWithText("%", substring = true).assertCountEquals(0)
    }

    @Test
    fun aViewThatIsNotOnItsWayIsNeverAskedForOnOpening() {
        views("none", """{"status":"none","available":["roman","english"],"default":"roman"}""")
        open(item(RowViewsDto("none", "roman")))
        awaitText("Hi, thanks for calling.")
        // An older recording, or one whose plan has no views: opening it never makes (or bills) its views.
        compose.onAllNodesWithText("Converting", substring = true).assertCountEquals(0)
        assertTrue(server.requestsTo("POST", "/api/transcripts/.+/views").isEmpty())
        assertTrue(server.requestsTo("GET", "/api/transcripts/.+/views").isEmpty())
    }

    @Test
    fun inUrduThePageSaysItIsConvertingInItsOwnWords() {
        views("making", making)
        open(item(RowViewsDto("making", "roman")), EchoLanguage.Urdu)
        awaitText("رومن اردو میں تبدیل ہو رہا ہے…")
    }

    @Test
    fun aViewThatFailedSaysSoAndTryAgainAsksForItAgain() {
        views("failed", """{"status":"failed","available":["roman","english"],"default":"roman","error":"x"}""", making)
        open(item(RowViewsDto("failed", "roman")))
        // The page's words stop before "Try again": its button follows.
        awaitText("Couldn't convert to Roman Urdu.")
        compose.onAllNodesWithText("· Try again", substring = true).assertCountEquals(0)
        assertTrue(server.requestsTo("POST", "/api/transcripts/.+/views").isEmpty())
        press("Try again")
        pumpUntil("asked again") { server.requestsTo("POST", "/api/transcripts/.+/views").isNotEmpty() }
        awaitText("Converting to Roman Urdu…")
    }

    private fun library(
        rowViews: String,
        preview: String = "Hello there.",
        previewView: String = "original",
        updatedAt: String = java.time.OffsetDateTime.now().toString(),
        /** False: the Library is left (another tab), true: it opens again. */
        open: androidx.compose.runtime.State<Boolean>? = null
    ) {
        val row = ServerFixtures.libraryItem(
            ServerFixtures.SESSION_ID,
            title = "Bilal Traders",
            preview = preview,
            client = null,
            updatedAt = updatedAt
        )
            .replace("\"preview\":\"$preview\"", "\"preview\":\"$preview\",\"preview_view\":\"$previewView\",\"views\":$rowViews")
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(listOf(row))))
        runBlocking { app.graph.library.refresh() }
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    if (open?.value != false) V2LibraryScreen(vm, initials = "AS", onOpen = {}, onTrash = {}, onAccount = {})
                }
            }
        }
        awaitText("Bilal Traders")
    }

    @Test
    fun aLibraryRowSaysItIsConvertingInPlaceOfItsSnippet() {
        library("""{"status":"making","default":"roman","progress":{"roman":{"done":3,"total":5}}}""")
        awaitText("Converting to Roman Urdu… 60%")
        compose.onAllNodesWithText("Hello there.", substring = true).assertCountEquals(0)
    }

    @Test
    fun aLibraryRowWhoseRomanUrduIsReadyShowsTheServersRomanSnippet() {
        library(
            """{"status":"ready","default":"roman","progress":{}}""",
            preview = "Hello ji. Shukriya call karne ka.",
            previewView = "roman"
        )
        awaitText("Hello ji. Shukriya call karne ka.")
        compose.onAllNodesWithText("Converting", substring = true).assertCountEquals(0)
        assertEquals("roman", vm.library.value.single().transcript?.previewView)
        // The row needs no views of its own.
        assertTrue(server.requestsTo("GET", "/api/transcripts/.+/views").isEmpty())
    }

    @Test
    fun aConvertingRowAsksAgainUntilItsRomanSnippetArrives() {
        library("""{"status":"making","default":"roman","progress":{}}""")
        awaitText("Converting to Roman Urdu…")
        val later = java.time.OffsetDateTime.now().plusMinutes(1).toString()
        val row = ServerFixtures.libraryItem(
            ServerFixtures.SESSION_ID,
            title = "Bilal Traders",
            preview = "Hello ji.",
            client = null,
            updatedAt = later
        )
            .replace(
                "\"preview\":\"Hello ji.\"",
                "\"preview\":\"Hello ji.\",\"preview_view\":\"roman\",\"views\":{\"status\":\"ready\",\"default\":\"roman\",\"progress\":{}}"
            )
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(listOf(row))))
        // The Library asks again every 5 s while something is converting.
        awaitPolled("Hello ji.")
        compose.onAllNodesWithText("Converting", substring = true).assertCountEquals(0)
    }

    @Test
    fun aLibraryRowWhoseViewFailedSaysSo() {
        library("""{"status":"failed","default":"roman","progress":{}}""")
        awaitText("Couldn't convert to Roman Urdu · Try again")
    }

    @Test
    fun aRowStillConvertingTwentyMinutesOnReadsItsViewsOnceAndStopsAsking() {
        views(
            "failed",
            """{"status":"failed","available":["roman","english"],"default":"roman","error":"Converting took too long. Try again."}"""
        )
        val old = java.time.OffsetDateTime.now().minusMinutes(30).toString()
        library("""{"status":"making","default":"roman","progress":{}}""", updatedAt = old)
        // The server has stopped it, which no sync brings: the row reads its views once and says so.
        awaitText("Couldn't convert to Roman Urdu · Try again")
        assertEquals(1, server.requestsTo("GET", "/api/transcripts/.+/views").size)
        val asked = server.requestsTo("GET", "/api/library").size
        repeat(12) {
            compose.mainClock.advanceTimeBy(1_000)
            compose.waitForIdle()
        }
        assertEquals("no 5 s refresh for a stopped view", asked, server.requestsTo("GET", "/api/library").size)
        val now = java.time.Instant.now()
        val row = vm.library.value.single()
        assertTrue(
            com.echoledger.mobile.ui.v2.library.LibraryModel.viewsStale(
                row.copy(transcript = row.transcript?.copy(views = com.echoledger.mobile.data.RowViewsDto("making", "roman"))),
                now
            )
        )
        assertTrue(
            !com.echoledger.mobile.ui.v2.library.LibraryModel.viewsStale(
                row.copy(
                    updatedAt = now.toString(),
                    transcript = row.transcript?.copy(views = com.echoledger.mobile.data.RowViewsDto("making", "roman"))
                ),
                now
            )
        )
    }

    @Test
    fun aStoppedViewIsReadAgainAboutFiveAndFifteenMinutesOnAndWhenTheLibraryOpens() {
        // The server still says making (its clean-up has not run yet), so the row stays stale.
        views("making", """{"status":"making","available":["roman","english"],"default":"roman","progress":{}}""")
        val old = java.time.OffsetDateTime.now().minusMinutes(30).toString()
        val open = androidx.compose.runtime.mutableStateOf(true)
        library("""{"status":"making","default":"roman","progress":{}}""", updatedAt = old, open = open)
        val reads = { server.requestsTo("GET", "/api/transcripts/.+/views").size }
        pumpUntil("read as the Library opens") { reads() == 1 }
        fun after(minutes: Long) {
            compose.mainClock.advanceTimeBy(minutes * 60_000, ignoreFrameDuration = true)
            compose.waitForIdle()
        }
        after(4)
        assertEquals("not before 5 minutes", 1, reads())
        after(1)
        pumpUntil("read again at about 5 minutes") { reads() == 2 }
        after(9)
        assertEquals(2, reads())
        after(1)
        pumpUntil("read again at about 15 minutes") { reads() == 3 }
        after(60)
        assertEquals("no more while the Library stays open", 3, reads())
        // Leaving the Library and opening it again reads them once more.
        open.value = false
        compose.waitForIdle()
        open.value = true
        pumpUntil("read as the Library opens again") { reads() == 4 }
    }
}
