package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.ui.design.Avatar
import com.echoledger.mobile.ui.design.RecordingRow
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * P-028 and P-029 at 200 % text: a Library title isn't cut to a few letters (it takes two lines and the time moves
 * under it, giving it the row's width), and a speaker's initials stay inside their avatar circle.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h800dp")
class LargeTextRowsTest {
    @get:Rule
    val compose = createComposeRule()

    /** One 22 sp title line in px at the scale shown (Android 14 scales large text less than linearly). */
    private var titleLine = 0f

    private fun show(fontScale: Float, content: @Composable () -> Unit) {
        compose.setContent {
            val density = LocalDensity.current
            val scaled = Density(density.density, fontScale = fontScale)
            titleLine = with(scaled) { 22.sp.toPx() }
            CompositionLocalProvider(LocalDensity provides scaled) {
                EchoLedgerTheme("light") { Box(Modifier.width(360.dp)) { content() } }
            }
        }
    }

    @Composable
    private fun row() = RecordingRow(
        title = "Weekly sync with Northwind",
        time = "Nov 23",
        meta = "WhatsApp · 12 min",
        tile = { Box(Modifier.size(44.dp)) },
        onClick = {},
        onLongClick = {}
    )

    private fun boundsOf(text: String) = compose.onNodeWithText(text, useUnmergedTree = true).fetchSemanticsNode().boundsInRoot

    @Test
    fun `at 200 % text a title takes two lines and the time moves under it`() {
        show(2f) { row() }
        val title = boundsOf("Weekly sync with Northwind")
        val time = boundsOf("Nov 23")
        assertTrue("title is ${title.height} px for a $titleLine px line", title.height >= titleLine * 1.5f)
        assertTrue("time at ${time.top} px, title ends at ${title.bottom} px", time.top >= title.bottom)
    }

    @Test
    fun `at normal text the row is as before, the time beside a one-line title`() {
        show(1f) { row() }
        val title = boundsOf("Weekly sync with Northwind")
        val time = boundsOf("Nov 23")
        assertTrue("time at ${time.top} px, title ends at ${title.bottom} px", time.top < title.bottom)
        assertTrue("time starts at ${time.left} px, title ends at ${title.right} px", time.left >= title.right)
    }

    @Test
    fun `at 200 % text a speaker's initials stay inside their 28 dp circle`() {
        show(2f) { Avatar("Maryam Wasim", size = 28.dp) }
        val layout = mutableListOf<TextLayoutResult>()
        compose.onNodeWithText("MW", useUnmergedTree = true).performSemanticsAction(SemanticsActions.GetTextLayoutResult) { it(layout) }
        // The text's own size, not the box it was given (a layout's size is clamped to the circle).
        val text = layout.first().multiParagraph
        val circle = with(compose.density) { 28.dp.toPx() }
        assertTrue("initials broke into ${text.lineCount} lines in a $circle px circle", text.lineCount == 1)
        assertTrue("initials ${text.width} x ${text.height} px in a $circle px circle", text.height <= circle)
    }
}
