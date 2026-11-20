package com.echoledger.mobile.ui.v2

import android.content.ClipboardManager
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextReplacement
import androidx.compose.ui.test.performTouchInput
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.ExportFormat
import com.echoledger.mobile.ui.v2.library.V2RecordingScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * The v2 recording page's shared parts, against a scripted server: the ⋮ menu (Organise, Transcript, Export ▸, Delete
 * last), each of its actions as the request classic's page sends, Copy ▾ and what it puts on the clipboard.
 */
class RecordingPageTest : ScreenTest() {
    private var backs = 0

    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
        server.on("PATCH", "/api/sessions/.+", FakeServer.empty(204))
        server.on("POST", "/api/sessions/.+/retranscribe", FakeServer.json(202, "{}"))
        server.on("DELETE", "/api/sessions/.+", FakeServer.empty(204))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        server.on("POST", "/api/clients", FakeServer.json(201, """{"data":{"id":"01JCLIENT0000000000000000N","name":"Noor Traders"}}"""))
        server.on("GET", "/api/transcripts/.+/export", exportFile("transcript.txt"))
        server.on("GET", "/api/sessions/.+/pack", exportFile("pack.zip"))
    }

    private fun exportFile(name: String) = MockResponse.Builder().code(200).addHeader("Content-Type", "application/octet-stream")
        .addHeader("Content-Disposition", "attachment; filename=\"$name\"").body("file").build()

    private fun item(pinned: Boolean = false, keep: Boolean = false) = LibraryItem(
        id = ServerFixtures.SESSION_ID,
        sourceDevice = "phone",
        sourceApp = "whatsapp",
        title = "Call with Acme",
        client = NamedRef("01JCLIENT0000000000000000A", "Acme Holdings"),
        startedAt = "2026-10-20T10:00:00+00:00",
        durationMs = 2_530_000,
        status = "completed",
        transcript = TranscriptSummary(finalStatus = "completed", finalTranscriptId = ServerFixtures.TRANSCRIPT_ID, finalLanguage = "en"),
        updatedAt = "2026-10-20T10:05:00+00:00",
        pinned = pinned,
        keep = keep,
        labels = listOf("urgent")
    )

    private fun open(item: LibraryItem = item(), language: EchoLanguage = EchoLanguage.English) = show {
        EchoV2Theme(darkTheme = false, language = language) {
            val toasts = rememberEchoToastState()
            CompositionLocalProvider(LocalEchoToasts provides toasts) {
                androidx.compose.foundation.layout.Box {
                    V2RecordingScreen(vm, item, onBack = { backs++ })
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

    private fun awaitText(words: String) = pumpUntil("\"$words\"", 20_000) {
        compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    private fun patches() = server.requestsTo("PATCH", "/api/sessions/${ServerFixtures.SESSION_ID}").map {
        Json.parseToJsonElement(it.body!!.utf8()).jsonObject
    }

    private fun clipboard(): String? = app.getSystemService(ClipboardManager::class.java).primaryClip?.getItemAt(0)?.text?.toString()

    private fun openMenu() {
        awaitText("Hi, thanks for calling.")
        press("More")
    }

    @Test
    fun theMenuHasOrganiseTranscriptAndExportGroupsAndDeleteLast() {
        open()
        openMenu()
        val rows = listOf(
            "Organise", "Rename", "Move to project", "Labels", "Pin", "Keep forever",
            "Transcript", "Change language & re-transcribe", "Export", "Delete"
        )
        rows.forEach { compose.onAllNodesWithText(it).onFirst().assertExistsSafely(it) }
        // Delete is the last line of the menu.
        val tops = rows.associateWith { compose.onAllNodesWithText(it).fetchSemanticsNodes().last().boundsInRoot.top }
        assertTrue(tops.toString(), tops.getValue("Delete") >= tops.values.max())
    }

    private fun androidx.compose.ui.test.SemanticsNodeInteraction.assertExistsSafely(what: String) =
        runCatching { assertExists() }.getOrElse { throw AssertionError("missing in the menu: $what", it) }

    @Test
    fun pinKeepAndUnpinSendTheSameChangesAsClassic() {
        open()
        openMenu()
        press("Pin")
        pumpUntil("pinned") { patches().any { it["pinned"]?.jsonPrimitive?.content == "true" } }
        awaitText("Pinned")
        openMenu()
        press("Keep forever")
        pumpUntil("kept") { patches().any { it["keep"]?.jsonPrimitive?.content == "true" } }
    }

    @Test
    fun aPinnedAndKeptRecordingOffersUnpinAndStopKeeping() {
        open(item(pinned = true, keep = true))
        openMenu()
        press("Unpin")
        pumpUntil("unpinned") { patches().any { it["pinned"]?.jsonPrimitive?.content == "false" } }
        openMenu()
        press("Stop keeping")
        pumpUntil("not kept") { patches().any { it["keep"]?.jsonPrimitive?.content == "false" } }
    }

    @Test
    fun renameFromTheMenuOrTheTitle() {
        open()
        openMenu()
        press("Rename")
        compose.onNode(hasSetTextAction()).performTextReplacement("Acme pricing")
        press("Save")
        pumpUntil("renamed") { patches().any { it["title"]?.jsonPrimitive?.content == "Acme pricing" } }
        awaitText("Renamed")
        compose.onNodeWithText("Acme pricing").assertExists()
        // The title itself opens Rename too.
        press("Acme pricing")
        compose.onNode(hasSetTextAction()).assertExists()
    }

    @Test
    fun moveToAProjectOutOfOneOrIntoANewOne() {
        // The app loads the projects when it starts (the shell's sync); here the sync runs first.
        vm.refresh()
        pumpUntil("projects") { vm.clients.value.isNotEmpty() }
        open()
        openMenu()
        press("Move to project")
        press("Zed Logistics")
        pumpUntil("moved") { patches().any { it["client_id"]?.jsonPrimitive?.content == "01JCLIENT0000000000000000B" } }
        awaitText("Moved to Zed Logistics")
        openMenu()
        press("Move to project")
        press("No project")
        pumpUntil("taken out") { patches().any { it.containsKey("client_id") && it["client_id"].toString() == "null" } }
        openMenu()
        press("Move to project")
        press("New project")
        compose.onNode(hasSetTextAction()).performTextReplacement("Noor Traders")
        press("Create")
        pumpUntil("moved to the new one") { patches().any { it["client_id"]?.jsonPrimitive?.content == "01JCLIENT0000000000000000N" } }
    }

    @Test
    fun labelsAreAddedAndRemoved() {
        open()
        openMenu()
        press("Labels")
        compose.onNode(hasSetTextAction()).performTextReplacement("pricing")
        press("Add")
        pumpUntil("added") {
            patches().any { p -> p["labels"]?.jsonArray?.map { it.jsonPrimitive.content } == listOf("urgent", "pricing") }
        }
        press("Remove label urgent")
        pumpUntil("removed") { patches().any { p -> p["labels"]?.jsonArray?.map { it.jsonPrimitive.content } == emptyList<String>() } }
    }

    @Test
    fun reTranscribeAsALanguageWarnsOnceForEnglishOnlyOnUrdu() {
        open(item().let { it.copy(transcript = it.transcript!!.copy(finalLanguage = "ur", preview = "جی بالکل")) })
        openMenu()
        press("Change language & re-transcribe")
        press("English only")
        press("Re-transcribe")
        compose.onNodeWithText("English only makes the engine translate", substring = true).assertExists()
        assertTrue(server.requestsTo("POST", "/api/sessions/.+/retranscribe").isEmpty())
        press("Re-transcribe anyway")
        pumpUntil("asked") { server.requestsTo("POST", "/api/sessions/.+/retranscribe").isNotEmpty() }
        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/sessions/.+/retranscribe").single().body!!.utf8()).jsonObject
        assertEquals("en", body["language"]?.jsonPrimitive?.content)
        // One toast: the view model's own (the shell shows its messages), none of the page's.
        pumpUntil("said once") { vm.message.value?.startsWith("Re-transcribing in English") == true }
        compose.onAllNodesWithText("Re-transcribing", substring = true).assertCountEquals(0)
    }

    @Test
    fun exportOffersEveryFormatAndAsksTheServerForIt() {
        open()
        openMenu()
        press("Export")
        listOf(
            "PDF (print)", "Word (.docx)", "Text (.txt)", "Markdown (.md)", "Subtitles (.srt)", "Subtitles (.vtt)", "JSON",
            "Web page (.html)", "Meeting pack (PDF, with screens)", "Meeting pack (ZIP)"
        ).forEach { compose.onNodeWithText(it).assertExists() }
        press("Word (.docx)")
        pumpUntil("export asked") { server.requestsTo("GET", "/api/transcripts/.+/export").isNotEmpty() }
        assertEquals("docx", server.requestsTo("GET", "/api/transcripts/.+/export").last().url.queryParameter("format"))
        openMenu()
        press("Export")
        press("Meeting pack (ZIP)")
        pumpUntil("pack asked") { server.requestsTo("GET", "/api/sessions/.+/pack").isNotEmpty() }
        assertEquals("zip", server.requestsTo("GET", "/api/sessions/.+/pack").last().url.queryParameter("format"))
        // Every format names the server's own one.
        assertEquals(
            listOf("html", "docx", "txt", "md", "srt", "vtt", "json", "html", "html", "zip"),
            ExportFormat.entries.map { it.wire }
        )
    }

    @Test
    fun deleteAsksFirstThenGoesToTrashAndUndoWorksAfterThePageHasGone() {
        server.on("POST", "/api/sessions/.+/restore", FakeServer.empty(204))
        var open by androidx.compose.runtime.mutableStateOf(true)
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    androidx.compose.foundation.layout.Box {
                        // The host removes the page on Back, as the shell does.
                        if (open) V2RecordingScreen(vm, item(), onBack = { open = false })
                        EchoToastHost(toasts)
                    }
                }
            }
        }
        openMenu()
        press("Delete")
        compose.onNodeWithText("Delete this recording?").assertExists()
        assertTrue(server.requestsTo("DELETE", "/api/sessions/.+").isEmpty())
        // The dialog's own Delete (the last one on screen) confirms.
        val deletes = compose.onAllNodes(hasText("Delete") and hasClickAction())
        deletes[deletes.fetchSemanticsNodes().size - 1].performClick()
        pumpUntil("deleted") { server.requestsTo("DELETE", "/api/sessions/.+").isNotEmpty() }
        assertTrue("the page has gone", !open)
        compose.onAllNodesWithText("Hi, thanks for calling.").assertCountEquals(0)
        awaitText("Undo")
        press("Undo")
        pumpUntil("restored") { server.requestsTo("POST", "/api/sessions/.+/restore").isNotEmpty() }
    }

    @Test
    fun copyMenuPutsTextWithAndWithoutTimesAndForAiWithItsHeader() {
        open()
        awaitText("Hi, thanks for calling.")
        press("Copy")
        press("Copy text")
        awaitText("Copied")
        assertEquals("You: Hello there.\nClient: Hi, thanks for calling.", clipboard()?.trim()?.replace("Me:", "You:"))
        press("Copy")
        press("Copy with timestamps")
        pumpUntil("with times") { clipboard()?.contains("0:12") == true }
        press("Copy")
        press("Copy without timestamps")
        pumpUntil("without times") { clipboard()?.contains("0:12") == false }
        press("Copy")
        press("Copy for AI")
        pumpUntil("for AI") { clipboard()?.startsWith("Title: Call with Acme") == true }
        val forAi = clipboard()!!
        assertTrue(forAi, forAi.contains("Date: 20 Oct 2026"))
        assertTrue(forAi, forAi.contains("Language: English"))
        assertTrue(forAi, forAi.contains("Hi, thanks for calling."))
    }

    /** A transcript whose Roman Urdu and English views are made. */
    private fun withViews() {
        val withViews = ServerFixtures.transcript().trimEnd().removeSuffix("}") +
            ""","views":{"available":["roman","english"],"status":"ready","default":"original"}}"""
        server.on("GET", "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}", FakeServer.json(200, withViews))
        server.on(
            "GET",
            "/api/transcripts/.+/views",
            FakeServer.json(
                200,
                """{"status":"ready","available":["roman","english"],"roman":["Hello ji.","Shukriya call karne ka."],"english":null}"""
            )
        )
    }

    @Test
    fun theViewSwitchShowsRomanUrduAtOnceAndCopyFollowsIt() {
        withViews()
        open()
        awaitText("Hi, thanks for calling.")
        compose.onNodeWithText("Original").assertExists()
        compose.onNodeWithText("English").assertExists()
        press("Roman Urdu")
        awaitText("Shukriya call karne ka.")
        compose.onNodeWithText("Roman Urdu view. The transcript itself is unchanged.").assertExists()
        press("Copy")
        press("Copy text")
        pumpUntil("copied in the view") { clipboard()?.contains("Shukriya call karne ka.") == true }
        press("Original")
        awaitText("Hi, thanks for calling.")
    }

    @Test
    fun findCountsTheMatchingLinesAndStepsThroughThem() {
        open()
        awaitText("Hi, thanks for calling.")
        press("Find in transcript")
        compose.onNode(hasSetTextAction()).performTextReplacement("h")
        awaitText("1 of 2")
        press("Next match")
        awaitText("2 of 2")
        compose.onNode(hasSetTextAction()).performTextReplacement("zebra")
        awaitText("No matches")
        press("Close find")
        compose.onAllNodes(hasSetTextAction()).assertCountEquals(0)
    }

    @Test
    fun aLineIsCopiedFromItsLongPressMenuWithTheNameTheServerGaveTheSpeaker() {
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(
                200,
                ServerFixtures.sessionDetail(playbackUrl = null).trimEnd().removeSuffix("}") + ""","speaker_names":{"Client":"Omar"}}"""
            )
        )
        open()
        awaitText("Omar")
        compose.onNodeWithText("Hi, thanks for calling.").performTouchInput { longClick() }
        compose.waitForIdle()
        press("Copy line")
        pumpUntil("line copied") { clipboard() == "Omar: Hi, thanks for calling." }
        awaitText("Line copied")
    }

    @Test
    fun theOldVersionIsOneTapAwayAfterARetranscribe() {
        server.on(
            "GET",
            "/api/transcripts/01JTRANSCRIPT0000000000OLD",
            FakeServer.json(200, ServerFixtures.transcript(text = null).replace("Hi, thanks for calling.", "The older words."))
        )
        open(
            item().let {
                it.copy(
                    transcript = it.transcript!!.copy(
                        alternateTranscriptId = "01JTRANSCRIPT0000000000OLD",
                        alternateStatus = "completed",
                        alternateLanguage = "ur"
                    )
                )
            }
        )
        awaitText("Hi, thanks for calling.")
        press("Previous (Urdu)")
        awaitText("The older words.")
        press("Current")
        awaitText("Hi, thanks for calling.")
    }

    @Test
    fun aTapOnALinePlaysFromItWhenTheRecordingCanPlay() {
        val played = mutableListOf<Long>()
        val segments = Json { ignoreUnknownKeys = true }
            .decodeFromString(com.echoledger.mobile.data.TranscriptDto.serializer(), ServerFixtures.transcript()).segments
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                com.echoledger.mobile.ui.v2.library.V2RecordingContent(
                    item = item(),
                    transcript = com.echoledger.mobile.ui.v2.library.TranscriptState.Lines(segments),
                    positionMs = 0,
                    durationMs = 2_530_000,
                    playing = false,
                    speed = 1f,
                    onPlayPause = {},
                    onSeek = {},
                    onSpeed = {},
                    onShare = {},
                    onDelete = {},
                    onRetry = {},
                    onBack = {},
                    tools = com.echoledger.mobile.ui.v2.library.TranscriptTools(canPlay = true, onPlay = { played += it })
                )
            }
        }
        compose.onNodeWithText("Hi, thanks for calling.").performClick()
        assertEquals(listOf(12_400L), played)
    }

    private fun content(
        head: com.echoledger.mobile.ui.v2.library.RecordingHead = com.echoledger.mobile.ui.v2.library.RecordingHead(),
        speed: Float = 1f,
        onSpeed: () -> Unit = {}
    ) = show {
        val segments = Json { ignoreUnknownKeys = true }
            .decodeFromString(com.echoledger.mobile.data.TranscriptDto.serializer(), ServerFixtures.transcript()).segments
        EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
            com.echoledger.mobile.ui.v2.library.V2RecordingContent(
                item = item(pinned = true, keep = true),
                transcript = com.echoledger.mobile.ui.v2.library.TranscriptState.Lines(segments),
                positionMs = 0,
                durationMs = 2_530_000,
                playing = false,
                speed = speed,
                onPlayPause = {},
                onSeek = {},
                onSpeed = onSpeed,
                onShare = {},
                onDelete = {},
                onRetry = {},
                onBack = {},
                head = head
            )
        }
    }

    @Test
    fun theSpeedStepsThroughClassicsSpeeds() {
        val seen = generateSequence(0.75f) { com.echoledger.mobile.ui.v2.library.nextSpeed(it) }.take(6).toList()
        assertEquals(listOf(0.75f, 1f, 1.25f, 1.5f, 2f, 0.75f), seen)
        assertEquals("0.75×", com.echoledger.mobile.ui.v2.library.speedLabel(0.75f))
    }

    @Test
    fun skipSilenceAndTheBookmarksAreUnderThePlayer() {
        val skips = mutableListOf<Boolean>()
        val bookmarks = mutableListOf<Long>()
        content(
            com.echoledger.mobile.ui.v2.library.RecordingHead(
                device = "Pixel 9",
                bookmarks = listOf(com.echoledger.mobile.data.SessionMarkerDto("m1", 83_000, "Price")),
                onBookmark = { bookmarks += it },
                onSkipSilence = { skips += it }
            )
        )
        press("Skip silence")
        assertEquals(listOf(true), skips)
        press("Bookmark at 1:23")
        assertEquals(listOf(83_000L), bookmarks)
        compose.onNodeWithText("Pixel 9", substring = true).assertExists()
    }

    @Test
    fun detailsHaveTheDeviceDateLanguageProjectLabelsAndKeeping() {
        content(com.echoledger.mobile.ui.v2.library.RecordingHead(device = "Pixel 9"))
        press("Details")
        listOf("Device", "Pixel 9", "Length", "Recorded", "Project", "Acme Holdings", "Labels", "urgent", "Kept", "Forever", "Pinned")
            .forEach { compose.onAllNodesWithText(it).onFirst().assertExists() }
    }

    private fun transcriptJson(extra: String = "", status: String = "completed", errorCode: String? = null, segments: String? = null) =
        ServerFixtures.transcript(status = status, errorCode = errorCode, segments = segments ?: ServerFixtures.DEFAULT_SEGMENTS)
            .trimEnd().removeSuffix("}") + extra + "}"

    private fun serveTranscript(json: String) =
        server.on("GET", "/api/transcripts/${ServerFixtures.TRANSCRIPT_ID}", FakeServer.json(200, json))

    private var settingsOpened: String? = null
    private var captureTests = 0

    private fun openWithCallbacks(item: LibraryItem = item()) = show {
        EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
            val toasts = rememberEchoToastState()
            CompositionLocalProvider(LocalEchoToasts provides toasts) {
                V2RecordingScreen(vm, item, onBack = {
                    backs++
                }, onCaptureTest = { captureTests++ }, onOpenSettings = { settingsOpened = it })
            }
        }
    }

    @Test
    fun openingTheRecordingMarksItOpened() {
        open()
        pumpUntil("opened") { ServerFixtures.SESSION_ID in vm.settings.value.libraryOpened }
    }

    @Test
    fun aRecordingWithNoAudioSaysSoAndOffersTheCaptureTestAndDelete() {
        serveTranscript(transcriptJson(status = "failed", errorCode = "no_audio", segments = "[]"))
        openWithCallbacks()
        awaitText("Nothing was heard in this recording")
        // Nothing to play or share (classic): no player and no Share; nothing to re-transcribe either.
        compose.onAllNodes(hasContentDescription("Play")).assertCountEquals(0)
        compose.onAllNodes(hasText("Share") and hasClickAction()).assertCountEquals(0)
        press("More")
        compose.onAllNodesWithText("Change language & re-transcribe").assertCountEquals(0)
        androidx.test.espresso.Espresso.pressBack()
        compose.waitForIdle()
        press("Run capture test")
        assertEquals(1, captureTests)
        press("Delete")
        compose.onNodeWithText("Delete this recording?").assertExists()
    }

    @Test
    fun aRecordingOnItsWayShowsTheStepsAndNotifyMe() {
        server.on("GET", "/api/transcripts/.+", FakeServer.json(404, """{"message":"No query results."}"""))
        openWithCallbacks(item().let { it.copy(transcript = it.transcript!!.copy(finalStatus = "processing")) })
        awaitText("Notify me when ready")
        compose.onNode(hasContentDescription("Progress: Uploaded, Transcribing, Improve"), useUnmergedTree = true).assertExists()
        press("Notify me when ready")
        awaitText("You will get a notification when it is ready.")
    }

    @Test
    fun aFailedTranscriptSaysWhyAndRetries() {
        serveTranscript(transcriptJson(status = "failed", errorCode = "provider_timeout", segments = "[]"))
        openWithCallbacks()
        awaitText("Transcription took too long and was stopped.")
        press("Retry")
        pumpUntil("retried") { server.requestsTo("POST", "/api/sessions/.+/retranscribe").isNotEmpty() }
    }

    @Test
    fun aRefusedOwnKeyOpensTheKeyPageInsteadOfRetrying() {
        serveTranscript(transcriptJson(status = "failed", errorCode = "own_key_rejected", segments = "[]"))
        openWithCallbacks()
        awaitText("Groq did not accept your key.")
        press("Check your key")
        assertEquals("key", settingsOpened)
        assertTrue(server.requestsTo("POST", "/api/sessions/.+/retranscribe").isEmpty())
    }

    @Test
    fun anEmptyTranscriptSaysNoSpeechWasDetected() {
        serveTranscript(transcriptJson(segments = "[]"))
        openWithCallbacks()
        awaitText("The transcript is empty: no speech was detected.")
    }

    @Test
    fun aBackupEngineTranscriptOffersTheBestOneAndFixesCanBeAccepted() {
        serveTranscript(
            transcriptJson(
                ""","backup_engine":true,"suggestions":[{"id":7,"index":1,"from":"Akme","to":"Acme","reason":"A project name"}]"""
            )
        )
        server.on("POST", "/api/transcripts/.+/suggestions/7", FakeServer.json(200, ServerFixtures.transcript()))
        openWithCallbacks()
        awaitText("Made with a backup service.")
        press("Re-transcribe")
        pumpUntil("best engine asked") { server.requestsTo("POST", "/api/sessions/.+/retranscribe").isNotEmpty() }
        awaitText("Suggested fixes")
        press("Accept")
        pumpUntil("fix accepted") { server.requestsTo("POST", "/api/transcripts/.+/suggestions/7").isNotEmpty() }
    }

    @Test
    fun summaryAndMomentsTabsSayWhatTheyHave() {
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(
                200,
                ServerFixtures.sessionDetail(playbackUrl = null).trimEnd().removeSuffix("}") +
                    ""","markers":[{"id":"m1","at_ms":83000,"tag":"Price"}]}"""
            )
        )
        open()
        awaitText("Hi, thanks for calling.")
        press("Summary")
        awaitText("Write call notes")
        press("Write call notes")
        pumpUntil("notes asked") { server.requestsTo("POST", "/api/sessions/.+/notes").isNotEmpty() }
        press("Moments")
        awaitText("Bookmark · Price")
    }
}
