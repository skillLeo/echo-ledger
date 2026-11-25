package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.translate.BoxStyle
import com.echoledger.mobile.translate.SubtitleStyle
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/** P-119: a Try it notice (why it stopped, what it waits for) is shown whole in the game and watch previews. */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h800dp", fontScale = 1.3f)
class TranslatePreviewNoticeTest {
    @get:Rule
    val compose = createComposeRule()

    private val notice = "Couldn't hear the game: Android stopped sharing its sound. Start it again from the panel above."

    private fun assertWhole() {
        val layout = mutableListOf<TextLayoutResult>()
        val node = compose.onNodeWithText(notice, useUnmergedTree = true)
        node.performSemanticsAction(SemanticsActions.GetTextLayoutResult) { it(layout) }
        assertTrue("the notice needs more than one line here (${layout.first().lineCount})", layout.first().lineCount > 1)
        assertEquals("all of it shows", layout.first().size.height.toFloat(), node.fetchSemanticsNode().boundsInRoot.height, 1.5f)
    }

    @Test
    fun `a notice is shown whole in the game box, even with one line kept`() {
        compose.setContent {
            EchoLedgerTheme("light") {
                Box(Modifier.width(360.dp).padding(16.dp)) { BoxPreview(BoxStyle(lines = 1), "en", text = notice, notice = true) }
            }
        }
        assertWhole()
    }

    @Test
    fun `a notice is shown whole in the watch subtitles`() {
        compose.setContent {
            EchoLedgerTheme("light") {
                Box(Modifier.width(360.dp).padding(16.dp)) { SubtitlePreview(SubtitleStyle(lines = 1), "en", text = notice, notice = true) }
            }
        }
        assertWhole()
    }
}
