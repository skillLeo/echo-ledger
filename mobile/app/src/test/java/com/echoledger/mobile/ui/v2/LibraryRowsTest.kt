package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.semantics.SemanticsNode
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.core.LibraryViewChoice
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.ROW_AVATAR_TAG
import com.echoledger.mobile.ui.v2.components.RowAvatarLook
import com.echoledger.mobile.ui.v2.components.RowAvatarLookKey
import com.echoledger.mobile.ui.v2.components.contactAvatarIndex
import com.echoledger.mobile.ui.v2.components.contactKey
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2LibraryScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * v2.1 UX-16, the phone's Library rows: 72 dp tall, a 40 dp avatar (the person's initials on their colour; a voice
 * note's mic; a meeting's camera; the person icon when there is no name of a person) with the source app's 16 dp badge
 * (none on a voice note), heard as the source's name only; line 1 the name and the time, line 2 the snippet or state and
 * at most two small things, one mark and the length.
 */
class LibraryRowsTest : ScreenTest() {
    private fun item(id: String, title: String?, app: String = "whatsapp", extra: String = "") =
        ServerFixtures.libraryItem(id, title = title, sourceApp = app)
            .let { if (extra.isEmpty()) it else it.replace("\"updated_at\"", "$extra,\"updated_at\"") }

    private fun seed(vararg rows: String, language: EchoLanguage = EchoLanguage.English, groupNone: Boolean = false) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(rows.toList())))
        runBlocking {
            app.graph.library.refresh()
            if (groupNone) app.graph.settings.update { it.copy(libraryViews = mapOf("all" to LibraryViewChoice(group = "none"))) }
        }
        show {
            EchoV2Theme(darkTheme = false, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    V2LibraryScreen(vm, initials = "AS", onOpen = {}, onTrash = {}, onAccount = {})
                }
            }
        }
        pumpUntil("the rows") {
            compose.onAllNodes(hasTestTag(ROW_AVATAR_TAG), useUnmergedTree = true).fetchSemanticsNodes().size >= rows.size
        }
    }

    private fun row(title: String) = hasClickAction() and hasText(title, substring = true)

    /** The avatar drawn inside the row [title] (a row is one merged node, so it is found by place). */
    private fun avatarOf(title: String): SemanticsNode {
        val bounds = compose.onNode(row(title)).fetchSemanticsNode().boundsInRoot
        return compose.onAllNodes(hasTestTag(ROW_AVATAR_TAG), useUnmergedTree = true).fetchSemanticsNodes()
            .single { it.boundsInRoot.center.y in bounds.top..bounds.bottom }
    }

    private fun lookOf(title: String): RowAvatarLook = avatarOf(title).config[RowAvatarLookKey]

    @Test
    fun aRowIs72dpWithA40dpAvatarOfInitialsAndA16dpSourceBadge() {
        seed(item("01JA", "Bilal Traders"))
        val px = compose.density.density
        val height = compose.onNode(row("Bilal Traders")).fetchSemanticsNode().size.height
        assertTrue("72 dp ($height px)", height >= (72.dp.value * px).toInt())
        assertEquals(40.dp.value * px, avatarOf("Bilal Traders").size.width.toFloat(), 1f)
        assertEquals(RowAvatarLook(initials = "BT", icon = null, badge = true), lookOf("Bilal Traders"))
        assertEquals(16.dp, EchoTokens.Component.avatarBadge)
    }

    @Test
    fun aVoiceNoteShowsAMicWithoutABadgeAMeetingItsCameraAndANumberThePersonIcon() {
        seed(item("01JA", "Shopping list", app = "voice_note"), item("01JB", "+92 300 1234567"), item("01JC", "Weekly sync", app = "zoom"))
        assertEquals(RowAvatarLook(initials = null, icon = "mic", badge = false), lookOf("Shopping list"))
        assertEquals(RowAvatarLook(initials = null, icon = "person", badge = true), lookOf("+92 300 1234567"))
        assertEquals(RowAvatarLook(initials = null, icon = "meeting", badge = true), lookOf("Weekly sync"))
    }

    /** Review of UX-16: never the source's name an untitled row shows, nor a title the server wrote, in either language. */
    private fun untitledAndServerTitledShowThePersonIcon(language: EchoLanguage) {
        seed(item("01JA", null), item("01JB", "Discussed the invoice for June", extra = "\"title_auto\":true"), language = language)
        val looks = compose.onAllNodes(hasTestTag(ROW_AVATAR_TAG), useUnmergedTree = true).fetchSemanticsNodes()
            .map { it.config[RowAvatarLookKey] }
        assertEquals("$language", listOf(RowAvatarLook(null, "person", true), RowAvatarLook(null, "person", true)), looks)
    }

    @Test
    fun anUntitledRowOrAServerTitleShowsThePersonIconInEnglish() = untitledAndServerTitledShowThePersonIcon(EchoLanguage.English)

    @Test
    fun anUntitledRowOrAServerTitleShowsThePersonIconInUrdu() = untitledAndServerTitledShowThePersonIcon(EchoLanguage.Urdu)

    /** Review of UX-16: TalkBack hears the source's name for the avatar, never its initials. */
    @Test
    fun theAvatarIsHeardAsTheSourceNotItsInitials() {
        seed(item("01JA", "Bilal Traders"))
        assertTrue("no BT in the row", !hasText("BT").matches(compose.onNode(row("Bilal Traders")).fetchSemanticsNode()))
        assertTrue(hasContentDescription("WhatsApp").matches(avatarOf("Bilal Traders")))
    }

    /**
     * A contact's colour is the same on every screen and device: FNV-1a (32-bit) over the UTF-8 key, its low 3 bits. The
     * values below are the ones parity.csv PH-LIB-044 gives the desktop to match.
     */
    @Test
    fun aContactsAvatarColourIsAStableHashOfTheContact() {
        val ref = mapOf("bilal traders" to 0, "rehman textiles" to 6, "karachi cargo" to 2, "weekly sync" to 1, "علی رضا" to 0)
        ref.forEach { (key, index) -> assertEquals(key, index, contactAvatarIndex(key)) }
        // The key: the name only, trimmed, its spaces collapsed, in lower case.
        assertEquals("bilal traders", contactKey("  Bilal   Traders "))
        assertEquals(contactAvatarIndex(contactKey("Bilal Traders")), contactAvatarIndex(contactKey("bilal  traders")))
    }

    /** Seen in UX-16's Urdu shots: "BT" was drawn in the Urdu font's Latin letters. */
    @Test
    fun latinInitialsKeepTheLatinFaceOnTheUrduScreens() {
        var latin: androidx.compose.ui.text.font.FontFamily? = null
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.Urdu) {
                latin = com.echoledger.mobile.ui.v2.theme.EchoV2.type.latin
                com.echoledger.mobile.ui.v2.components.EchoAvatar(initials = "BT")
            }
        }
        val layouts = mutableListOf<androidx.compose.ui.text.TextLayoutResult>()
        compose.onNode(hasText("BT"), useUnmergedTree = true).fetchSemanticsNode()
            .config[androidx.compose.ui.semantics.SemanticsActions.GetTextLayoutResult].action?.invoke(layouts)
        assertEquals(latin, layouts.single().layoutInput.style.fontFamily)
    }

    @Test
    fun lineTwoHasAtMostTwoSmallThingsOneMarkAndTheLength() {
        val marks = "\"important\":true,\"pinned\":true,\"labels\":[\"Clients\",\"Home\"],\"screen_count\":3"
        seed(item("01JA", "Bilal Traders", extra = marks))
        // Important wins, named with the row's lists; no pin, no list dot, no screens count beside it; the length is there.
        val all = { words: String -> compose.onAllNodes(hasContentDescription(words), useUnmergedTree = true).fetchSemanticsNodes() }
        assertEquals(1, all("Important, Clients, Home").size)
        listOf("Clients, Home", "Pinned", "3 screens").forEach { assertTrue(it, all(it).isEmpty()) }
        assertTrue(compose.onAllNodesWithText("42:10", substring = true, useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty())
    }

    /** Review of UX-16: away from the Pinned header (Group by None, App…), a pinned row carries its pin. */
    @Test
    fun underGroupByNoneAPinnedRowCarriesItsPin() {
        seed(item("01JA", "Bilal Traders", extra = "\"pinned\":true"), item("01JB", "Rehman Textiles"), groupNone = true)
        assertEquals(1, compose.onAllNodes(hasContentDescription("Pinned"), useUnmergedTree = true).fetchSemanticsNodes().size)
    }
}
