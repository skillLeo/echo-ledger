package com.echoledger.mobile.ui.v2

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isSelectable
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.PILLS_ROW_TAG
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import kotlinx.coroutines.runBlocking
import org.junit.Test

/**
 * Each of classic's Library filters has its home in the Filters sheet (parity.csv PH-LIB-015…021, 044; contract
 * 2.0.0-p): Device (Phone, Desktop, Web, several at once), Date (Today and Last 7 days with how many each holds; a date
 * and a mark narrow each other), Project (No project and every project), Language (the languages the recordings
 * have), Bookmarks (Any bookmark and each tag), Labels (only when there are labels), and the row's source mark with
 * its device badge.
 */
class V2LibraryFilterChipsParityTest : ScreenTest() {
    // Read once the test has set its clock and zone up (after @Before), never while the class is made.
    private val now: ZonedDateTime by lazy { ZonedDateTime.now() }

    /** A moment of today, never in the future: a minute after midnight, or now if that is sooner. */
    private val today: String by lazy {
        minOf(now, now.toLocalDate().atStartOfDay(now.zone).plusMinutes(1).plusSeconds(1)).format(DateTimeFormatter.ISO_OFFSET_DATE_TIME)
    }
    private val longAgo: String by lazy { now.minusDays(40).format(DateTimeFormatter.ISO_OFFSET_DATE_TIME) }

    private fun item(
        id: String,
        title: String,
        startedAt: String = longAgo,
        device: String = "phone",
        client: String? = null,
        clientId: String = "01JCLIENT0000000000000000A",
        extra: String = ""
    ): String = ServerFixtures.libraryItem(id, title = title, startedAt = startedAt, sourceDevice = device, client = client)
        .replace("01JCLIENT0000000000000000A", clientId)
        .let { if (extra.isEmpty()) it else it.replace("\"updated_at\"", "$extra,\"updated_at\"") }

    private fun seed(vararg rows: String) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(rows.toList())))
        server.on("GET", "/api/search.*", FakeServer.json(200, """{"data":[]}"""))
        runBlocking { app.graph.library.refresh() }
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                androidx.compose.runtime.CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2LibraryScreen(vm, initials = "AS", onOpen = {}, onTrash = {}, onAccount = {})
                }
            }
        }
        pumpUntil("the library") { compose.onAllNodesWithText("Rec ", substring = true).fetchSemanticsNodes().size == rows.size }
    }

    private fun shown(vararg titles: String) {
        val all = listOf("Rec A", "Rec B", "Rec C", "Rec D")
        pumpUntil("only ${titles.toList()}") {
            all.all { t -> compose.onAllNodesWithText(t, substring = true).fetchSemanticsNodes().isNotEmpty() == (t in titles) }
        }
    }

    private fun openFilters() {
        compose.onAllNodes(hasContentDescription("Filters", substring = true) and hasClickAction()).onFirst().performClick()
        compose.waitForIdle()
    }

    /** An option of the sheet (a box, or Date's round choice), brought into view. */
    private fun option(words: String) =
        compose.onAllNodes(hasText(words) and (isToggleable() or isSelectable())).onFirst().performScrollTo()

    /** Ticks [choices] in the sheet and shows the results. */
    private fun choose(vararg choices: String) {
        openFilters()
        choices.forEach { option(it).performClick() }
        compose.onAllNodes(hasText("Show ", substring = true) and hasClickAction()).onFirst().performClick()
        compose.waitForIdle()
    }

    private fun pill(words: String) = compose.onNode(hasText(words) and hasAnyAncestor(hasTestTag(PILLS_ROW_TAG)))

    @Test
    fun deviceTakesPhoneDesktopAndWebSeveralAtOnceAndSaysHowManyAreOn() {
        seed(item("01JA", "Rec A"), item("01JB", "Rec B", device = "desktop"), item("01JC", "Rec C", device = "web"))
        choose("Desktop", "Web")
        shown("Rec B", "Rec C")
        pill("Device: Desktop, Web").assertExists()
        compose.onNode(hasContentDescription("Filters, 1 on")).assertExists()
        choose("Web", "Phone")
        shown("Rec A", "Rec B")
        // The pill's own ✕ clears only Device.
        compose.onNode(hasContentDescription("Remove Device: Desktop, Phone")).performClick()
        shown("Rec A", "Rec B", "Rec C")
        compose.onAllNodes(hasAnyAncestor(hasTestTag(PILLS_ROW_TAG))).assertCountEquals(0)
    }

    @Test
    fun dateSaysHowManyEachDateHoldsAndADateAndAMarkNarrowEachOther() {
        seed(
            item("01JA", "Rec A", startedAt = today),
            item("01JB", "Rec B", startedAt = today, extra = "\"pinned\":true"),
            item("01JC", "Rec C", extra = "\"pinned\":true")
        )
        openFilters()
        // Today 2 and Last 7 days 2 (each count at its end); This month counts the day too.
        compose.onNodeWithText("Show 3 results").assertExists()
        option("Last 7 days").performClick()
        compose.onNodeWithText("Show 2 results").assertExists()
        // Pinned (Marked) and Last 7 days (Date): the pinned recording from this week only, not either one.
        option("Pinned").performClick()
        compose.onNodeWithText("Show 1 result").performClick()
        shown("Rec B")
        pill("Date: Last 7 days").assertExists()
        pill("Marked: Pinned").assertExists()
    }

    @Test
    fun projectOffersNoProjectAndEveryProject() {
        seed(
            item("01JA", "Rec A", client = "Acme Holdings"),
            item("01JB", "Rec B", client = "Zed Traders", clientId = "01JCLIENT0000000000000000Z"),
            item("01JC", "Rec C")
        )
        openFilters()
        listOf("No project", "Acme Holdings", "Zed Traders").forEach { option(it).assertExists() }
        option("No project").performClick()
        compose.onNodeWithText("Show 1 result").performClick()
        shown("Rec C")
        choose("Zed Traders")
        shown("Rec B", "Rec C")
        pill("Project: No project, Zed Traders").assertExists()
    }

    @Test
    fun languageListsTheLanguagesTheRecordingsHaveAndNarrowsToOne() {
        seed(
            item("01JA", "Rec A", extra = "\"facets\":{\"source\":\"whatsapp\",\"status\":\"transcribed\",\"language\":\"ur\"}"),
            item("01JB", "Rec B", extra = "\"facets\":{\"source\":\"whatsapp\",\"status\":\"transcribed\",\"language\":\"en\"}"),
            item("01JC", "Rec C", extra = "\"facets\":{\"source\":\"whatsapp\",\"status\":\"transcribed\",\"language\":\"en\"}")
        )
        openFilters()
        option("English").assertExists()
        compose.onAllNodes(hasText("Arabic") and isToggleable()).assertCountEquals(0)
        option("Urdu").performClick()
        compose.onNodeWithText("Show 1 result").performClick()
        shown("Rec A")
    }

    @Test
    fun bookmarksFindAnyBookmarkOrOneTag() {
        seed(
            item("01JA", "Rec A", extra = "\"marker_count\":2,\"tags\":[\"Price\"]"),
            item("01JB", "Rec B", extra = "\"marker_count\":1,\"tags\":[\"Follow up\"]"),
            item("01JC", "Rec C")
        )
        choose("Any bookmark")
        shown("Rec A", "Rec B")
        choose("Any bookmark", "Price")
        shown("Rec A")
    }

    @Test
    fun labelsAreOfferedOnlyWhenThereAreLabelsAndNarrowToThem() {
        seed(item("01JA", "Rec A"), item("01JB", "Rec B"))
        openFilters()
        compose.onAllNodesWithText("Labels").assertCountEquals(0)
        compose.onAllNodes(hasContentDescription("Close", substring = true) and hasClickAction()).onFirst().performClick()
        server.on(
            "GET",
            "/api/library",
            FakeServer.json(
                200,
                ServerFixtures.libraryPage(
                    listOf(item("01JA", "Rec A", extra = "\"labels\":[\"Urgent\"]"), item("01JB", "Rec B", extra = "\"labels\":[\"Paid\"]"))
                )
            )
        )
        runBlocking { app.graph.library.refresh() }
        pumpUntil("the labels") { vm.library.value.any { it.labels.isNotEmpty() } }
        choose("Paid")
        shown("Rec B")
        pill("Labels: Paid").assertExists()
    }

    @Test
    fun aRowsSourceMarkIsNamedWithTheDeviceForAComputerOrTheWeb() {
        seed(item("01JA", "Rec A"), item("01JB", "Rec B", device = "desktop"), item("01JC", "Rec C", device = "web"))
        // A phone's recording is just its app; a computer's or the web's names where it was made (the badge left the
        // row in v2.1 UX-16; TalkBack still hears it).
        compose.onAllNodes(hasContentDescription("WhatsApp"), useUnmergedTree = true).assertCountEquals(1)
        compose.onAllNodes(hasContentDescription("WhatsApp, Desktop"), useUnmergedTree = true).assertCountEquals(1)
        compose.onAllNodes(hasContentDescription("WhatsApp, Web"), useUnmergedTree = true).assertCountEquals(1)
    }
}
