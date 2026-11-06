package com.echoledger.mobile.ui

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.design.SegmentedControl
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** A small phone with large text (Phase 1B: 360 dp, 1.3x): seen on an emulator, "Recordings" read "Recordi". */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], qualifiers = "w360dp-h640dp-xhdpi")
class SmallScreenTest {
    @get:Rule
    val compose = createComposeRule()

    private val tabs = listOf("Notes", "Recordings", "Files", "Screens", "Ask")

    /** A word broken at the edge of its box ends its first line early; one laid out wider than its box runs past it. */
    private fun SemanticsNodeInteraction.isCut(): Boolean {
        val node = fetchSemanticsNode()
        val results = mutableListOf<TextLayoutResult>()
        node.config[SemanticsActions.GetTextLayoutResult].action!!(results)
        val text = results[0]
        return text.getLineEnd(0, visibleEnd = true) != text.layoutInput.text.length || text.getLineRight(0) > node.size.width + 1
    }

    private fun show(fontScale: Float, options: List<String>, onSelect: (Int) -> Unit = {}) {
        compose.setContent {
            EchoLedgerTheme("light") {
                CompositionLocalProvider(LocalDensity provides Density(2f, fontScale)) {
                    SegmentedControl(options, 0, onSelect)
                }
            }
        }
    }

    @Test
    fun `five tabs at large text keep their whole words and can be reached`() {
        var chosen = -1
        show(1.3f, tabs, { chosen = it })

        tabs.forEach { assertFalse("$it is cut", compose.onNodeWithText(it).isCut()) }
        compose.onNodeWithText("Screens").performClick()
        assertEquals(3, chosen)
        compose.onNodeWithText("Screens").assertIsDisplayed()
    }

    @Test
    fun `two short choices at normal text still share the width`() {
        show(1f, listOf("Original", "English"))

        val first = compose.onNodeWithText("Original").fetchSemanticsNode().parent!!.boundsInRoot.width
        val second = compose.onNodeWithText("English").fetchSemanticsNode().parent!!.boundsInRoot.width
        assertEquals(first, second, 1f)
    }
}
