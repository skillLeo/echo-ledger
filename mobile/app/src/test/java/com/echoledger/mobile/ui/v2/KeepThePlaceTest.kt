package com.echoledger.mobile.ui.v2

import androidx.compose.material3.Text
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasScrollToIndexAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.StateRestorationTester
import androidx.compose.ui.test.onAllNodesWithContentDescription
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollToNode
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.library.ReturnedRow
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
 * v2.1 UX-25: back from a recording, the Library is where it was (the same view, tab, filters and search, and the same
 * scroll position), and the row come back to has a light tint and focus until another row is opened or tapped, the
 * view or filters change, or 60 s pass. A round ↑ "Back to top" shows once the list is more than a screen down,
 * fades at the top, and takes the list to the top. A rotation (state saved and restored) keeps the place.
 */
@Config(sdk = [34], qualifiers = "w412dp-h891dp-xxhdpi")
class KeepThePlaceTest : ScreenTest() {
    private val classic = ClassicScreens(
        home = { Text("Classic activity") },
        library = { Text("Classic library") },
        live = { Text("Classic live") },
        ask = { Text("Classic projects") },
        settings = { Text("Classic settings") },
        upgrade = {}
    )

    private val v2 = UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true, "ui_v2.live" to true, "ui_v2.ask" to true))

    /** 60 recordings, newest first, one a day: "Caller 001" … "Caller 060". */
    private fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        val today = java.time.OffsetDateTime.now().withHour(9).withMinute(0).withSecond(0).withNano(0)
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    (1..60).map {
                        ServerFixtures.libraryItem(
                            "01K%03d".format(it),
                            title = "Caller %03d".format(it),
                            startedAt = today.minusDays(it.toLong()).toString()
                        )
                    }
                )
            )
        )
        server.on("GET", "/api/activity", FakeServer.json(200, """{"data": [], "unread_count": 0}"""))
        runBlocking {
            app.graph.library.refresh()
            app.graph.settings.update { it.copy(setupCardHidden = true) }
        }
    }

    private val shell: @androidx.compose.runtime.Composable () -> Unit = {
        EchoV2Shell(vm, v2, darkTheme = false, classic = classic, onSwitchToClassic = {}, language = EchoLanguage.English)
    }

    private fun list() = compose.onAllNodes(hasScrollToIndexAction()).onFirst()

    private fun shown(title: String) = compose.onAllNodesWithText(title).fetchSemanticsNodes().isNotEmpty()

    /** The row come back to, by its title ("Caller 040"); null when none is marked. */
    private fun returned(): String? = compose.onAllNodes(SemanticsMatcher.keyIsDefined(ReturnedRow)).fetchSemanticsNodes().firstOrNull()
        ?.config?.getOrNull(SemanticsProperties.Text)?.map { it.text }?.firstOrNull { it.startsWith("Caller") }

    private fun openFromTheList(title: String) {
        list().performScrollToNode(hasText(title))
        compose.onAllNodesWithText(title).onFirst().performClick()
        pumpUntil("the recording page") { compose.onAllNodesWithContentDescription("Back").fetchSemanticsNodes().isNotEmpty() }
    }

    private fun back() {
        compose.onAllNodesWithContentDescription("Back").onFirst().performClick()
        pumpUntil("the Library") { compose.onAllNodesWithText("All recordings").fetchSemanticsNodes().isNotEmpty() }
    }

    @Test
    fun `back from a recording opened at row 40 the list is where it was, with that row lightly tinted`() {
        seed()
        show(shell)
        pumpUntil("the list") { shown("Caller 001") }
        openFromTheList("Caller 040")
        back()
        assertTrue("row 40 is on screen", shown("Caller 040"))
        assertTrue("the top is not", !shown("Caller 001"))
        assertEquals("Caller 040", returned())
        // Still there after 3 s.
        compose.mainClock.advanceTimeBy(3_000)
        compose.waitForIdle()
        assertEquals("Caller 040", returned())
        // Another row opened: the tint goes with it, to that row.
        openFromTheList("Caller 041")
        back()
        assertEquals("Caller 041", returned())
    }

    @Test
    fun `back to top shows past one screen, fades at the top, and takes the list there`() {
        seed()
        show(shell)
        pumpUntil("the list") { shown("Caller 001") }
        assertTrue("not at the top", compose.onAllNodes(hasContentDescription("Back to top")).fetchSemanticsNodes().isEmpty())
        list().performScrollToNode(hasText("Caller 045"))
        compose.waitForIdle()
        compose.onNodeWithContentDescription("Back to top").performClick()
        pumpUntil("at the top") { shown("Caller 001") }
        compose.waitForIdle()
        assertTrue("gone at the top", compose.onAllNodes(hasContentDescription("Back to top")).fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun `a rotation keeps the place`() {
        seed()
        val restoration = StateRestorationTester(compose)
        restoration.setContent { shell() }
        pumpUntil("the list") { shown("Caller 001") }
        list().performScrollToNode(hasText("Caller 035"))
        compose.waitForIdle()
        restoration.emulateSavedInstanceStateRestore()
        pumpUntil("the list again") { compose.onAllNodes(hasScrollToIndexAction()).fetchSemanticsNodes().isNotEmpty() }
        assertTrue("still far down", shown("Caller 035"))
        assertTrue(!shown("Caller 001"))
    }
}
