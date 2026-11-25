package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.getUnclippedBoundsInRoot
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.translate.BoxStyle
import com.echoledger.mobile.translate.SubtitleStyle
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * P-119: Game and Watch translate's Try it preview on a 360 dp phone at large text. A sample is shown whole, the frame
 * growing with it; Try it's growing line shows its newest lines, as the overlay does (TranslateTextView: older lines
 * move up out of its window), its end always in view. Never cut by a line limit (the sweep's rule, TextCuts, unchanged).
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h800dp")
class TranslatePreviewTest {
    @get:Rule
    val compose = createComposeRule()

    private val long = "Come on guys, the enemy is at the red house, go round the back and wait there for my signal"
    private val longUrdu = "چلو دوستو، دشمن سرخ گھر کے پاس ہے، پیچھے سے گھوم کر جاؤ اور میرے اشارے کا انتظار کرو"

    private var shownScale by mutableFloatStateOf(1.3f)
    private var shownWidth by mutableStateOf(360.dp)
    private var shownContent by mutableStateOf<(@Composable () -> Unit)?>(null)
    private var started = false

    /**
     * Shows [content] at the phone's text [fontScale]: 1.3 ("large"), or 1.69 with Largest in Settings › Appearance on
     * top. One content per test, changed in place.
     */
    private fun show(fontScale: Float = 1.3f, width: Dp = 360.dp, content: @Composable () -> Unit) {
        shownScale = fontScale
        shownWidth = width
        shownContent = content
        if (!started) {
            started = true
            compose.setContent {
                val density = LocalDensity.current
                CompositionLocalProvider(LocalDensity provides Density(density.density, shownScale)) {
                    EchoLedgerTheme("light") { Box(Modifier.width(shownWidth).padding(16.dp)) { shownContent?.invoke() } }
                }
            }
        }
        compose.waitForIdle()
    }

    private fun layoutOf(line: String): TextLayoutResult {
        val layout = mutableListOf<TextLayoutResult>()
        compose.onNodeWithText(line, useUnmergedTree = true).performSemanticsAction(SemanticsActions.GetTextLayoutResult) { it(layout) }
        return layout.first()
    }

    /** The line's box on screen (clipped by its preview). */
    private fun shown(line: String) = compose.onNodeWithText(line, useUnmergedTree = true).fetchSemanticsNode().boundsInRoot

    private fun assertInsideFrame(line: String) {
        val frame = compose.onNodeWithContentDescription("Preview over a sample game scene").getUnclippedBoundsInRoot()
        val text = compose.onNodeWithText(line, useUnmergedTree = true).getUnclippedBoundsInRoot()
        assertTrue("the line ends at ${text.bottom}, the frame at ${frame.bottom}", text.bottom <= frame.bottom)
    }

    /** All of it shows: the height on screen is the text's own. */
    private fun assertWhole(line: String) {
        val layout = layoutOf(line)
        assertNull(TextCuts.problem(layout))
        assertEquals("all its ${layout.lineCount} lines show", layout.size.height.toFloat(), shown(line).height, 1.5f)
        assertInsideFrame(line)
    }

    /**
     * Only its newest [lines] lines show: the text from where they start to its end, whole, and nothing of the lines
     * before it (not even the tips of a hidden line's letters).
     */
    private fun assertNewest(line: String, lines: Int) {
        val tail = SemanticsMatcher("the end of the line") { node ->
            node.config.getOrNull(SemanticsProperties.Text)?.joinToString("")?.trimStart('\u061C', '\u200E')
                ?.let { it.isNotEmpty() && line.endsWith(it) } == true
        }
        val shownNode = compose.onNode(tail, useUnmergedTree = true)
        val shownWithMark = shownNode.fetchSemanticsNode().config[SemanticsProperties.Text].joinToString("")
        val shownText = shownWithMark.trimStart('\u061C', '\u200E')
        assertTrue("an older line is shown too: \"$shownText\"", shownText.length < line.length)
        assertTrue("it starts a word: \"$shownText\"", line[line.length - shownText.length - 1].isWhitespace())
        val layout = layoutOf(shownWithMark)
        assertNull(TextCuts.problem(layout))
        assertEquals("its newest $lines lines", lines, layout.lineCount)
        assertEquals("shown whole", layout.size.height.toFloat(), shown(shownWithMark).height, 1.5f)
        assertInsideFrame(shownWithMark)
    }

    private fun shoot(name: String) {
        val out = File(System.getProperty("screenshots.dir") ?: "build/screenshots")
        compose.onRoot().captureRoboImage(File(out, "phone-try-it-$name.png"))
    }

    @Test
    fun `the game box's sample is shown whole even when it needs more lines than the box keeps`() {
        val sample = "Come on guys, the enemy is at the red house"
        show(width = 300.dp) { BoxPreview(BoxStyle(lines = 2), "en") }
        assertTrue("the sample needs 3 lines here (${layoutOf(sample).lineCount})", layoutOf(sample).lineCount > 2)
        assertWhole(sample)
    }

    @Test
    fun `Try it's longer line shows its newest two lines, as the overlay does, and is never cut at its end`() {
        show { BoxPreview(BoxStyle(lines = 2), "en", text = long) }
        assertNewest(long, 2)
        shoot("newest-lines")
    }

    @Test
    fun `the watch subtitles' sample is whole, and Try it's longer line shows its newest two lines`() {
        show { SubtitlePreview(SubtitleStyle(), "en") }
        assertWhole("The enemy is at the red house")
        show { SubtitlePreview(SubtitleStyle(lines = 2), "en", text = long) }
        assertNewest(long, 2)
    }

    /** Urdu (Nastaliq, 1.7 line height) at 1.3 and Largest, one and two lines: the shots show no glyph shaved at the clip. */
    @Test
    fun `in Urdu the sample is whole and Try it's line shows its newest lines, at 1·3 and Largest`() {
        for (scale in listOf(1.3f, 1.69f)) {
            for (lines in listOf(1, 2)) {
                show(scale) { BoxPreview(BoxStyle(lines = lines), "ur") }
                assertWhole("چلو دوستو، دشمن سرخ گھر کے پاس ہے")
                show(scale) { BoxPreview(BoxStyle(lines = lines), "ur", text = longUrdu) }
                assertNewest(longUrdu, lines)
                shoot("ur-lines$lines-${if (scale > 1.5f) "largest" else "130"}")
            }
        }
    }
}
