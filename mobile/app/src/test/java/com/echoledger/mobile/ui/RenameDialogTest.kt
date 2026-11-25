package com.echoledger.mobile.ui

import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextClearance
import androidx.compose.ui.test.performTextInput
import androidx.compose.ui.text.AnnotatedString
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.testsupport.ScreenTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * U19 (2.0.0-k) on the classic screens, as on the desktop: only a title the person chose is in Rename's box to edit.
 * "Call with …", an automatic title or the app's name start the box empty, with that title as its placeholder, and
 * leaving it empty saves nothing. The box is tested on its own: a text field in a dialog window never lets Compose get
 * idle under Robolectric, so [RenameDialog] itself can't be opened here.
 */
class RenameDialogTest : ScreenTest() {
    private fun field(text: String) = SemanticsMatcher.expectValue(SemanticsProperties.EditableText, AnnotatedString(text))

    @Test
    fun `only a title the person renamed it to is theirs, never a machine title`() {
        fun item(source: String?, auto: Boolean = false) = LibraryItem(
            id = "r1",
            title = "Call with Omar",
            titleSource = source,
            titleAuto = auto,
            updatedAt = "2026-10-20T10:05:00+00:00"
        )
        assertTrue(ownTitle(item("own")))
        listOf("person", "auto", "app").forEach { assertFalse(it, ownTitle(item(it))) }
        // A server that doesn't say where a title came from: its automatic one is not the person's; any other is.
        assertTrue(ownTitle(item(null)))
        assertFalse(ownTitle(item(null, auto = true)))
    }

    @Test
    fun `a title the person chose is in the box, and emptying it gives back EchoLedger's`() {
        val box = RenameBox("Acme renewal", own = true)
        show { RenameField(box) }

        compose.onNode(hasSetTextAction()).assert(field("Acme renewal"))
        compose.onNodeWithText("Leave it empty for the title EchoLedger gives it.").assertExists()
        assertFalse("unchanged", box.saves)
        compose.onNode(hasSetTextAction()).performTextClearance()
        assertTrue("emptied: back to EchoLedger's title", box.saves)
        assertEquals("", box.text)
    }

    @Test
    fun `a title the person didn't choose starts the box empty, shown as its placeholder, and empty saves nothing`() {
        val box = RenameBox("Call with Omar", own = false)
        show { RenameField(box) }

        compose.onNode(hasSetTextAction()).assert(field(""))
        compose.onNodeWithText("Type a title of your own, or leave it empty to keep this one.").assertExists()
        assertFalse("empty over it: nothing to save", box.saves)
        compose.onNode(hasSetTextAction()).performClick()
        compose.onNodeWithText("Call with Omar", useUnmergedTree = true).assertExists()
        compose.onNode(hasSetTextAction()).performTextInput("Omar, the March order")
        assertTrue(box.saves)
        assertEquals("Omar, the March order", box.text)
    }
}
