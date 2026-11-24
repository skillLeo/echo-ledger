package com.echoledger.mobile.ui.v2

import androidx.activity.OnBackPressedDispatcher
import androidx.activity.compose.LocalOnBackPressedDispatcherOwner
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasScrollToIndexAction
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performImeAction
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.test.performTextReplacement
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeDown
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.library.V2TrashScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Library rows and tools that classic has and v2 now matches (docs/v2/audit/parity.csv PH-LIB): pull to sync, the
 * pinned mark, the unopened dot, the project on the meta line, the failed and no-audio lines, each filter chip's ✕,
 * recent searches, Back leaving search, Trash's restore and erase, and a long library.
 */
class V2LibraryParityTest : ScreenTest() {
    private lateinit var back: OnBackPressedDispatcher

    private fun item(
        id: String,
        title: String,
        extra: String = "",
        preview: String? = "Thanks for calling about the invoice",
        finalStatus: String? = "completed",
        client: String? = "Acme Holdings"
    ) = ServerFixtures.libraryItem(id, title = title, preview = preview, finalStatus = finalStatus, client = client)
        .let { if (extra.isEmpty()) it else it.replace("\"updated_at\"", "$extra,\"updated_at\"") }

    private fun seed(rows: List<String>, first: String) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(rows)))
        server.on("GET", "/api/search.*", FakeServer.json(200, """{"data":[]}"""))
        runBlocking { app.graph.library.refresh() }
        show {
            back = LocalOnBackPressedDispatcherOwner.current!!.onBackPressedDispatcher
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    androidx.compose.foundation.layout.Box {
                        V2LibraryScreen(vm, initials = "AS", onOpen = {}, onTrash = {}, onAccount = {})
                        EchoToastHost(toasts)
                    }
                }
            }
        }
        pumpUntil("the library") { compose.onAllNodesWithText(first, substring = true).fetchSemanticsNodes().isNotEmpty() }
    }

    private fun twoRows() = seed(
        listOf(
            item("01JA", "Bilal Traders", preview = "New rate for 40 cartons of basmati"),
            item("01JB", "Rehman and Sons Textiles", finalStatus = null, client = null)
        ),
        "Bilal Traders"
    )

    @Test
    fun pullingTheListDownSyncsTheLibraryAgain() {
        twoRows()
        val before = server.requestsTo("GET", "/api/library").size
        compose.onNode(hasScrollToIndexAction()).performTouchInput { swipeDown(startY = top + 10f, endY = bottom, durationMillis = 400) }
        pumpUntil("synced again") { server.requestsTo("GET", "/api/library").size > before }
    }

    @Test
    fun aPinnedRowCarriesThePinMarkAndAnUnpinnedOneDoesNot() {
        seed(listOf(item("01JA", "Bilal Traders", "\"pinned\":true"), item("01JB", "Rehman and Sons Textiles")), "Bilal Traders")
        compose.onAllNodes(hasContentDescription("Pinned"), useUnmergedTree = true).assertCountEquals(1)
    }

    @Test
    fun anUnopenedRowSaysSoUntilItIsOpened() {
        seed(
            listOf(item("01JA", "Bilal Traders", "\"opened\":false"), item("01JB", "Rehman and Sons Textiles", "\"opened\":true")),
            "Bilal Traders"
        )
        val notOpened = SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, "Not opened yet")
        compose.onAllNodes(notOpened).assertCountEquals(1)
        compose.onNode(notOpened and hasText("Bilal Traders", substring = true)).assertExists()
        // Semibold until opened; an opened row's title is in the regular weight.
        assertTrue(weightOf("Bilal Traders") > weightOf("Rehman and Sons Textiles"))
        compose.onNodeWithText("Bilal Traders", substring = true).performClick()
        pumpUntil("marked opened") { server.requestsTo("POST", "/api/sessions/01JA/opened").isNotEmpty() }
        pumpUntil("the dot went") { compose.onAllNodes(notOpened).fetchSemanticsNodes().isEmpty() }
        assertEquals(weightOf("Rehman and Sons Textiles"), weightOf("Bilal Traders"))
    }

    /** The weight a title is drawn in (its text layout's style). */
    private fun weightOf(title: String): Int {
        val node = compose.onNode(hasText(title), useUnmergedTree = true).fetchSemanticsNode()
        val layouts = mutableListOf<androidx.compose.ui.text.TextLayoutResult>()
        node.config[androidx.compose.ui.semantics.SemanticsActions.GetTextLayoutResult].action?.invoke(layouts)
        return (layouts.single().layoutInput.style.fontWeight ?: androidx.compose.ui.text.font.FontWeight.Normal).weight
    }

    @Test
    fun theMetaLineHasTheProjectThenWhatWasSaid() {
        twoRows()
        compose.onNodeWithText("Acme Holdings · New rate for 40 cartons of basmati", useUnmergedTree = true).assertExists()
    }

    @Test
    fun aFailedRowSaysTapToRetryAndARowWithNothingHeardSaysNoAudio() {
        val noAudio = item("01JB", "Rehman and Sons Textiles", finalStatus = "failed")
            .replace("\"live_available\"", "\"final_error_code\":\"no_audio\",\"live_available\"")
        seed(listOf(item("01JA", "Bilal Traders", finalStatus = "failed"), noAudio), "Bilal Traders")
        compose.onNodeWithText("Transcription failed · Tap to retry", useUnmergedTree = true).assertExists()
        compose.onNodeWithText("No audio", useUnmergedTree = true).assertExists()
        // The failure line takes the meta line's place.
        compose.onAllNodesWithText("Thanks for calling about the invoice", substring = true, useUnmergedTree = true).assertCountEquals(0)
    }

    @Test
    fun eachAppliedFilterHasItsPillWithItsOwnRemove() {
        twoRows()
        compose.onAllNodes(hasContentDescription("Remove Status: Not transcribed")).assertCountEquals(0)
        compose.onNode(hasContentDescription("Filters") and hasClickAction()).performClick()
        compose.onNode(hasText("Not transcribed") and isToggleable()).performScrollTo().performClick()
        compose.onNodeWithText("Show 1 result").performClick()
        compose.onNodeWithText("Bilal Traders", substring = true).assertDoesNotExist()
        compose.onNodeWithContentDescription("Remove Status: Not transcribed").performClick()
        compose.onNodeWithText("Bilal Traders", substring = true).assertExists()
        compose.onAllNodes(hasContentDescription("Remove Status: Not transcribed")).assertCountEquals(0)
    }

    /** Search is the ⌕ in the title row (v2.1 UX-14): it opens over the page. */
    private fun openSearch() {
        compose.onNodeWithContentDescription("Search recordings").performClick()
        compose.waitForIdle()
    }

    @Test
    fun aSearchIsKeptOnThisPhoneAndATapSearchesAgain() {
        twoRows()
        openSearch()
        val field = compose.onNode(hasSetTextAction())
        field.performClick()
        field.performTextInput("basmati")
        field.performImeAction()
        pumpUntil("kept") { vm.settings.value.recentSearches == listOf("basmati") }
        // Back leaves search: the words go, the list comes back, and they wait under Recent searches.
        compose.runOnIdle { back.onBackPressed() }
        compose.onNodeWithText("Rehman and Sons Textiles", substring = true).assertExists()
        openSearch()
        field.performClick()
        compose.onNodeWithText("Recent searches").assertExists()
        compose.onNode(hasText("basmati") and hasClickAction()).performClick()
        compose.onNodeWithText("Rehman and Sons Textiles", substring = true).assertDoesNotExist()
        compose.onNodeWithText("Bilal Traders", substring = true).assertExists()
    }

    @Test
    fun onlyTheLastFiveSearchesAreKeptAndNeverInASettingsExport() {
        twoRows()
        openSearch()
        val field = compose.onNode(hasSetTextAction())
        listOf("one", "two", "three", "four", "five", "six", "Four").forEach { words ->
            field.performTextReplacement(words)
            field.performImeAction()
        }
        // Newest first, five at most, the same words once (capitals ignored).
        pumpUntil("kept") { vm.settings.value.recentSearches == listOf("Four", "six", "five", "three", "two") }
        val exported = com.echoledger.mobile.core.SettingsTransfer.export(vm.settings.value)
        assertTrue(exported, !exported.contains("three") && !exported.contains("recentSearches"))
    }

    @Test
    fun backLeavesSearchAndKeepsTheWords() {
        twoRows()
        openSearch()
        val field = compose.onNode(hasSetTextAction())
        field.performClick()
        field.performTextInput("Rehman")
        compose.onNodeWithText("Bilal Traders", substring = true).assertDoesNotExist()
        compose.runOnIdle { assertTrue("Back is taken by search", back.hasEnabledCallbacks()) }
        compose.runOnIdle { back.onBackPressed() }
        compose.onNodeWithText("Bilal Traders", substring = true).assertExists()
        pumpUntil("kept") { vm.settings.value.recentSearches == listOf("Rehman") }
        // Search is closed (v2.1 UX-14); opened again, it is empty.
        compose.onAllNodes(hasSetTextAction()).assertCountEquals(0)
        openSearch()
        compose.onNode(hasSetTextAction()).assert(hasText("Rehman", substring = true).not())
    }

    @Test
    fun aLongLibraryDrawsOnlyTheRowsInView() {
        val rows = (1..2000).map { n ->
            val id = "01K" + n.toString().padStart(5, '0')
            item(id, "Caller ${n.toString().padStart(4, '0')}", preview = "Line $n", client = null)
        }
        seed(rows, "Caller")
        val drawn = compose.onAllNodesWithText("Caller ", substring = true).fetchSemanticsNodes().size
        assertTrue("drawn $drawn of 2000", drawn in 1..60)
        assertEquals(2000, vm.library.value.count { !it.deleted })
    }

    @Test
    fun trashRestoresAndErasesOnlyAfterAsking() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/bin",
            FakeServer.json(
                200,
                """{"data":[{"id":"01JT","title":"Old call","started_at":"2026-11-05T10:15:00+00:00","deleted_at":"2026-11-06T18:28:34+00:00","erase_at":"2026-11-08T18:28:34+00:00"}]}"""
            )
        )
        server.on("POST", "/api/sessions/01JT/restore", FakeServer.empty(204))
        server.on("DELETE", "/api/bin/01JT", FakeServer.empty(204))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        show { EchoV2Theme(darkTheme = false, language = EchoLanguage.English) { V2TrashScreen(vm, configKeepHours = 48, onBack = {}) } }
        pumpUntil("trash") { compose.onAllNodesWithText("Old call").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Deleted recordings stay here for 48 hours", substring = true).assertExists()
        compose.onNodeWithText("Erased on", substring = true, useUnmergedTree = true).assertExists()
        compose.onNodeWithText("Old call").performClick()
        compose.onNodeWithText("Erase now").performClick()
        compose.onNodeWithText("Erase this recording now?").assertExists()
        assertTrue(server.requestsTo("DELETE", "/api/bin/01JT").isEmpty())
        compose.onNodeWithText("Cancel").performClick()
        assertTrue(server.requestsTo("DELETE", "/api/bin/01JT").isEmpty())
        compose.onNodeWithText("Old call").performClick()
        compose.onNodeWithText("Erase now").performClick()
        compose.onAllNodes(hasText("Erase now") and hasClickAction()).onFirst().performClick()
        pumpUntil("erased") { server.requestsTo("DELETE", "/api/bin/01JT").isNotEmpty() }
        pumpUntil("listed again") { compose.onAllNodesWithText("Old call").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Old call").performClick()
        compose.onNodeWithText("Restore").performClick()
        pumpUntil("restored") { server.requestsTo("POST", "/api/sessions/01JT/restore").isNotEmpty() }
    }

    @Test
    fun anImportantRowHasItsFlagAndTheSelectionMarksAndUnmarksImportant() {
        seed(listOf(item("01JA", "Bilal Traders", "\"important\":true"), item("01JB", "Rehman and Sons Textiles")), "Bilal Traders")
        server.on("POST", "/api/sessions/bulk", FakeServer.json(200, """{"changed": 1}"""))
        compose.onAllNodes(hasContentDescription("Important"), useUnmergedTree = true).assertCountEquals(1)
        compose.onNodeWithText("Rehman and Sons Textiles", substring = true).performTouchInput { longClick() }
        compose.onNodeWithContentDescription("More").performClick()
        // Only Rehman is selected and it is not important: Mark important, no Unmark.
        compose.onAllNodesWithText("Unmark important").assertCountEquals(0)
        compose.onAllNodes(hasText("Mark important") and hasClickAction()).onFirst().performClick()
        pumpUntil("marked") {
            server.requestsTo("POST", "/api/sessions/bulk").any {
                it.bodyText().contains("\"important\"") && it.bodyText().contains("01JB")
            }
        }
    }

    @Test
    fun aRowShowsItsListsAsColourDotsNamedForScreenReaders() {
        val lists = "\"labels\":[\"Clients\",\"Home\",\"Family\",\"Extra\"],\"label_colors\":{\"Clients\":\"#16a34a\"}"
        seed(listOf(item("01JA", "Bilal Traders", lists)), "Bilal Traders")
        listOf("Clients", "Home", "Family").forEach {
            compose.onAllNodes(hasContentDescription(it), useUnmergedTree = true).assertCountEquals(1)
        }
        // Up to three.
        compose.onAllNodes(hasContentDescription("Extra"), useUnmergedTree = true).assertCountEquals(0)
        // Each dot's colour is the label's in label_colors (capitals ignored); a label without one has none.
        val row = vm.library.value.single()
        assertEquals("#16a34a", com.echoledger.mobile.ui.v2.library.LibraryModel.labelColour(row, "Clients"))
        assertEquals("#16a34a", com.echoledger.mobile.ui.v2.library.LibraryModel.labelColour(row, "clients"))
        assertEquals(null, com.echoledger.mobile.ui.v2.library.LibraryModel.labelColour(row, "Home"))
    }

    @Test
    fun audioKeptWithoutATranscriptSaysHowManyDaysAreLeft() {
        val expires = java.time.OffsetDateTime.now().plusDays(17).plusHours(6).toString()
        seed(listOf(item("01JA", "Bilal Traders", "\"expires_at\":\"$expires\"", finalStatus = null)), "Bilal Traders")
        compose.onNodeWithText("Audio · 18 days left", useUnmergedTree = true).assertExists()
        val now = java.time.Instant.parse("2026-11-21T10:00:00Z")
        assertEquals(1, com.echoledger.mobile.ui.v2.library.LibraryModel.daysLeft("2026-11-21T11:00:00+00:00", now))
        assertEquals(0, com.echoledger.mobile.ui.v2.library.LibraryModel.daysLeft("2026-11-20T11:00:00+00:00", now))
        assertEquals(2, com.echoledger.mobile.ui.v2.library.LibraryModel.daysLeft("2026-11-23T09:00:00+00:00", now))
        assertEquals(null, com.echoledger.mobile.ui.v2.library.LibraryModel.daysLeft(null, now))
    }
}
