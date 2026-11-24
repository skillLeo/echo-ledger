package com.echoledger.mobile.ui.v2

import androidx.compose.material3.Text
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasScrollToIndexAction
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isHeading
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeDown
import androidx.compose.ui.test.swipeUp
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.library.KIND_ROW_TAG
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.annotation.Config

/**
 * v2.1 UX-14 on the S25 Ultra at its default display size (412 × 891 dp): a 64 dp title row ("All recordings ▾" · ⌕ ·
 * bell · avatar) and a 48 dp chips row ([Filters] All · Calls · … · Sort & display) take 13 % of the screen or less;
 * the chips row hides while the list scrolls down and comes back as it scrolls up; no ⋮ (Select, Sync now and Settings
 * are in the avatar's menu, Sort & display is the last chip); no Calls chip; search opens over the page.
 */
@Config(sdk = [34], qualifiers = "w412dp-h891dp-xxhdpi")
class CompactHeaderTest : ScreenTest() {
    private val classic = ClassicScreens(
        home = { Text("Classic activity") },
        library = { Text("Classic library") },
        live = { Text("Classic live") },
        ask = { Text("Classic projects") },
        settings = { Text("Classic settings") },
        upgrade = {}
    )

    private val v2 = UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true, "ui_v2.live" to true, "ui_v2.ask" to true))

    private fun seed(rows: Int = 30) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    (1..rows).map {
                        ServerFixtures.libraryItem("01K%03d".format(it), title = "Caller %03d".format(it))
                    }
                )
            )
        )
        server.on("GET", "/api/activity", FakeServer.json(200, """{"data": [], "unread_count": 0}"""))
        runBlocking { app.graph.library.refresh() }
        // The setup card closed, as once setup is done.
        vm.updateSettings { it.copy(setupCardHidden = true) }
        pumpUntil("the card closed") { vm.settings.value.setupCardHidden }
        show { EchoV2Shell(vm, v2, darkTheme = false, classic = classic, onSwitchToClassic = {}, language = EchoLanguage.English) }
        pumpUntil("the list") { compose.onAllNodesWithText("Caller 001").fetchSemanticsNodes().isNotEmpty() }
    }

    private val kinds = hasAnyAncestor(hasTestTag(KIND_ROW_TAG))

    private fun chipsBottom(): Float? =
        compose.onAllNodes(hasTestTag(KIND_ROW_TAG)).fetchSemanticsNodes().firstOrNull()?.boundsInRoot?.bottom

    @Test
    fun `the header takes 13 percent of the screen or less at the default text size`() {
        seed()
        val screen = compose.onRoot().fetchSemanticsNode().size.height.toFloat()
        val bottom = checkNotNull(chipsBottom()) { "the chips' row" }
        assertTrue("the header is ${100 * bottom / screen} % of the screen", bottom <= 0.13f * screen)
        // Its two rows: the title row first, the chips under it.
        val title = compose.onNode(hasText("All recordings") and isHeading()).fetchSemanticsNode().boundsInRoot
        assertTrue(title.bottom <= bottom)
    }

    @Test
    fun `the chips hide while the list scrolls down and come back as it scrolls up`() {
        seed()
        compose.onNode(hasText("All · 30") and kinds).assertExists()
        val list = compose.onAllNodes(hasScrollToIndexAction()).onFirst()
        list.performTouchInput { swipeUp(startY = bottom - 10f, endY = top + 10f) }
        compose.waitForIdle()
        val hidden = chipsBottom()
        val title = compose.onNode(hasText("All recordings") and isHeading()).fetchSemanticsNode().boundsInRoot.bottom
        assertTrue("hidden under the title row: $hidden <= $title", hidden == null || hidden <= title + 1f)
        list.performTouchInput { swipeDown(startY = top + 10f, endY = top + (bottom - top) / 3f) }
        compose.waitForIdle()
        compose.onNode(hasText("All · 30") and kinds).assertExists()
        assertTrue("back under the title row", (chipsBottom() ?: 0f) > title + 1f)
    }

    @Test
    fun `no more button and no Calls chip, and the avatar's menu has Select, Sync now and Settings`() {
        seed()
        assertTrue("no ⋮", compose.onAllNodes(hasContentDescription("More")).fetchSemanticsNodes().isEmpty())
        assertTrue(
            "no Calls chip",
            compose.onAllNodesWithText("Calls: ", substring = true).fetchSemanticsNodes().isEmpty() &&
                compose.onAllNodesWithText("Not ready", substring = true).fetchSemanticsNodes().isEmpty()
        )
        // Review of UX-14: TalkBack hears that it opens a menu.
        compose.onNodeWithContentDescription("Account and settings")
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.Role, Role.DropdownList))
            .assert(SemanticsMatcher("opens its choices") { it.config[SemanticsActions.OnClick].label == "Show choices" })
        compose.onNodeWithContentDescription("Account and settings").performClick()
        listOf("Select", "Sync now", "Settings").forEach { compose.onNodeWithText(it).assertExists() }
        compose.onNodeWithText("Select").performClick()
        pumpUntil("selection mode") { compose.onAllNodesWithText("1 selected", substring = true).fetchSemanticsNodes().isNotEmpty() }
    }

    @Test
    fun `Sort and display is the last chip, and search opens over the page`() {
        seed()
        compose.onNode(hasText("Sort & display") and kinds and hasClickAction()).performClick()
        compose.waitForIdle()
        assertTrue(compose.onAllNodesWithText("Sort & display").fetchSemanticsNodes().size >= 2)
        androidx.test.espresso.Espresso.pressBack()
        compose.waitForIdle()

        compose.onNodeWithContentDescription("Search recordings").performClick()
        compose.waitForIdle()
        compose.onNode(hasSetTextAction() and hasText("Search in All recordings")).assertExists()
        assertTrue("the title row gives way", compose.onAllNodes(hasText("All recordings") and isHeading()).fetchSemanticsNodes().isEmpty())
        compose.onNodeWithContentDescription("Back").performClick()
        compose.waitForIdle()
        compose.onNode(hasText("All recordings") and isHeading()).assertExists()
        assertEquals(0, compose.onAllNodes(hasText("Search in All recordings")).fetchSemanticsNodes().size)
    }
}
