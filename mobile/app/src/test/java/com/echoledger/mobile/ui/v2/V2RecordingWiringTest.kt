package com.echoledger.mobile.ui.v2

import androidx.compose.material3.Text
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import kotlinx.coroutines.runBlocking
import org.junit.Before
import org.junit.Test

/**
 * The recording page inside the shell (parity.csv PH-REC-015, 016, 020, PH-LIB-008): a transcript match opens the page at
 * its moment; "Run capture test" opens Setup; "Check your key" opens Settings at the key page.
 */
class V2RecordingWiringTest : ScreenTest() {
    private val classic = ClassicScreens(
        home = { Text("Classic activity") },
        library = { Text("Classic library") },
        live = { Text("Classic live") },
        ask = { Text("Classic projects") },
        settings = { Text("Classic settings") },
        upgrade = {},
        settingsPage = { page, _ -> Text("Classic page $page") },
        setup = { Text("Classic setup") }
    )

    @Before
    fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(listOf(ServerFixtures.libraryItem(ServerFixtures.SESSION_ID, title = "Bilal Traders")))
            )
        )
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        runBlocking { app.graph.library.refresh() }
    }

    private fun shell(flags: Map<String, Boolean> = emptyMap()) = show {
        EchoV2Shell(
            vm,
            UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true) + flags),
            darkTheme = false,
            classic = classic,
            onSwitchToClassic = {},
            language = EchoLanguage.English
        )
    }

    private fun failed(code: String) = server.on(
        "GET",
        "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}",
        FakeServer.json(200, ServerFixtures.transcript(status = "failed", errorCode = code, segments = "[]"))
    )

    private fun openRow() {
        compose.waitUntil(5_000) { compose.onAllNodesWithText("Bilal Traders", substring = true).fetchSemanticsNodes().isNotEmpty() }
        compose.onAllNodesWithText("Bilal Traders", substring = true).onFirst().performClick()
    }

    private fun await(words: String) {
        val ok = runCatching {
            pumpUntil("\"$words\"", 20_000) { compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty() }
        }
        if (ok.isFailure) {
            val texts = compose.onAllNodes(hasText("", substring = true), useUnmergedTree = true).fetchSemanticsNodes().mapNotNull {
                it.config.getOrElseNullable(androidx.compose.ui.semantics.SemanticsProperties.Text) { null }?.joinToString()
            } + compose.onAllNodes(androidx.compose.ui.test.hasContentDescription("", substring = true), useUnmergedTree = true)
                .fetchSemanticsNodes().mapNotNull {
                    it.config.getOrElseNullable(androidx.compose.ui.semantics.SemanticsProperties.ContentDescription) {
                        null
                    }?.joinToString()
                }
            throw AssertionError("\"$words\" not shown; on screen: $texts", ok.exceptionOrNull())
        }
    }

    @Test
    fun aTranscriptMatchOpensTheRecordingAtItsMoment() {
        // A recording with audio: the page has its player.
        server.on("GET", "/api/sessions/${ServerFixtures.SESSION_ID}", FakeServer.json(200, ServerFixtures.sessionDetail()))
        server.on(
            "GET",
            "/api/search",
            FakeServer.json(
                200,
                """{"data":[{"session":${ServerFixtures.libraryItem(ServerFixtures.SESSION_ID, title = "Bilal Traders")},
                   "hits":[{"transcript_id":"t","segment_index":3,"start_ms":65000,"speaker":"Client",
                   "snippet":"the invoice for June","highlights":[[4,11]]}],"hit_count":1}],"next_page":null}"""
            )
        )
        shell()
        compose.onAllNodes(hasSetTextAction()).onFirst().performTextInput("invoice")
        compose.waitUntil(10_000) { compose.onAllNodesWithText("the invoice for June").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("the invoice for June").performClick()
        // The page's player stands at 1:05, where it plays from once the audio is ready.
        await("1:05")
    }

    @Test
    fun runCaptureTestOpensSetup() {
        failed("no_audio")
        shell()
        openRow()
        await("Nothing was heard in this recording")
        compose.onNode(hasText("Run capture test") and hasClickAction()).performClick()
        await("Classic setup")
    }

    @Test
    fun checkYourKeyOpensTheKeyPage() {
        failed("own_key_rejected")
        shell()
        openRow()
        await("Groq did not accept your key.")
        compose.onNode(hasText("Check your key") and hasClickAction()).performClick()
        await("Classic page key")
    }

    @Test
    fun checkYourKeyOpensTheV2KeyPageWithSettingsV2() {
        failed("own_key_rejected")
        shell(mapOf("ui_v2.settings" to true))
        openRow()
        await("Groq did not accept your key.")
        compose.onNode(hasText("Check your key") and hasClickAction()).performClick()
        await("Transcription key")
    }

    @Test
    fun aSharedVoiceNoteGoesBackToTheAppItCameFrom() {
        var backToSource = 0
        com.echoledger.mobile.core.SharedNotes.remember(app, listOf("note-1"))
        com.echoledger.mobile.core.SharedNotes.linkSession(app, "note-1", ServerFixtures.SESSION_ID)
        show {
            com.echoledger.mobile.ui.SharedNotesScreen(
                vm,
                listOf("note-1"),
                onLeave = {},
                sourceApp = "WhatsApp",
                onBackToSource = { backToSource++ },
                page = { item, back, source ->
                    com.echoledger.mobile.ui.v2.shell.EchoV2Frame(vm, darkTheme = false, language = EchoLanguage.English) {
                        com.echoledger.mobile.ui.v2.library.V2RecordingScreen(vm, item, onBack = back, backToSource = source)
                    }
                }
            )
        }
        await("Summary")
        val more = (hasText("More") or androidx.compose.ui.test.hasContentDescription("More")) and hasClickAction()
        compose.onAllNodes(more).onFirst().performClick()
        compose.onNode(hasText("Back to WhatsApp") and hasClickAction()).performClick()
        org.junit.Assert.assertEquals(1, backToSource)
    }
}
