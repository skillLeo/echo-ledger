package com.echoledger.mobile.ui.v2

import androidx.compose.foundation.layout.height
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeLeft
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.core.RowProgress
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Library's uploads against classic's (docs/v2/audit/parity.csv PH-LIB-054, 056, 057): "Waiting to upload" and
 * "Uploading 40%"; an import the Library already had says so and a tap removes it; a waiting or failed upload has ⋮
 * (and a long press) with Retry upload and Delete recording…, which asks first because this phone has the only copy;
 * a row being transcribed says which part and the time left beside its badge (PH-LIB-048); and offline, the last
 * synced library stays with classic's banner, whose Try again syncs (PH-LIB-058); Library's swipes can be turned off
 * in Settings › Appearance (PH-LIB-075); the selection exports as a Web page (PH-LIB-071) and copies for AI, the note
 * once and each recording's header and lines (PH-LIB-072).
 */
class V2LibraryFlowParityTest : ScreenTest() {
    private val bilal = ServerFixtures.libraryItem("01JA", title = "Bilal Traders")

    private fun library(
        language: EchoLanguage = EchoLanguage.English,
        rows: List<String> = listOf(bilal),
        /** Settings › Appearance above the Library, to turn its swipes off from there. */
        withAppearance: Boolean = false
    ) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(200, ServerFixtures.libraryPage(rows))
        )
        runBlocking { app.graph.library.refresh() }
        show {
            EchoV2Theme(darkTheme = false, language = language) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    androidx.compose.foundation.layout.Box {
                        androidx.compose.foundation.layout.Column {
                            if (withAppearance) {
                                val settings by vm.settings.collectAsState()
                                androidx.compose.foundation.layout.Box(Modifier.height(900.dp)) {
                                    com.echoledger.mobile.ui.v2.settings.AppearancePage(
                                        settings,
                                        vm::updateSettings,
                                        com.echoledger.mobile.ui.v2.shell.UiFlags.Off,
                                        {},
                                        highlight = null,
                                        onBack = {}
                                    )
                                }
                            }
                            V2LibraryScreen(vm, initials = "AS", onOpen = {}, onTrash = {}, onAccount = {})
                        }
                        EchoToastHost(toasts)
                    }
                }
            }
        }
        pumpUntil("the library") { compose.onAllNodesWithText("Bilal Traders", substring = true).fetchSemanticsNodes().isNotEmpty() }
    }

    private fun shows(words: String) =
        pumpUntil("\"$words\"") { compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty() }

    @After
    fun forgetProgress() {
        RowProgress.doneSending("up")
    }

    @Test
    fun aWaitingUploadSaysSoAndOneBeingSentSaysHowMuch() {
        app.storeRecording(id = "wait", state = "queued", sourceApp = "whatsapp")
        app.storeRecording(id = "up", state = "uploading", sourceApp = "phone_call")
        library()
        shows("Waiting to upload")
        // Before the first part went it says Uploading; then how much of it (2 of 5 parts sent: 40 %).
        shows("Uploading")
        RowProgress.sent("up", 2, 5)
        shows("Uploading 40%")
        // One being sent cannot be deleted: only the waiting one has ⋮.
        compose.onAllNodes(hasContentDescription("More for", substring = true)).assertCountEquals(1)
    }

    @Test
    fun anImportTheLibraryHadSaysSoAndATapRemovesIt() {
        app.storeRecording(id = "dup", state = "duplicate", sourceApp = "other")
        library()
        shows("Already in your Library · Tap to dismiss")
        compose.onNode(hasText("Already in your Library", substring = true) and hasClickAction()).performClick()
        pumpUntil("the row to go") { app.graph.recordings.get("dup") == null }
        pumpUntil("its row to leave the list") {
            compose.onAllNodesWithText("Already in your Library", substring = true).fetchSemanticsNodes().isEmpty()
        }
    }

    @Test
    fun aFailedUploadsMenuRetriesIt() {
        app.storeRecording(id = "bad", state = "failed", sourceApp = "whatsapp", lastError = "timeout")
        library()
        shows("Failed")
        compose.onNode(hasContentDescription("More for", substring = true)).performClick()
        compose.onNodeWithText("Retry upload").performClick()
        pumpUntil("it to wait to upload again") { app.graph.recordings.get("bad")?.state == "queued" }
        assertNull(app.graph.recordings.get("bad")!!.lastError)
        shows("Waiting to upload")
    }

    @Test
    fun deletingAnUploadThatNeverWentAsksFirstThenDeletesIt() {
        app.storeRecording(id = "wait", state = "queued", sourceApp = "whatsapp")
        library()
        shows("Waiting to upload")
        // A long press opens the same menu as ⋮; a waiting upload has no Retry.
        compose.onNode(hasText("Waiting to upload", substring = true) and hasClickAction()).performTouchInput { longClick() }
        compose.onAllNodesWithText("Retry upload").assertCountEquals(0)
        compose.onNodeWithText("Delete recording…").performClick()
        shows("It was never uploaded, so this phone has the only copy.")
        // Cancel keeps it.
        compose.onNodeWithText("Cancel").performClick()
        compose.waitForIdle()
        assertNotNull(app.graph.recordings.get("wait"))
        compose.onNode(hasContentDescription("More for", substring = true)).performClick()
        compose.onNodeWithText("Delete recording…").performClick()
        compose.onNode(hasText("Delete") and hasClickAction()).performClick()
        pumpUntil("the recording to be deleted") { app.graph.recordings.get("wait") == null }
        // The shell says so as a toast, in the screen's language.
        pumpUntil("the message") { vm.message.value == "Recording deleted" }
        assertEquals(
            V2Testing.resources(EchoLanguage.Urdu).getString(com.echoledger.mobile.R.string.v2_deleted),
            com.echoledger.mobile.ui.v2.shell.VmMessages.localize(V2Testing.resources(EchoLanguage.Urdu), "Recording deleted")
        )
        assertEquals(0, compose.onAllNodesWithText("Waiting to upload").fetchSemanticsNodes().size)
    }

    @Test
    fun inUrduTheUploadsSpeakUrdu() {
        app.storeRecording(id = "wait", state = "queued", sourceApp = "whatsapp")
        app.storeRecording(id = "dup", state = "duplicate", sourceApp = "other")
        library(EchoLanguage.Urdu)
        shows("اپ لوڈ کا انتظار")
        shows("پہلے سے آپ کی لائبریری میں ہے")
        compose.onNode(hasContentDescription("کے لیے مزید", substring = true)).performClick()
        shows("ریکارڈنگ حذف کریں…")
    }

    private fun transcribing(progress: String) =
        ServerFixtures.libraryItem("01JT", title = "Rehman Textiles", status = "processing", finalStatus = "processing")
            .replace("\"preview\":", "\"final_progress\":$progress,\"preview\":")

    @Test
    fun aRowBeingTranscribedSaysWhichPartAndTheTimeLeftBesideItsBadge() {
        library(rows = listOf(bilal, transcribing("""{"done":1,"total":3,"eta_seconds":70}""")))
        shows("Transcribing")
        shows("part 2 of 3 · about 1 min left")
    }

    @Test
    fun inUrduTheRowBeingTranscribedSaysItInUrdu() {
        library(EchoLanguage.Urdu, rows = listOf(bilal, transcribing("""{"done":0,"total":2,"eta_seconds":12}""")))
        shows("حصہ 1 از 2 · تقریباً 15 سیکنڈ باقی")
    }

    @Test
    fun offlineTheLastSyncedLibraryStaysWithTheBannerAndTryAgainSyncs() {
        library()
        server.on("GET", "/api/library", FakeServer.json(503, """{"message":"Unavailable"}"""))
        vm.refresh()
        shows("You're offline. Showing the last synced library.")
        // The rows synced before stay.
        shows("Bilal Traders")
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(listOf(bilal))))
        server.on("GET", "/api/clients", FakeServer.json(200, """{"data":[]}"""))
        val before = server.requestsTo("GET", "/api/library").size
        compose.onNode(hasText("Try again") and hasClickAction()).performClick()
        pumpUntil("synced again") { server.requestsTo("GET", "/api/library").size > before }
        pumpUntil("the banner to go") {
            compose.onAllNodesWithText("You're offline", substring = true).fetchSemanticsNodes().isEmpty()
        }
    }

    @Test
    fun swipesTurnedOffInAppearanceDoNothingAndOnAgainDelete() {
        // Settings search finds it, in Appearance; that switch is what turns them off.
        val en = V2Testing.resources(EchoLanguage.English)
        assertTrue(com.echoledger.mobile.ui.v2.settings.SettingsSearch.search(en, "swipe").any { it.item.id == "appearance.swipes" })
        library(withAppearance = true)
        server.on("POST", "/api/sessions/bulk", FakeServer.json(200, """{"changed": 1}"""))
        compose.onNode(hasText("Swipe actions") and isToggleable()).performClick()
        pumpUntil("swipes off") { !vm.settings.value.librarySwipes }
        compose.waitForIdle()
        compose.onNodeWithText("Bilal Traders", substring = true).performTouchInput { swipeLeft() }
        compose.waitForIdle()
        assertTrue("a swipe with swipes off deletes nothing", server.requestsTo("POST", "/api/sessions/bulk").isEmpty())
        vm.updateSettings { it.copy(librarySwipes = true) }
        pumpUntil("swipes on") { vm.settings.value.librarySwipes }
        compose.waitForIdle()
        compose.onNodeWithText("Bilal Traders", substring = true).performTouchInput { swipeLeft() }
        pumpUntil("deleted") { server.requestsTo("POST", "/api/sessions/bulk").isNotEmpty() }
        assertTrue(server.requestsTo("POST", "/api/sessions/bulk").single().bodyText().contains("\"delete\""))
    }

    private val rehman = ServerFixtures.libraryItem("01JB", title = "Rehman Textiles", client = null)
    private val waiting = ServerFixtures.libraryItem("01JC", title = "Karachi Cargo", status = "processing", finalStatus = "processing")

    private fun pick(vararg titles: String) = titles.forEach { t ->
        compose.onNodeWithText(t, substring = true).performTouchInput { longClick() }
        compose.waitForIdle()
    }

    private fun menuChoice(vararg path: String) {
        compose.onNode(hasContentDescription("More") and hasClickAction()).performClick()
        compose.waitForIdle()
        path.forEach { words ->
            compose.onAllNodes(hasText(words) and hasClickAction()).onFirst()
                .performSemanticsAction(androidx.compose.ui.semantics.SemanticsActions.OnClick)
            compose.waitForIdle()
        }
    }

    @Test
    fun theSelectionExportsAsAWebPage() {
        V2Testing.freshFileProvider()
        library(rows = listOf(bilal, rehman))
        server.on("POST", "/api/sessions/export", FakeServer.json(200, "{}"))
        pick("Bilal Traders", "Rehman Textiles")
        menuChoice("Export", "Web page (.html)")
        pumpUntil("exported") { server.requestsTo("POST", "/api/sessions/export").isNotEmpty() }
        val body = server.requestsTo("POST", "/api/sessions/export").single().bodyText()
        assertTrue(body, body.contains("\"format\":\"html\"") && body.contains("01JA") && body.contains("01JB"))
        pumpUntil("shared") { org.robolectric.Shadows.shadowOf(app).nextStartedActivity != null }
    }

    @Test
    fun theSelectionCopiesForAiTheNoteOnceAndEachRecording() {
        library(rows = listOf(bilal, rehman, waiting))
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
        pick("Bilal Traders", "Rehman Textiles", "Karachi Cargo")
        menuChoice("Export", "Copy for AI")
        pumpUntil("copied") { compose.onAllNodesWithText("Copied 2 of 3 for AI", substring = true).fetchSemanticsNodes().isNotEmpty() }
        val clip = app.getSystemService(android.content.ClipboardManager::class.java).primaryClip!!.getItemAt(0).text.toString()
        val note = com.echoledger.mobile.core.TranscriptText.note("brackets")
        assertTrue(clip, clip.startsWith(note))
        assertEquals("the note once", 1, clip.split(note).size - 1)
        assertTrue(clip, clip.contains("Title: Bilal Traders") && clip.contains("Title: Rehman Textiles"))
        assertTrue("one without a transcript is left out", !clip.contains("Karachi Cargo"))
        assertTrue(clip, clip.contains("\n\n---\n\n"))
        // Only the two finished transcripts were read.
        assertEquals(2, server.requestsTo("GET", "/api/transcripts/.+").size)
    }

    @Test
    fun copyForAiWithNoTranscriptCopiesNothingAndSaysSo() {
        library(rows = listOf(bilal, waiting))
        pick("Karachi Cargo")
        menuChoice("Export", "Copy for AI")
        pumpUntil("said") {
            compose.onAllNodesWithText("Nothing copied: none of them has a transcript yet.").fetchSemanticsNodes().isNotEmpty()
        }
        assertNull(app.getSystemService(android.content.ClipboardManager::class.java).primaryClip)
    }
}
