package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.getUnclippedBoundsInRoot
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.text.TextLayoutResult
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
 * P-119: Game and Watch translate's Try it preview at 1.3 text on a 360 dp phone. The game box shows the newest of its
 * lines, as the overlay does (TranslateTextView: older lines move up out of its window): the line is laid out whole, its
 * end always in view, never cut by a line limit (the sweep's rule, TextCuts, unchanged). The frame keeps 16:9 as its
 * least height and grows with the box.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h800dp", fontScale = 1.3f)
class TranslatePreviewTest {
    @get:Rule
    val compose = createComposeRule()

    private fun layoutOf(line: String): TextLayoutResult {
        val layout = mutableListOf<TextLayoutResult>()
        compose.onNodeWithText(line, useUnmergedTree = true).performSemanticsAction(SemanticsActions.GetTextLayoutResult) { it(layout) }
        return layout.first()
    }

    private fun assertWholeInsideFrame(line: String) {
        assertNull(TextCuts.problem(layoutOf(line)))
        val frame = compose.onNodeWithContentDescription("Preview over a sample game scene").getUnclippedBoundsInRoot()
        val text = compose.onNodeWithText(line, useUnmergedTree = true).getUnclippedBoundsInRoot()
        assertTrue("the line ends at ${text.bottom}, the frame at ${frame.bottom}", text.bottom <= frame.bottom)
    }

    @Test
    fun `the game box's sample line is whole inside its preview at 1·3 text`() {
        compose.setContent {
            EchoLedgerTheme("light") { Box(Modifier.width(360.dp).padding(16.dp)) { BoxPreview(BoxStyle(), "en") } }
        }
        assertWholeInsideFrame("Come on guys, the enemy is at the red house")
    }

    @Test
    fun `a longer line shows its newest two lines, as the overlay does, and is never cut at its end`() {
        val line = "Come on guys, the enemy is at the red house, go round the back and wait there for my signal"
        compose.setContent {
            EchoLedgerTheme("light") { Box(Modifier.width(360.dp).padding(16.dp)) { BoxPreview(BoxStyle(lines = 2), "en", text = line) } }
        }
        assertWholeInsideFrame(line)
        val layout = layoutOf(line)
        assertTrue("the line needs more than two lines here (${layout.lineCount})", layout.lineCount > 2)
        // What shows (the text clipped by its box) is the last two lines: their height, ending where the text ends.
        val node = compose.onNodeWithText(line, useUnmergedTree = true).fetchSemanticsNode()
        val last = layout.lineCount - 1
        val twoLines = layout.getLineBottom(last) - layout.getLineTop(last - 1)
        assertEquals(twoLines, node.boundsInRoot.height, 1.5f)
        assertEquals(node.unclippedBoundsInRootPx().bottom, node.boundsInRoot.bottom, 1.5f)
        val out = File(System.getProperty("screenshots.dir") ?: "build/screenshots")
        compose.onRoot().captureRoboImage(File(out, "phone-try-it-newest-lines.png"))
    }

    @Test
    fun `the watch subtitles' sample line is whole inside its preview at 1·3 text`() {
        compose.setContent {
            EchoLedgerTheme("light") { Box(Modifier.width(360.dp).padding(16.dp)) { SubtitlePreview(SubtitleStyle(), "en") } }
        }
        assertWholeInsideFrame("The enemy is at the red house")
    }

    private fun androidx.compose.ui.semantics.SemanticsNode.unclippedBoundsInRootPx() =
        androidx.compose.ui.geometry.Rect(positionInRoot, androidx.compose.ui.geometry.Size(size.width.toFloat(), size.height.toFloat()))
}
