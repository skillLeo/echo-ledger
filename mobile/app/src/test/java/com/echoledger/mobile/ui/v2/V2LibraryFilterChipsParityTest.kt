package com.echoledger.mobile.ui.v2

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performSemanticsAction
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import kotlinx.coroutines.runBlocking
import org.junit.Test

/**
 * Each of Library's filter chips does what classic's does (parity.csv PH-LIB-015…021, 044): Source (Phone, Desktop, Web,
 * several at once, with a count), Date (Today and This week with how many each holds; a date and a mark narrow each
 * other), Project (No project and every project), Language (the languages the recordings have), Bookmark (Any bookmark
 * and each tag), Label (only when there are labels), and the row's source mark with its device badge.
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

    private fun choose(chip: String, vararg choices: String) {
        // "Source ▾", or "Source · 2 ▾" once some are on; the chip's own action, as a touch on a narrow applied chip
        // may land on its ✕.
        compose.onNode(
            androidx.compose.ui.test.SemanticsMatcher("the $chip chip") { node ->
                node.config.getOrElseNullable(androidx.compose.ui.semantics.SemanticsProperties.Text) { null }.orEmpty()
                    .any { it.text.startsWith(chip) && it.text.endsWith("▾") }
            }
        ).performSemanticsAction(androidx.compose.ui.semantics.SemanticsActions.OnClick)
        compose.waitForIdle()
        choices.forEach { compose.onNode(hasText(it) and isToggleable()).performClick() }
        compose.onNodeWithText("Apply").performClick()
        compose.waitForIdle()
    }

    @Test
    fun sourceTakesPhoneDesktopAndWebSeveralAtOnceAndSaysHowManyAreOn() {
        seed(item("01JA", "Rec A"), item("01JB", "Rec B", device = "desktop"), item("01JC", "Rec C", device = "web"))
        choose("Source", "Desktop", "Web")
        shown("Rec B", "Rec C")
        compose.onNodeWithText("Source · 2 ▾").assertExists()
        choose("Source", "Web", "Phone")
        shown("Rec A", "Rec B")
        // The chip's own ✕ clears only Source.
        compose.onNodeWithContentDescription("Clear Source", substring = true).performClick()
        shown("Rec A", "Rec B", "Rec C")
        compose.onNodeWithText("Source ▾").assertExists()
    }

    @Test
    fun dateSaysHowManyEachDateHoldsAndATickedDateAndAMarkNarrowEachOther() {
        seed(
            item("01JA", "Rec A", startedAt = today),
            item("01JB", "Rec B", startedAt = today, extra = "\"pinned\":true"),
            item("01JC", "Rec C", extra = "\"pinned\":true")
        )
        compose.onNodeWithText("Date ▾").performClick()
        compose.onNode(hasText("Today (2)") and isToggleable()).assertExists()
        compose.onNode(hasText("This week (2)") and isToggleable()).performClick()
        compose.onNodeWithText("Apply").performClick()
        shown("Rec A", "Rec B")
        compose.onNodeWithText("Date · 1 ▾").assertExists()
        // Pinned (a mark) and This week (a date): the pinned recording from this week only, not either one.
        choose("Views", "Pinned (2)")
        shown("Rec B")
    }

    @Test
    fun projectOffersNoProjectAndEveryProject() {
        seed(
            item("01JA", "Rec A", client = "Acme Holdings"),
            item("01JB", "Rec B", client = "Zed Traders", clientId = "01JCLIENT0000000000000000Z"),
            item("01JC", "Rec C")
        )
        compose.onNodeWithText("Project ▾").performClick()
        listOf("No project", "Acme Holdings", "Zed Traders").forEach { compose.onNode(hasText(it) and isToggleable()).assertExists() }
        compose.onNode(hasText("No project") and isToggleable()).performClick()
        compose.onNodeWithText("Apply").performClick()
        shown("Rec C")
        choose("Project", "Zed Traders")
        shown("Rec B", "Rec C")
        compose.onNodeWithText("Project · 2 ▾").assertExists()
    }

    @Test
    fun languageListsTheLanguagesTheRecordingsHaveAndNarrowsToOne() {
        seed(
            item("01JA", "Rec A", extra = "\"language_chip\":\"UR+EN\""),
            item("01JB", "Rec B", extra = "\"language_chip\":\"EN\""),
            item("01JC", "Rec C")
        )
        compose.onNodeWithText("Language ▾").performClick()
        compose.onAllNodes(isToggleable()).assertCountEquals(2)
        compose.onNode(hasText("EN") and isToggleable()).assertExists()
        compose.onNode(hasText("UR+EN") and isToggleable()).performClick()
        compose.onNodeWithText("Apply").performClick()
        shown("Rec A")
    }

    @Test
    fun bookmarkFindsAnyBookmarkOrOneTag() {
        seed(
            item("01JA", "Rec A", extra = "\"marker_count\":2,\"tags\":[\"Price\"]"),
            item("01JB", "Rec B", extra = "\"marker_count\":1,\"tags\":[\"Follow up\"]"),
            item("01JC", "Rec C")
        )
        choose("Bookmark", "Any bookmark")
        shown("Rec A", "Rec B")
        choose("Bookmark", "Any bookmark", "Price")
        shown("Rec A")
    }

    @Test
    fun labelIsOfferedOnlyWhenThereAreLabelsAndNarrowsToThem() {
        seed(item("01JA", "Rec A"), item("01JB", "Rec B"))
        compose.onAllNodesWithText("Label ▾").assertCountEquals(0)
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
        pumpUntil("the Label chip") { compose.onAllNodesWithText("Label ▾").fetchSemanticsNodes().isNotEmpty() }
        choose("Label", "Paid")
        shown("Rec B")
    }

    @Test
    fun aRowsSourceMarkCarriesTheDeviceBadgeForAComputerOrTheWeb() {
        seed(item("01JA", "Rec A"), item("01JB", "Rec B", device = "desktop"), item("01JC", "Rec C", device = "web"))
        // A phone's recording is just its app; a computer's or the web's names where it was made, as classic's badge.
        compose.onAllNodes(hasContentDescription("WhatsApp"), useUnmergedTree = true).assertCountEquals(1)
        compose.onAllNodes(hasContentDescription("WhatsApp, Desktop"), useUnmergedTree = true).assertCountEquals(1)
        compose.onAllNodes(hasContentDescription("WhatsApp, Web"), useUnmergedTree = true).assertCountEquals(1)
    }
}
