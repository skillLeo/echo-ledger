package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.ask.V2AskScreen
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2RecordingScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.io.File
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Ask beyond one project and each line's language (contract 2.0.0-o; parity.csv PH-OTH-026, PH-REC-071, 073, 074): the
 * Ask tab asks across all my recordings first (`POST /api/ask`) or a project; a recording's page has an Ask tab and Ask
 * in its action row (`POST /api/sessions/{id}/ask`); nothing transcribed yet says so; the lines of a language can be
 * skipped while lines without one always show. The answers are the server's own samples.
 */
class V2AskEverywhereParityTest : ScreenTest() {
    private val samples = File("../../docs/api-samples")

    /** The server's own answers (docs/api-samples): `ask` across recordings, `session_ask` about one. */
    private fun sample(name: String) = File(samples, "$name.json").readText()

    private var opened = mutableListOf<Pair<String, Long>>()

    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
    }

    private fun seen(words: String) = compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()

    private fun press(words: String) {
        val node = compose.onAllNodes((hasText(words) or hasContentDescription(words)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    private fun body(path: String) = Json.parseToJsonElement(server.requestsTo("POST", path).single().bodyText()).jsonObject

    private fun askTab(language: EchoLanguage = EchoLanguage.English) {
        vm.refresh()
        show {
            EchoV2Theme(darkTheme = false, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2AskScreen(vm, onOpenAt = { id, at -> opened += id to at })
                }
            }
        }
        compose.waitForIdle()
    }

    /** Types the question and presses the form's Ask (the last one: a recording's action row has one above it). */
    private fun typeAndAsk(question: String, ask: String = "Ask") {
        compose.onAllNodes(hasSetTextAction()).onFirst().performTextInput(question)
        val buttons = compose.onAllNodes(
            hasText(ask) and hasClickAction() and !SemanticsMatcher.expectValue(SemanticsProperties.Role, Role.Tab)
        )
        buttons[buttons.fetchSemanticsNodes().size - 1].performScrollTo().performClick()
    }

    @Test
    fun theAskTabAsksAcrossAllMyRecordingsFirstAndTheSourceOpensAtItsMoment() {
        server.on("POST", "/api/ask", FakeServer.json(200, sample("ask")))
        askTab()
        pumpUntil("the scope") { seen("All my recordings") }
        typeAndAsk("When do we launch?")
        pumpUntil("asked everywhere") { server.requestsTo("POST", "/api/ask").isNotEmpty() }
        assertEquals("When do we launch?", body("/api/ask")["question"]!!.jsonPrimitive.content)
        assertEquals("en", body("/api/ask")["language"]!!.jsonPrimitive.content)
        assertTrue(server.requestsTo("POST", "/api/clients/.+/ask").isEmpty())
        pumpUntil("the answer") { seen("Launch is on November 15.") }
        val citation = Json.parseToJsonElement(sample("ask")).jsonObject["data"]!!.jsonObject["citations"]!!
        val first = (citation as kotlinx.serialization.json.JsonArray)[0].jsonObject
        press(first["title"]!!.jsonPrimitive.content)
        assertEquals(listOf(first["session_id"]!!.jsonPrimitive.content to first["at_ms"]!!.jsonPrimitive.content.toLong()), opened)
    }

    @Test
    fun aProjectChosenAsksThatProject() {
        server.on("POST", "/api/clients/.+/ask", FakeServer.json(200, sample("client_ask")))
        askTab()
        pumpUntil("the projects") { vm.clients.value.isNotEmpty() }
        compose.onAllNodesWithText("Ask about", useUnmergedTree = true).onFirst().performClick()
        compose.onAllNodes(hasText("Acme Holdings") and hasClickAction()).onFirst().performClick()
        typeAndAsk("When do we launch?")
        pumpUntil("asked the project") { server.requestsTo("POST", "/api/clients/01JCLIENT0000000000000000A/ask").isNotEmpty() }
        assertTrue(server.requestsTo("POST", "/api/ask").isEmpty())
    }

    @Test
    fun nothingTranscribedYetSaysSoInTheScreensLanguage() {
        server.on("POST", "/api/ask", FakeServer.json(409, """{"message":"Nothing to ask about yet.","code":"no_transcript"}"""))
        askTab(EchoLanguage.Urdu)
        pumpUntil("the Urdu scope") { seen("میری تمام ریکارڈنگز") }
        typeAndAsk("ہم کب لانچ کریں گے؟", ask = "پوچھیں")
        pumpUntil("the words") { seen("ابھی پوچھنے کے لیے کچھ ٹرانسکرائب نہیں ہوا۔") }
    }

    // ---- a recording's page ---------------------------------------------------------------------------------------

    private val segments = """[
        {"channel":1,"speaker":"Me","start_ms":250,"end_ms":2600,"text":"Shipment kab aayegi?","words":[],"language":"ur"},
        {"channel":2,"speaker":"Client","start_ms":3000,"end_ms":5000,"text":"Next Tuesday, by sea.","words":[],"language":"en"},
        {"channel":2,"speaker":"Client","start_ms":6000,"end_ms":8000,"text":"Theek hai, done.","words":[]}
    ]"""

    private fun recording(language: EchoLanguage = EchoLanguage.English, lines: String = segments) {
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript(segments = lines)))
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        val item = LibraryItem(
            id = ServerFixtures.SESSION_ID,
            sourceDevice = "phone",
            sourceApp = "whatsapp",
            title = "Call with Acme",
            startedAt = "2026-10-20T10:00:00+00:00",
            durationMs = 2_530_000,
            status = "completed",
            transcript = TranscriptSummary(
                finalStatus = "completed",
                finalTranscriptId = ServerFixtures.TRANSCRIPT_ID,
                finalLanguage = "ur"
            ),
            updatedAt = "2026-10-20T10:05:00+00:00"
        )
        show {
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
        pumpUntil("the lines") { seen("Next Tuesday, by sea.") }
    }

    @Test
    fun aRecordingsAskInTheActionRowAsksAboutThatRecording() {
        server.on("POST", "/api/sessions/.+/ask", FakeServer.json(200, sample("session_ask")))
        recording()
        // The tabs are Transcript · Summary · Ask · Moments · Details.
        assertTrue(
            compose.onAllNodes(
                hasText("Ask") and SemanticsMatcher.expectValue(SemanticsProperties.Role, Role.Tab)
            ).fetchSemanticsNodes().isNotEmpty()
        )
        compose.onAllNodes(hasText("Ask") and hasClickAction() and !SemanticsMatcher.expectValue(SemanticsProperties.Role, Role.Tab))
            .onFirst().performClick()
        pumpUntil("the Ask tab") { seen("Ask about this recording") }
        typeAndAsk("When do we launch?")
        pumpUntil("asked") { server.requestsTo("POST", "/api/sessions/${ServerFixtures.SESSION_ID}/ask").isNotEmpty() }
        assertEquals("When do we launch?", body("/api/sessions/${ServerFixtures.SESSION_ID}/ask")["question"]!!.jsonPrimitive.content)
        pumpUntil("the answer") { seen("Launch is on November 15.") }
        assertTrue(server.requestsTo("POST", "/api/ask").isEmpty())
    }

    @Test
    fun aLanguagesLinesCanBeSkippedAndLinesWithoutOneAlwaysShow() {
        recording()
        pumpUntil("the languages") { seen("Lines in") }
        press("English")
        pumpUntil("English skipped") { !seen("Next Tuesday, by sea.") }
        assertTrue(seen("Shipment kab aayegi?"))
        assertTrue("a line with no language stays", seen("Theek hai, done."))
        press("English")
        pumpUntil("back") { seen("Next Tuesday, by sea.") }
        press("Urdu")
        pumpUntil("Urdu skipped") { !seen("Shipment kab aayegi?") }
        assertTrue(seen("Next Tuesday, by sea.") && seen("Theek hai, done."))
    }

    @Test
    fun aTranscriptInOneLanguageOffersNoSkip() {
        recording(lines = segments.replace("\"language\":\"ur\"", "\"language\":\"en\""))
        assertTrue(!seen("Lines in"))
    }

    @Test
    fun inUrduTheChoicesNameTheLanguagesInUrdu() {
        recording(EchoLanguage.Urdu)
        pumpUntil("the Urdu names") { seen("اردو") && seen("انگریزی") }
        assertTrue(seen("پوچھیں"))
    }
}
