package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTouchInput
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.TranscribeEstimateDto
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.RecordingCopy
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.library.V2RecordingScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * Audio only and Transcribe later on the v2 screens (docs/v2/audit/parity.csv PH-LIB-067, PH-LIB-070; contract
 * 2.0.0-j): the title menu › Audio only; the selection's Transcribe… asks the server what it uses first, then starts only
 * the audio-only ones (all or nothing for the minutes); an audio-only recording's page offers Transcribe with its
 * estimate.
 */
class V2TranscribeParityTest : ScreenTest() {
    private fun audio(id: String, title: String, estimate: String = """{"minutes":2,"minutes_left":118}""") =
        ServerFixtures.libraryItem(id, title = title, status = "audio_only", finalStatus = null, preview = null, client = null)
            .replace("\"updated_at\"", "\"transcribe_estimate\":$estimate,\"updated_at\"")

    private val done = ServerFixtures.libraryItem("01JA", title = "Bilal Traders")

    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
    }

    private fun library(rows: List<String>, language: EchoLanguage = EchoLanguage.English) {
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(rows)))
        runBlocking { app.graph.library.refresh() }
        show {
            EchoV2Theme(darkTheme = false, language = language) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    androidx.compose.foundation.layout.Box {
                        V2LibraryScreen(vm, initials = "AS", onOpen = {}, onTrash = {}, onAccount = {})
                        EchoToastHost(toasts)
                    }
                }
            }
        }
        shows(if (language == EchoLanguage.Urdu) "Rehman" else "Bilal Traders")
    }

    private fun shows(words: String) = pumpUntil("\"$words\"") {
        compose.mainClock.advanceTimeByFrame()
        compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    private fun click(words: String) {
        compose.onAllNodes(hasText(words, substring = true) and hasClickAction()).onFirst().performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    /** The dialog's button, once the estimate's answer has opened the dialog. */
    private fun inDialog(words: String): androidx.compose.ui.test.SemanticsNodeInteraction {
        val button = hasText(words) and hasClickAction() and hasAnyAncestor(isDialog())
        pumpUntil("the dialog's \"$words\"") {
            compose.mainClock.advanceTimeByFrame()
            compose.onAllNodes(button).fetchSemanticsNodes().isNotEmpty()
        }
        return compose.onAllNodes(button).onLast()
    }

    private fun pick(vararg titles: String) = titles.forEach {
        compose.onNodeWithText(it, substring = true).performTouchInput { longClick() }
        compose.waitForIdle()
    }

    private fun more() {
        compose.onNode(hasContentDescription("More") and hasClickAction()).performClick()
        compose.waitForIdle()
    }

    private fun ids(path: String) = Json.parseToJsonElement(server.requestsTo("POST", path).last().bodyText()).jsonObject["ids"]!!
        .jsonArray.map { it.jsonPrimitive.content }

    @Test
    fun audioOnlyInTheTitlesMenuShowsOnlyTheRecordingsKeptAsAudio() {
        library(listOf(done, audio("01JB", "Rehman Textiles")))
        click("All recordings")
        // The menu counts it (TalkBack hears the count with the name).
        compose.onNode(androidx.compose.ui.test.hasContentDescription("Audio only, 1") and hasClickAction()).performClick()
        pumpUntil("filtered") { compose.onAllNodesWithText("Bilal Traders", substring = true).fetchSemanticsNodes().isEmpty() }
        shows("Rehman Textiles")
        // The title says where the list is.
        compose.onNode(hasText("Audio only") and androidx.compose.ui.test.isHeading()).assertExists()
    }

    @Test
    fun transcribeTheSelectionShowsTheMinutesFirstThenStartsOnlyTheAudioOnes() {
        library(listOf(done, audio("01JB", "Rehman Textiles"), audio("01JC", "Karachi Cargo")))
        server.on(
            "POST",
            "/api/sessions/transcribe-estimate",
            FakeServer.json(200, """{"minutes":4,"minutes_left":120,"items":[{"id":"01JB","minutes":2},{"id":"01JC","minutes":2}]}""")
        )
        server.on(
            "POST",
            "/api/sessions/transcribe",
            FakeServer.json(202, """{"started":["01JB"],"skipped":[{"id":"01JC","code":"not_audio_only"}],"minutes":2}""")
        )
        pick("Bilal Traders", "Rehman Textiles", "Karachi Cargo")
        more()
        click("Transcribe…")
        shows("Transcribe 2 recordings?")
        shows("Uses 4 minutes of your plan · 120 left this month.")
        // Only the audio-only ones are asked about; nothing has started yet.
        assertEquals(listOf("01JB", "01JC"), ids("/api/sessions/transcribe-estimate"))
        assertTrue(server.requestsTo("POST", "/api/sessions/transcribe").isEmpty())
        inDialog("Transcribe").performClick()
        pumpUntil("started") { server.requestsTo("POST", "/api/sessions/transcribe").isNotEmpty() }
        assertEquals(listOf("01JB", "01JC"), ids("/api/sessions/transcribe"))
        shows("Transcribing 1 recording. 1 skipped: already transcribed or still uploading.")
        // The Library syncs to show them transcribing.
        pumpUntil("synced") { server.requestsTo("GET", "/api/library").size >= 2 }
    }

    @Test
    fun cancelStartsNothing() {
        library(listOf(done, audio("01JB", "Rehman Textiles")))
        server.on(
            "POST",
            "/api/sessions/transcribe-estimate",
            FakeServer.json(200, """{"minutes":0,"minutes_left":null,"items":[{"id":"01JB","minutes":0}]}""")
        )
        pick("Rehman Textiles")
        more()
        click("Transcribe…")
        // On the person's own key it uses no plan minutes.
        shows("Uses none of your plan's minutes.")
        inDialog("Cancel").performClick()
        compose.waitForIdle()
        assertTrue(server.requestsTo("POST", "/api/sessions/transcribe").isEmpty())
    }

    @Test
    fun whenTheMinutesLeftCannotCoverItItSaysSoAndStartsNothing() {
        library(listOf(done, audio("01JB", "Rehman Textiles")))
        server.on(
            "POST",
            "/api/sessions/transcribe-estimate",
            FakeServer.json(200, """{"minutes":30,"minutes_left":10,"items":[{"id":"01JB","minutes":30}]}""")
        )
        pick("Rehman Textiles")
        more()
        click("Transcribe…")
        shows("Not enough minutes")
        shows("30 needed, 10 left.")
        inDialog("OK").performClick()
        compose.waitForIdle()
        assertTrue(server.requestsTo("POST", "/api/sessions/transcribe").isEmpty())
    }

    @Test
    fun theServersRefusalsAreSaidInTheScreensWords() {
        library(listOf(done, audio("01JB", "Rehman Textiles")))
        server.on(
            "POST",
            "/api/sessions/transcribe-estimate",
            FakeServer.json(200, """{"minutes":2,"minutes_left":5,"items":[{"id":"01JB","minutes":2}]}""")
        )
        // Another device used the minutes meanwhile.
        server.on(
            "POST",
            "/api/sessions/transcribe",
            FakeServer.json(403, """{"message":"Over budget","code":"budget_exceeded","minutes_needed":2,"minutes_left":0}""")
        )
        pick("Rehman Textiles")
        more()
        click("Transcribe…")
        inDialog("Transcribe").performClick()
        shows("2 needed, 0 left. Nothing was started.")
    }

    @Test
    fun onFreeWithoutAKeyItSaysToAddTheKey() {
        library(listOf(done, audio("01JB", "Rehman Textiles")))
        server.on(
            "POST",
            "/api/sessions/transcribe-estimate",
            FakeServer.json(200, """{"minutes":0,"minutes_left":null,"items":[{"id":"01JB","minutes":0}]}""")
        )
        server.on("POST", "/api/sessions/transcribe", FakeServer.json(403, """{"message":"Key","code":"needs_transcription_key"}"""))
        pick("Rehman Textiles")
        more()
        click("Transcribe…")
        inDialog("Transcribe").performClick()
        shows("On the Free plan, add your transcription key in Settings › Transcription key to transcribe.")
    }

    @Test
    fun withNothingKeptAsAudioTheSelectionOffersNoTranscribe() {
        library(listOf(done, ServerFixtures.libraryItem("01JB", title = "Rehman Textiles")))
        pick("Bilal Traders")
        more()
        compose.onAllNodesWithText("Transcribe…").assertCountEquals(0)
    }

    @Test
    fun inUrduTheQuestionIsInUrdu() {
        library(listOf(done, audio("01JB", "Rehman Textiles")), EchoLanguage.Urdu)
        server.on(
            "POST",
            "/api/sessions/transcribe-estimate",
            FakeServer.json(200, """{"minutes":2,"minutes_left":null,"items":[{"id":"01JB","minutes":2}]}""")
        )
        pick("Rehman Textiles")
        compose.onNode(
            hasContentDescription(V2Testing.resources(EchoLanguage.Urdu).getString(com.echoledger.mobile.R.string.v2_lib_more)) and
                hasClickAction()
        )
            .performClick()
        click("ٹرانسکرائب کریں…")
        shows("1 ریکارڈنگ ٹرانسکرائب کریں؟")
        shows("آپ کے پلان کے 2 منٹ استعمال ہوں گے۔")
    }

    // The recording page of an audio-only recording.

    private val audioItem = LibraryItem(
        id = ServerFixtures.SESSION_ID,
        sourceDevice = "phone",
        sourceApp = "whatsapp",
        title = "Rehman Textiles",
        startedAt = "2026-10-20T10:00:00+00:00",
        durationMs = 95_000,
        status = "audio_only",
        transcript = TranscriptSummary(finalStatus = null, finalTranscriptId = null),
        updatedAt = "2026-10-20T10:05:00+00:00",
        transcribeEstimate = TranscribeEstimateDto(minutes = 2, minutesLeft = 118)
    )

    private fun page() {
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    androidx.compose.foundation.layout.Box {
                        V2RecordingScreen(vm, audioItem, onBack = {})
                        EchoToastHost(toasts)
                    }
                }
            }
        }
        shows("Kept as audio, not transcribed yet.")
    }

    @Test
    fun anAudioOnlyRecordingsPageSaysWhatTranscribingUsesAndStartsIt() {
        val started = ServerFixtures.libraryItem(
            ServerFixtures.SESSION_ID,
            title = "Rehman Textiles",
            status = "processing",
            finalStatus = "pending"
        )
        server.on(
            "POST",
            "/api/sessions/${ServerFixtures.SESSION_ID}/transcribe",
            FakeServer.json(
                202,
                """{"session":$started,"transcript":{"id":"T9","kind":"final","provider":"assemblyai","status":"pending","language":null}}"""
            )
        )
        page()
        shows("Uses 2 minutes of your plan · 118 left this month.")
        val go = compose.onAllNodes(hasText("Transcribe") and hasClickAction()).onFirst()
        runCatching { go.performScrollTo() }
        go.performClick()
        pumpUntil("started") { server.requestsTo("POST", "/api/sessions/${ServerFixtures.SESSION_ID}/transcribe").isNotEmpty() }
        shows("Transcribing 1 recording.")
        // The Library has it transcribing at once, before the next sync.
        pumpUntil("in the Library") { vm.library.value.any { it.id == ServerFixtures.SESSION_ID && it.status == "processing" } }
    }

    @Test
    fun onAnAudioOnlyPageARecordingAlreadyStartedSaysSo() {
        server.on(
            "POST",
            "/api/sessions/${ServerFixtures.SESSION_ID}/transcribe",
            FakeServer.json(409, """{"message":"Not audio only","code":"not_audio_only"}""")
        )
        page()
        val go = compose.onAllNodes(hasText("Transcribe") and hasClickAction()).onFirst()
        runCatching { go.performScrollTo() }
        go.performClick()
        shows("It is already transcribed or still uploading.")
    }

    @Test
    fun onAnAudioOnlyPageNotEnoughMinutesSaysSoAndNothingStarts() {
        server.on(
            "POST",
            "/api/sessions/${ServerFixtures.SESSION_ID}/transcribe",
            FakeServer.json(403, """{"message":"Over budget","code":"budget_exceeded","minutes_needed":2,"minutes_left":1}""")
        )
        page()
        val go = compose.onAllNodes(hasText("Transcribe") and hasClickAction()).onFirst()
        runCatching { go.performScrollTo() }
        go.performClick()
        shows("2 needed, 1 left. Nothing was started.")
        assertEquals(1, server.requestsTo("POST", "/api/sessions/${ServerFixtures.SESSION_ID}/transcribe").size)
        // It stays audio only, with Transcribe to try again.
        assertTrue(vm.library.value.none { it.id == ServerFixtures.SESSION_ID && it.status == "processing" })
        shows("Kept as audio, not transcribed yet.")
    }

    @Test
    fun notEnoughMinutesHasOnlyOk() {
        library(listOf(done, audio("01JB", "Rehman Textiles")))
        server.on(
            "POST",
            "/api/sessions/transcribe-estimate",
            FakeServer.json(200, """{"minutes":30,"minutes_left":10,"items":[{"id":"01JB","minutes":30}]}""")
        )
        pick("Rehman Textiles")
        more()
        click("Transcribe…")
        inDialog("OK")
        compose.onAllNodes(hasText("Cancel") and hasAnyAncestor(isDialog())).assertCountEquals(0)
    }

    // Copy for AI of several recordings (the lead's review of parity 3).

    private val segments = listOf(
        SegmentDto(
            1,
            "Me",
            0,
            2_000,
            "Rate kya hai?",
            listOf(
                com.echoledger.mobile.data.WordDto("Rate", 0, 400, 0.2),
                com.echoledger.mobile.data.WordDto("kya", 500, 800, 0.9),
                com.echoledger.mobile.data.WordDto("hai?", 900, 1_200, 0.9)
            )
        )
    )

    private fun part(settings: AppSettings, title: String) = RecordingCopy.text(
        com.echoledger.mobile.ui.v2.library.CopyKind.ForAi,
        LibraryItem(id = title, title = title, updatedAt = "2026-10-20T10:05:00+00:00"),
        segments,
        null,
        settings,
        emptyList(),
        withNote = false
    )

    @Test
    fun copyForAiOfManyHasOneNoteAndNoneWhenMarksAreOff() {
        val on = AppSettings(unsureStyle = "brackets")
        val note = com.echoledger.mobile.core.TranscriptText.note("brackets")
        val many = RecordingCopy.forAiMany(listOf(part(on, "Bilal"), part(on, "Rehman")), on)
        assertTrue(many.startsWith(note))
        assertEquals(1, many.split(note).size - 1)
        assertTrue(many, many.contains("[?Rate]"))
        val off = AppSettings(unsureStyle = "off")
        val plain = RecordingCopy.forAiMany(listOf(part(off, "Bilal"), part(off, "Rehman")), off)
        assertFalse(plain, plain.contains(note))
        assertFalse("marks off stays off", plain.contains("[?"))
        assertTrue(plain, plain.contains("Title: Bilal") && plain.contains("Title: Rehman") && plain.contains("\n\n---\n\n"))
        // One rule for one recording and many: marks off, the single Copy for AI has neither marks nor the note.
        val one = RecordingCopy.text(
            com.echoledger.mobile.ui.v2.library.CopyKind.ForAi,
            LibraryItem(id = "x", title = "Bilal", updatedAt = "2026-10-20T10:05:00+00:00"),
            segments,
            null,
            off,
            emptyList()
        )
        assertFalse(one, one.contains(note) || one.contains("[?"))
        val oneOn = RecordingCopy.text(
            com.echoledger.mobile.ui.v2.library.CopyKind.ForAi,
            LibraryItem(id = "x", title = "Bilal", updatedAt = "2026-10-20T10:05:00+00:00"),
            segments,
            null,
            on,
            emptyList()
        )
        assertTrue(oneOn, oneOn.contains(note) && oneOn.contains("[?Rate]"))
    }
}
