package com.echoledger.mobile.ui.v2

import androidx.compose.material3.Text
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.captureToImage
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
import org.robolectric.annotation.GraphicsMode

/**
 * v2.1 UX-25: back from a recording, the Library is where it was (the same view, tab, filters and search, and the same
 * scroll position), and the row come back to has a light tint and focus until another row is opened or tapped, the
 * view or filters change, or 60 s pass. A round ↑ "Back to top" shows once the list is more than a screen down,
 * fades at the top, and takes the list to the top. A rotation (state saved and restored) keeps the place.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
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

    private val today = java.time.OffsetDateTime.now().withHour(9).withMinute(0).withSecond(0).withNano(0)

    /** "Caller 001" … "Caller 060" as the server lists them, newest first, one a day ([from] 0: one from today too). */
    private fun serve(from: Int = 1) = server.on(
        "GET",
        "/api/library",
        FakeServer.json(
            200,
            ServerFixtures.libraryPage(
                (from..60).map {
                    ServerFixtures.libraryItem(
                        "01K%03d".format(it),
                        title = "Caller %03d".format(it),
                        startedAt = today.minusDays(it.toLong()).toString()
                    )
                }
            )
        )
    )

    /** 60 recordings, newest first, one a day: "Caller 001" … "Caller 060". */
    private fun seed() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        serve()
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

    /** Where the row [title] is in the list (from the list's top: the chips' row above may be hidden or shown). */
    private fun top(title: String) =
        compose.onAllNodesWithText(title).onFirst().fetchSemanticsNode().boundsInRoot.top - list().fetchSemanticsNode().boundsInRoot.top

    /** The come-back row's own colour, drawn: a point in its padding (nothing else drawn there). */
    private fun returnedColour(): Int {
        val row = compose.onNode(SemanticsMatcher.keyIsDefined(ReturnedRow)).captureToImage().asAndroidBitmap()
        return row.getPixel(4, 4)
    }

    /** Owner's review of UX-25: a neutral grey as the desktop's (the text colour over the page), never teal. */
    @Test
    fun `the row come back to is a light neutral grey, not the accent or the selection's tint`() {
        seed()
        show(shell)
        pumpUntil("the list") { shown("Caller 001") }
        openFromTheList("Caller 040")
        back()
        val drawn = returnedColour()
        val c = com.echoledger.mobile.ui.v2.theme.EchoV2Colors.Light
        val expected = androidx.compose.ui.graphics.Color(
            androidx.core.graphics.ColorUtils.compositeColors(
                com.echoledger.mobile.ui.v2.library.returnedTint(c).toArgb(),
                c.bgCanvas.toArgb()
            )
        ).toArgb()
        fun near(a: Int, b: Int) = listOf(16, 8, 0).all { shift -> kotlin.math.abs((a shr shift and 0xff) - (b shr shift and 0xff)) <= 3 }
        assertTrue("the text colour at 6 % over the page: ${Integer.toHexString(drawn)}", near(drawn, expected))
        assertTrue("never the selection's tint", !near(drawn, c.accentSoft.toArgb()))
        val accented = androidx.core.graphics.ColorUtils.compositeColors(c.accent.copy(alpha = 0.08f).toArgb(), c.bgCanvas.toArgb())
        assertTrue("never the accent", !near(drawn, accented) && !near(drawn, c.accent.toArgb()))
        assertTrue("seen against the page", !near(drawn, c.bgCanvas.toArgb()))
    }

    /** Review of UX-25: a recording that came in above while one was open never moves the row come back to. */
    @Test
    fun `a recording that came in while one was open leaves the row come back to where it was`() {
        seed()
        show(shell)
        pumpUntil("the list") { shown("Caller 001") }
        list().performScrollToNode(hasText("Caller 040"))
        compose.waitForIdle()
        val before = top("Caller 040")
        openFromTheList("Caller 040")
        // One more today, above them all, while the recording is open.
        serve(from = 0)
        runBlocking { app.graph.library.refresh() }
        pumpUntil("synced") { vm.library.value.any { it.title == "Caller 000" } }
        back()
        pumpUntil("row 40 where it was ($before)") { shown("Caller 040") && kotlin.math.abs(top("Caller 040") - before) <= 1f }
        assertEquals("Caller 040", returned())
    }

    /** Review of UX-25: a rotation right after coming back keeps the tint (and the time it has left). */
    @Test
    fun `a rotation right after coming back keeps the row's tint`() {
        seed()
        val restoration = StateRestorationTester(compose)
        restoration.setContent { shell() }
        pumpUntil("the list") { shown("Caller 001") }
        openFromTheList("Caller 040")
        back()
        assertEquals("Caller 040", returned())
        restoration.emulateSavedInstanceStateRestore()
        pumpUntil("the list again") { shown("Caller 040") }
        assertEquals("Caller 040", returned())
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
