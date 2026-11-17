package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.test.click
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.unit.LayoutDirection
import androidx.test.core.app.ApplicationProvider
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Dark mode's stock buttons and labels are readable (since v1.8.1, bug P-014), and in a right-to-left language the
 * scrubber seeks where it was touched (P-015).
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class ContrastAndRtlTest {
    @get:Rule
    val compose = createComposeRule()

    private fun contrast(a: Color, b: Color): Float {
        val (hi, lo) = listOf(a.luminance(), b.luminance()).sortedDescending()
        return (hi + 0.05f) / (lo + 0.05f)
    }

    @Test
    fun `dark mode's stock text buttons are 4_5 to 1 or more on the page, on cards and in dialogs`() {
        val scheme = DarkColors.toScheme()
        listOf(DarkColors.bg, DarkColors.surface, DarkColors.surface2, DarkColors.surface3).forEach { under ->
            assertTrue("primary on $under: ${contrast(scheme.primary, under)}", contrast(scheme.primary, under) >= 4.5f)
        }
        assertTrue("a stock filled button's text", contrast(scheme.onPrimary, scheme.primary) >= 4.5f)
        // Light mode keeps its brand primary, which already passes on white.
        assertEquals(LightColors.accentStrong, LightColors.toScheme().primary)
        assertTrue(contrast(LightColors.toScheme().primary, LightColors.surface) >= 4.5f)
    }

    @Test
    fun `right to left, a touch counts from the right where the track starts`() {
        assertEquals(0.1f, ScrubberMath.fraction(10f, 100, rtl = false), 0.001f)
        assertEquals(0.9f, ScrubberMath.fraction(10f, 100, rtl = true), 0.001f)
        assertEquals(0f, ScrubberMath.fraction(150f, 100, rtl = true), 0.001f)

        val state = RecordingPlayerState(ApplicationProvider.getApplicationContext())
        state.knownMs = 100_000
        compose.setContent {
            EchoLedgerTheme("light") {
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl) {
                    Scrubber(state, enabled = true, modifier = androidx.compose.ui.Modifier.fillMaxWidth())
                }
            }
        }
        // A touch near the right end, where an Urdu reader sees the start, seeks near the start.
        compose.onNodeWithContentDescription("Position").performTouchInput { click(centerRight.copy(x = width * 0.95f)) }
        compose.runOnIdle {
            assertTrue("seeked to ${state.positionMs}", state.positionMs < 15_000)
            state.release()
        }
    }
}
