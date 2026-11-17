package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.ui.v2.help.FixtureHelpSource
import com.echoledger.mobile.ui.v2.help.HelpResult
import com.echoledger.mobile.ui.v2.help.Markdown
import com.echoledger.mobile.ui.v2.help.MdBlock
import com.echoledger.mobile.ui.v2.help.MdSpan
import com.echoledger.mobile.ui.v2.help.V2HelpScreen
import com.echoledger.mobile.ui.v2.help.dayAndTime
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import java.time.ZoneId
import java.util.Locale
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/** Help v2 (contract 2.0.0-d): the bounded Markdown reader, guides, what's new and contact support. */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class HelpTest {
    @get:Rule
    val compose = createComposeRule()

    private var language by mutableStateOf(EchoLanguage.English)
    private var dark by mutableStateOf(false)

    private fun show(source: FixtureHelpSource, tab: Int = 0, onPhoneCheck: () -> Unit = {}) = compose.setContent {
        EchoV2Theme(darkTheme = dark, language = language) {
            V2HelpScreen(source, onPhoneCheck = onPhoneCheck, onBack = {}, startTab = tab)
        }
    }

    private fun press(text: String) {
        compose.onAllNodes(hasText(text) and hasClickAction()).onFirst().performScrollTo().performClick()
        compose.waitForIdle()
    }

    @Test
    fun eachBlockTakesItsDirectionFromItsFirstStrongLetter() {
        val ltr = androidx.compose.ui.unit.LayoutDirection.Ltr
        val rtl = androidx.compose.ui.unit.LayoutDirection.Rtl
        // The user guide's section 12 (web/resources/help/user-guide.md): an English item quoting Urdu stays left to right.
        val quoting = "**Urdu** always allows English words inside, the way people speak in Pakistan (\"کل کی meeting reschedule کر دیں\")."
        assertEquals(ltr, Markdown.direction(MdBlock.ListBlock(false, listOf(quoting, "Arabic does too."))))
        assertEquals(ltr, Markdown.firstStrong(quoting))
        // Each item its own: an Urdu item in an English list is right to left.
        assertEquals(rtl, Markdown.firstStrong("کل کی میٹنگ"))
        // A table goes by its header row: an English table with one Urdu cell stays left to right.
        assertEquals(ltr, Markdown.direction(MdBlock.Table(listOf("Button", "What it does"), listOf(listOf("Keep", "محفوظ کرتا ہے")))))
        assertEquals(rtl, Markdown.direction(MdBlock.Table(listOf("بٹن", "Button"), emptyList())))
        // Marks, quotes, digits and punctuation are passed over.
        assertEquals(rtl, Markdown.direction(MdBlock.Paragraph("**\"3.** میٹنگ کل ساڑھے دس بجے ہے۔")))
        assertEquals(ltr, Markdown.direction(MdBlock.Paragraph("`10` «Keep» کے بعد")))
        // The first letter decides, whatever follows.
        assertEquals(ltr, Markdown.direction(MdBlock.Heading(2, "After a call: کال کے بعد")))
        assertEquals(ltr, Markdown.direction(MdBlock.Paragraph("Meeting کل ساڑھے دس بجے ہے۔")))
        assertEquals(rtl, Markdown.direction(MdBlock.Paragraph("کال کے بعد: After a call")))
        // No letter: the screen's direction; code is always left to right.
        assertEquals(null, Markdown.direction(MdBlock.Paragraph("1. — 2.")))
        assertEquals(ltr, Markdown.direction(MdBlock.Code("کوڈ")))
    }

    @Test
    fun theReaderMakesBlocksAndKeepsALinksWordsOnly() {
        val blocks = Markdown.blocks(
            "# Title\n\nSome **bold** and `code`.\n\n- one\n- two\n  more\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\n> quote\n\n```\nx = 1\n```"
        )
        assertEquals(
            listOf(
                MdBlock.Heading(1, "Title"),
                MdBlock.Paragraph("Some **bold** and `code`."),
                MdBlock.ListBlock(false, listOf("one", "two more")),
                MdBlock.Table(listOf("A", "B"), listOf(listOf("1", "2"))),
                MdBlock.Quote("quote"),
                MdBlock.Code("x = 1")
            ),
            blocks
        )
        assertEquals(listOf(MdSpan("See "), MdSpan("the guide"), MdSpan(".")), Markdown.inline("See [the guide](https://x.example/a)."))
        assertEquals(listOf(MdSpan("a "), MdSpan("b", bold = true), MdSpan(" "), MdSpan("c", code = true)), Markdown.inline("a **b** `c`"))
        // HTML is text.
        assertEquals(listOf(MdBlock.Paragraph("<script>alert(1)</script>")), Markdown.blocks("<script>alert(1)</script>"))
    }

    @Test
    fun theReaderIsBounded() {
        // Lines, blocks and characters.
        assertEquals(Markdown.MAX_LINES, Markdown.blocks("# h\n".repeat(10_000)).size)
        assertTrue(Markdown.MAX_BLOCKS <= Markdown.MAX_LINES)
        assertTrue(Markdown.blocks("a".repeat(1_000_000)).single().let { it is MdBlock.Paragraph && it.text.length == Markdown.MAX_CHARS })
        // Table cells and rows.
        val wide = "|" + (1..50).joinToString("|") { "c$it" } + "|\n|---|---|\n" + (1..500).joinToString("\n") { "| r$it | x |" }
        val table = Markdown.blocks(wide).single() as MdBlock.Table
        assertEquals(Markdown.MAX_CELLS, table.head.size)
        assertEquals(Markdown.MAX_ROWS, table.rows.size)
        // Marks only on lines up to 2,000 characters; longer ones stay as written.
        val long = "**x** " + "y".repeat(Markdown.MAX_INLINE)
        assertEquals(listOf(MdSpan(long)), Markdown.inline(long))
        // Every step advances, also on lines that look like the start of something and end nowhere.
        val odd = listOf("```", "| a |", "  - x", "#", ">", "1)", "[x](", "**", "|---|").joinToString("\n").repeat(2000)
        val start = System.nanoTime()
        Markdown.blocks(odd)
        Markdown.inline("**[`".repeat(500))
        assertTrue((System.nanoTime() - start) / 1_000_000 < 5_000)
    }

    @Test
    fun nothingInAGuideCanBeTapped() {
        show(FixtureHelpSource())
        press("After a call")
        compose.onNodeWithText("Read more in the full guide.").assertExists()
        val tappable = compose.onAllNodes(hasClickAction()).fetchSemanticsNodes().map {
            it.config.getOrNull(SemanticsProperties.Text)?.joinToString()
                ?: it.config.getOrNull(SemanticsProperties.ContentDescription)?.joinToString()
        }
        // Only Back: no link, no text.
        assertEquals(listOf("Back"), tappable)
        compose.onAllNodesWithText("the full guide", substring = true).fetchSemanticsNodes().forEach {
            assertTrue(it.config.getOrNull(SemanticsActions.OnClick) == null)
        }
    }

    @Test
    fun guidesSearchAndOpen() {
        val source = FixtureHelpSource()
        show(source)
        compose.onNodeWithText("Recording WhatsApp calls").assertExists()
        compose.onNode(hasSetTextAction()).performTextInput("urdu")
        compose.mainClock.advanceTimeBy(1_000)
        compose.waitForIdle()
        compose.onNodeWithText("Urdu and Roman Urdu transcripts").assertExists()
        compose.onNodeWithText("Recording WhatsApp calls").assertDoesNotExist()
        assertEquals("urdu", source.searched.last())
        compose.onNode(hasSetTextAction()).performTextInput("zzz")
        compose.mainClock.advanceTimeBy(1_000)
        compose.waitForIdle()
        compose.onNodeWithText("No guide matches").assertExists()
    }

    @Test
    fun thePhoneCheckOpensFromGuides() {
        var opened = 0
        show(FixtureHelpSource(), onPhoneCheck = { opened++ })
        press("Phone check")
        assertEquals(1, opened)
    }

    @Test
    fun failuresSayWhyInPlainWords() {
        show(FixtureHelpSource(failing = HelpResult.Failed(offline = true, words = null)))
        compose.onNodeWithText("The guides didn't load").assertExists()
        compose.onNodeWithText("You're offline. Check the internet connection and try again.").assertExists()
    }

    @Test
    fun contactSupportSaysThereIsNoReportAndOffersNone() {
        val source = FixtureHelpSource(lastReport = null)
        show(source, tab = 2)
        compose.onNodeWithText("No report from this phone yet", substring = true).assertExists()
        compose.onNode(hasText("Attach this phone's last report", substring = true)).assertIsNotEnabled()
        compose.onNodeWithText("Subject").performTextInput("Calls not recorded")
        compose.onNodeWithText("Message").performTextInput("Since the update my WhatsApp calls are one-sided.")
        press("Send")
        compose.onNodeWithText("Message sent").assertExists()
        assertEquals(listOf(Triple("Calls not recorded", "Since the update my WhatsApp calls are one-sided.", false)), source.sent)
    }

    @Test
    fun contactSupportSaysWhichReportGoesWithIt() {
        val source = FixtureHelpSource(lastReport = "2026-11-17T05:42:00+00:00")
        val day = dayAndTime("2026-11-17T05:42:00+00:00", Locale.UK, ZoneId.systemDefault())!!
        show(source, tab = 2)
        compose.onNodeWithText("Your last report from $day", substring = true).assertExists()
        press("Attach this phone's last report")
        compose.onNodeWithText("Subject").performTextInput("Help")
        compose.onNodeWithText("Message").performTextInput("Please look at my report.")
        press("Send")
        assertEquals(true, source.sent.single().third)
        assertFalse(compose.onAllNodesWithText("Send").fetchSemanticsNodes().isNotEmpty())
    }

    @Test
    fun screenshots() {
        val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone/app")
        var tab by mutableStateOf(0)
        val source = FixtureHelpSource(lastReport = "2026-11-17T05:42:00+00:00")
        compose.setContent {
            EchoV2Theme(darkTheme = dark, language = language) {
                androidx.compose.runtime.key(tab) {
                    V2HelpScreen(source, onPhoneCheck = {}, onBack = {}, startTab = tab)
                }
            }
        }
        for ((name, t) in listOf("help-guides" to 0, "help-new" to 1, "help-contact" to 2, "help-guide" to 0)) {
            for ((l, d) in listOf(EchoLanguage.English to false, EchoLanguage.Urdu to false, EchoLanguage.English to true)) {
                language = l
                dark = d
                tab = t
                compose.waitForIdle()
                if (name == "help-guide") press("After a call")
                compose.waitForIdle()
                val file = "$name-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}.png"
                compose.onRoot().captureRoboImage(File(out, file))
                if (name == "help-guide") {
                    androidx.test.espresso.Espresso.pressBack()
                    compose.waitForIdle()
                }
            }
        }
    }
}
