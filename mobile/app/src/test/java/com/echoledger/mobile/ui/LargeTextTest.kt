package com.echoledger.mobile.ui

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.getUnclippedBoundsInRoot
import androidx.compose.ui.test.hasScrollAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performScrollToNode
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.ui.design.NavLabel
import com.echoledger.mobile.ui.design.SettingsRow
import com.echoledger.mobile.ui.design.SettingsRowLayout
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Large text and fixed sizes (since v1.8.1, batch 4): a settings title keeps whole words (P-010), the bottom bar's
 * labels stop at 1.5 × (P-011), the New sheet's tiles grow with their text (P-012), and Shared voice notes scroll (P-013).
 */
class LargeTextTest : ScreenTest() {
    /** [content] with the system text size at [fontScale]. */
    private fun showAt(fontScale: Float, content: @androidx.compose.runtime.Composable () -> Unit) = show {
        val density = LocalDensity.current
        CompositionLocalProvider(LocalDensity provides Density(density.density, fontScale)) { content() }
    }

    @Test
    fun `at large text a settings value goes under its title, as in Android's own Settings`() {
        assertFalse(SettingsRowLayout.stacked(1f))
        assertFalse(SettingsRowLayout.stacked(1.15f))
        assertTrue(SettingsRowLayout.stacked(2f))

        showAt(2f) { SettingsRow(null, "Microphone & audio", value = "Reduce echo", onClick = {}) }
        val title = compose.onNodeWithText("Microphone & audio", useUnmergedTree = true).getUnclippedBoundsInRoot()
        val value = compose.onNodeWithText("Reduce echo", useUnmergedTree = true).getUnclippedBoundsInRoot()
        assertTrue("the value is under the title: $value under $title", value.top >= title.bottom)
    }

    @Test
    fun `at normal text the value stays beside the title`() {
        showAt(1f) { SettingsRow(null, "Microphone & audio", value = "Reduce echo", onClick = {}) }
        val title = compose.onNodeWithText("Microphone & audio", useUnmergedTree = true).getUnclippedBoundsInRoot()
        val value = compose.onNodeWithText("Reduce echo", useUnmergedTree = true).getUnclippedBoundsInRoot()
        assertTrue("the value is beside the title: $value beside $title", value.top < title.bottom && value.left > title.right)
    }

    @Test
    fun `the bottom bar's labels grow to 1_5 times at most`() {
        assertEquals(11.sp, NavLabel.size(11.sp, 1f))
        assertEquals(16.5f, NavLabel.size(11.sp, 1.5f).value * 1.5f, 0.01f)
        assertEquals("never past 1.5 times on the screen", 16.5f, NavLabel.size(11.sp, 2f).value * 2f, 0.01f)
    }

    @Test
    fun `the New sheet's tiles grow so their status is never cut off`() {
        showAt(2f) {
            RecordSheet(
                Capabilities(34, true, micPermission = true, notificationPermission = true, overlayPermission = true),
                autoRecordOn = false,
                onPick = {},
                onDismiss = {}
            )
        }
        val tile = compose.onNodeWithText("Voice & files").getUnclippedBoundsInRoot()
        val status = compose.onNodeWithText("Record or import", useUnmergedTree = true).getUnclippedBoundsInRoot()
        // A fixed 112 dp tile left its status below its own bottom edge, cut off.
        assertTrue("the status ends inside its tile: $status in $tile", status.bottom <= tile.bottom)
    }

    @Test
    fun `many shared voice notes scroll, so the last one can be reached`() {
        val ids = (1..12).map { "note-$it" }
        show { SharedNotesScreen(vm, ids, onLeave = {}) }
        compose.onNode(hasScrollAction()).performScrollToNode(hasText("Voice note 12"))
        compose.onNodeWithText("Voice note 12").assertExists()
    }
}
