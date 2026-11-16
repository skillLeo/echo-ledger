package com.echoledger.mobile.overlay

import androidx.compose.foundation.gestures.detectDragGestures
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.assertHasClickAction
import androidx.compose.ui.test.assertHeightIsEqualTo
import androidx.compose.ui.test.assertWidthIsEqualTo
import androidx.compose.ui.test.click
import androidx.compose.ui.test.longClick
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeLeft
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.recording.PillEvent
import com.echoledger.mobile.recording.PillState
import com.echoledger.mobile.testsupport.ScreenTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The grey dot of hidden floating windows (audit u23: it was 16 dp and answered only to touch) and the toolbar's
 * Hide EchoLedger windows button.
 */
class PrivateDotTest : ScreenTest() {
    private val dot get() = compose.onNodeWithContentDescription("EchoLedger's floating windows are hidden")

    @Test
    fun `the dot is a 48 dp button that TalkBack can press to show the windows`() {
        var shown = 0
        show { PrivateDot(onShow = { shown++ }) }

        dot.assertWidthIsEqualTo(48.dp).assertHeightIsEqualTo(48.dp)
        dot.assertHasClickAction()
        dot.assert(SemanticsMatcher.expectValue(SemanticsProperties.Role, Role.Button))
        dot.assert(
            SemanticsMatcher("double-tap says Show EchoLedger windows") {
                it.config.getOrElseNullable(SemanticsActions.OnClick) { null }?.label == "Show EchoLedger windows"
            }
        )

        dot.performSemanticsAction(SemanticsActions.OnClick) // TalkBack's double-tap
        assertEquals(1, shown)
        dot.performClick()
        assertEquals(2, shown)
        dot.performTouchInput { click(Offset(center.x + 15.dp.toPx(), center.y)) } // beside the 16 dp dot, inside 48 dp
        assertEquals(3, shown)
    }

    @Test
    fun `dragging the dot moves it and does not show the windows`() {
        var shown = 0
        var dragged = 0f
        show {
            PrivateDot(
                onShow = { shown++ },
                modifier = Modifier.pointerInput(Unit) {
                    detectDragGestures { change, amount ->
                        change.consume()
                        dragged += amount.x
                    }
                }
            )
        }
        dot.performTouchInput { swipeLeft() }
        assertEquals(0, shown)
        assertTrue("the drag reached the recording controls", dragged < 0f)
        dot.performClick()
        assertEquals(1, shown)
    }

    @Test
    fun `the toolbar's Hide EchoLedger windows hides them all and closes the menu`() {
        val events = mutableListOf<PillEvent>()
        show { PillToolbar(PillState(mode = PillState.Mode.MENU, elapsedMs = 134_000), onEvent = { events += it }) }

        val hide = compose.onNodeWithContentDescription("Hide EchoLedger windows")
        hide.performTouchInput { longClick() }
        compose.onNodeWithText("Hide EchoLedger windows").assertExists() // the name a long-press shows
        hide.performSemanticsAction(SemanticsActions.OnClick)

        assertTrue(ShareGuard.hiddenByUser.value)
        assertEquals(listOf<PillEvent>(PillEvent.CloseMenu), events)
    }
}
