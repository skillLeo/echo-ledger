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
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * U19 on the v2 screens: an Urdu recording is read in Roman Urdu by default. While its view is made the page and the
 * Library row say "Converting to Roman Urdu… 60%" with a small progress ring (also before the server started it); once it is
 * ready the lines swap, Copy, Copy with timestamps and Copy for AI (its Language line too) use it, and the row's snippet
 * is its first Roman line. A view that failed says "Couldn't convert to Roman Urdu · Try again".
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
        open(item(RowViewsDto("making", "roman", mapOf("roman" to com.echoledger.mobile.data.ProgressDto(3, 5)))))
        awaitText("Converting to Roman Urdu… 60%")
        awaitText("Hi, thanks for calling.")
        awaitPolled("Shukriya call karne ka.")
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
    fun aFinishedUrduTranscriptCountsAsConvertingBeforeTheServerStartsItsView() {
        views("none", """{"status":"none","available":["roman","english"],"default":"roman"}""", making)
        open(item(RowViewsDto("none", "roman")))
        awaitText("Converting to Roman Urdu…")
        // The page asked for it, so it is made even if the server had not started.
        pumpUntil("asked") { server.requestsTo("POST", "/api/transcripts/.+/views").isNotEmpty() }
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
        awaitText("Couldn't convert to Roman Urdu · Try again")
        assertTrue(server.requestsTo("POST", "/api/transcripts/.+/views").isEmpty())
        press("Try again")
        pumpUntil("asked again") { server.requestsTo("POST", "/api/transcripts/.+/views").isNotEmpty() }
        awaitText("Converting to Roman Urdu…")
    }

    private fun library(rowViews: String) {
        val row = ServerFixtures.libraryItem(ServerFixtures.SESSION_ID, title = "Bilal Traders", preview = "Hello there.", client = null)
            .replace("\"preview\":\"Hello there.\"", "\"preview\":\"Hello there.\",\"views\":$rowViews")
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(listOf(row))))
        runBlocking { app.graph.library.refresh() }
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    V2LibraryScreen(vm, initials = "AS", onOpen = {}, onTrash = {}, onAccount = {})
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
    fun aLibraryRowWhoseRomanUrduIsReadyShowsItsFirstRomanLine() {
        views("ready", ready)
        library("""{"status":"ready","default":"roman","progress":{}}""")
        awaitText("Hello ji.")
        compose.onAllNodesWithText("Hello there.", substring = true).assertCountEquals(0)
        compose.onAllNodesWithText("Converting", substring = true).assertCountEquals(0)
    }

    @Test
    fun aLibraryRowWhoseViewFailedSaysSo() {
        library("""{"status":"failed","default":"roman","progress":{}}""")
        awaitText("Couldn't convert to Roman Urdu · Try again")
    }
}
