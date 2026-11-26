package com.echoledger.mobile.ui.v2

import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.work.WorkInfo
import androidx.work.WorkManager
import com.echoledger.mobile.core.SharedNotes
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.v2.live.V2SharedNotesScreen
import com.echoledger.mobile.ui.v2.shell.EchoV2Frame
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * A voice note shared to EchoLedger in the new design (parity.csv PH-OTH-038): its stage while it uploads and is
 * transcribed, Continue in background (back to the app, a notification follows), then the note itself with Copy and,
 * in ⋮, Back to <app>; in Urdu, the stage in Urdu.
 */
class V2SharedNotesParityTest : ScreenTest() {
    private var backToSource = 0
    private var left = 0

    @Before
    fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(listOf(ServerFixtures.libraryItem(ServerFixtures.SESSION_ID, title = "Voice note")))
            )
        )
        server.on("GET", "/api/transcripts/.+", FakeServer.json(200, ServerFixtures.transcript()))
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(200, ServerFixtures.sessionDetail(playbackUrl = null))
        )
    }

    private fun page(ids: List<String>, language: EchoLanguage = EchoLanguage.English) = show {
        EchoV2Frame(vm, darkTheme = false, language = language) {
            V2SharedNotesScreen(vm, ids, onLeave = { left++ }, sourceApp = "WhatsApp", onBackToSource = { backToSource++ })
        }
    }

    private fun named(words: String) = (hasText(words) or hasContentDescription(words)) and hasClickAction()

    private fun clipboard(): String? =
        app.getSystemService(android.content.ClipboardManager::class.java).primaryClip?.getItemAt(0)?.text?.toString()

    private fun seen(words: String) = compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()

    @Test
    fun aNoteStillOnItsWaySaysWhereItIsAndContinueInBackgroundLeavesANotificationToFollowIt() {
        app.storeRecording(id = "note-1", state = "queued", sourceApp = "voice_note")
        vm.reloadLocal()
        SharedNotes.remember(app, listOf("note-1"))
        page(listOf("note-1"))
        pumpUntil("its stage") { seen("Waiting to upload…") }
        assertTrue(seen("Shared voice note"))
        assertTrue(seen("The transcript opens here as soon as it is ready."))
        compose.onNode(hasText("Continue in background") and hasClickAction()).performClick()
        assertEquals(1, backToSource)
        val work = WorkManager.getInstance(app).getWorkInfosForUniqueWork("shared-notes:note-1").get()
        assertEquals(WorkInfo.State.ENQUEUED, work.single().state)
    }

    @Test
    fun aReadyNoteOpensOnItsPageWithCopyAndBackToTheApp() {
        runBlocking { app.graph.library.refresh() }
        SharedNotes.remember(app, listOf("note-1"))
        SharedNotes.linkSession(app, "note-1", ServerFixtures.SESSION_ID)
        page(listOf("note-1"))
        pumpUntil("the note") { seen("Hi, thanks for calling.") }
        // Copy puts the note's words on the clipboard; Copy for AI is in its menu.
        compose.onAllNodes(named("Copy text without timestamps")).onFirst().performClick()
        pumpUntil("copied") { clipboard()?.contains("Hi, thanks for calling.") == true }
        compose.onAllNodes(named("More ways to copy")).onFirst().performClick()
        compose.onNode(hasText("Copy for AI") and hasClickAction()).performClick()
        pumpUntil("copied for AI") { clipboard()?.startsWith("Title:") == true }
        compose.onAllNodes((hasText("More") or hasContentDescription("More")) and hasClickAction()).onFirst().performClick()
        compose.onNode(hasText("Back to WhatsApp") and hasClickAction()).performClick()
        assertEquals(1, backToSource)
    }

    @Test
    fun severalNotesAreListedAndOneThatIsReadyOpensItsPageThenBackReturnsToTheList() {
        runBlocking { app.graph.library.refresh() }
        app.storeRecording(id = "note-2", state = "queued", sourceApp = "voice_note")
        vm.reloadLocal()
        SharedNotes.remember(app, listOf("note-1", "note-2"))
        SharedNotes.linkSession(app, "note-1", ServerFixtures.SESSION_ID)
        page(listOf("note-1", "note-2"))
        pumpUntil("the list") { seen("2 voice notes") && seen("Transcript ready") && seen("Waiting to upload…") }
        compose.onNode(hasText("Voice note 1", substring = true) and hasClickAction()).performClick()
        pumpUntil("its page") { seen("Hi, thanks for calling.") }
        androidx.test.espresso.Espresso.pressBack()
        pumpUntil("back on the list") { seen("2 voice notes") }
        assertEquals(0, left)
    }

    @Test
    fun inUrduTheStageIsInUrdu() {
        app.storeRecording(id = "note-1", state = "queued", sourceApp = "voice_note")
        vm.reloadLocal()
        SharedNotes.remember(app, listOf("note-1"))
        // Signed in, the account's language (BE-06), never the phone's.
        speak(EchoLanguage.Urdu)
        page(listOf("note-1"), EchoLanguage.Urdu)
        pumpUntil("the Urdu stage") { seen("اپ لوڈ کا انتظار…") }
        assertTrue(seen("پس منظر میں جاری رکھیں"))
    }
}
