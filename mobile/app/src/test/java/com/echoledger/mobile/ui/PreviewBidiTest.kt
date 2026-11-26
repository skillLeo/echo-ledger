package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Text
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.unit.dp
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * P-127: the Try it preview shows a line's newest lines from where they start. Cut just before a number in Urdu, the
 * digits would resolve as European numbers (the Arabic letter before them is gone) and a % beside them would change
 * sides; the mark of the line's direction in front keeps them as they were in the whole line.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34])
class PreviewBidiTest {
    @get:Rule
    val compose = createComposeRule()

    @Test
    fun `the cut text starts with the line's direction mark, and an uncut one is as it is`() {
        val line = "دشمن 25% قریب ہے"
        val from = line.indexOf("25%")
        assertEquals("؜25% قریب ہے", newestText(line, from, rtl = true))
        assertEquals("‎game over", newestText("the game over", 4, rtl = false))
        assertEquals(line, newestText(line, 0, rtl = true))
    }

    /** Whether the % sits left of the 2 in [tag]'s text, as drawn. */
    private fun percentLeftOfDigits(tag: String): Boolean {
        val layouts = mutableListOf<TextLayoutResult>()
        compose.onNodeWithTag(tag, useUnmergedTree = true).performSemanticsAction(SemanticsActions.GetTextLayoutResult) { it(layouts) }
        val layout = layouts.single()
        val text = layout.layoutInput.text.text
        return layout.getBoundingBox(text.indexOf('%')).left < layout.getBoundingBox(text.indexOf('2')).left
    }

    @Test
    fun `cut just before a number, its percent sign keeps the side it had in the whole line`() {
        val line = "دشمن 25% قریب ہے"
        val from = line.indexOf("25%")
        val rtl = TextStyle(textDirection = TextDirection.Rtl)
        compose.setContent {
            EchoLedgerTheme("light") {
                Column(Modifier.width(320.dp)) {
                    Text(line, style = rtl, modifier = Modifier.testTag("whole"))
                    Text(line.substring(from), style = rtl, modifier = Modifier.testTag("bare"))
                    Text(newestText(line, from, rtl = true), style = rtl, modifier = Modifier.testTag("marked"))
                }
            }
        }
        val whole = percentLeftOfDigits("whole")
        // Without the mark the % would change sides (what the mark is for)...
        assertNotEquals("a bare cut changes the order", whole, percentLeftOfDigits("bare"))
        // ...with it the order is the whole line's.
        assertEquals("the marked cut keeps the whole line's order", whole, percentLeftOfDigits("marked"))
    }
}
