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
import com.echoledger.mobile.ui.design.ReadableAccent
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
    fun `dark mode's text buttons and focused labels reach about 7 to 1, while the scheme's primary stays the brand fill`() {
        // Filled buttons, switches, checkboxes, radios, sliders and progress keep the brand colour in both themes.
        assertEquals(DarkColors.accentStrong, DarkColors.toScheme().primary)
        assertEquals(DarkColors.onAccent, DarkColors.toScheme().onPrimary)
        assertEquals(LightColors.accentStrong, LightColors.toScheme().primary)
        assertEquals(LightColors.onAccent, LightColors.toScheme().onPrimary)

        val words = ReadableAccent.of(DarkColors)
        listOf(DarkColors.bg, DarkColors.surface, DarkColors.surface2, DarkColors.surface3).forEach { under ->
            // The DESIGN_SYSTEM rule for words: 4.5:1 or more. These reach about 7:1 (6.99 on the lightest dark surface).
            val ratio = contrast(words, under)
            assertTrue("text button words on $under: $ratio", ratio >= 4.5f)
            assertTrue("about 7:1 on $under: $ratio", ratio >= 6.99f)
        }
        // The brand fill as words was the bug: under 4.5 to 1 on the dark page.
        assertTrue(contrast(DarkColors.accentStrong, DarkColors.bg) < 4.5f)
        // Light mode keeps its brand colour, which passes on white.
        assertEquals(LightColors.accentStrong, ReadableAccent.of(LightColors))
        assertTrue(contrast(ReadableAccent.of(LightColors), LightColors.surface) >= 4.5f)
    }

    @Test
    fun `Material's outlined buttons draw their words in our muted text, readable in both themes, never the brand fill`() {
        val seen = mutableMapOf<String, Color>()
        compose.setContent {
            EchoLedgerTheme("dark") { seen["dark"] = androidx.compose.material3.ButtonDefaults.outlinedButtonColors().contentColor }
            EchoLedgerTheme("light") { seen["light"] = androidx.compose.material3.ButtonDefaults.outlinedButtonColors().contentColor }
        }
        compose.waitForIdle()
        val dark = seen.getValue("dark")
        val light = seen.getValue("light")
        // If Material ever took them to the primary (the brand fill), dark mode would fall to about 3.6:1: this fails then.
        assertTrue("dark outlined words are not the brand fill", dark != DarkColors.accentStrong)
        listOf(DarkColors.bg, DarkColors.surface, DarkColors.surface2, DarkColors.surface3).forEach { under ->
            assertTrue("dark outlined words on $under: ${contrast(dark, under)}", contrast(dark, under) >= 4.5f)
        }
        listOf(LightColors.bg, LightColors.surface, LightColors.surface2).forEach { under ->
            assertTrue("light outlined words on $under: ${contrast(light, under)}", contrast(light, under) >= 4.5f)
        }
    }

    @Test
    fun `the classic screens use the readable text button and field, never Material's own`() {
        val material =
            Regex(
                """import androidx\.compose\.material3\.(TextButton|OutlinedTextField)$|androidx\.compose\.material3\.(TextButton|OutlinedTextField)\(""",
                RegexOption.MULTILINE
            )
        val offenders = java.io.File("src/main/java/com/echoledger/mobile").walkTopDown()
            .filter { it.extension == "kt" && "/ui/v2/" !in it.path && it.name != "ReadableAccent.kt" }
            .filter { material.containsMatchIn(it.readText()) }
            .map { it.name }.toList()
        assertEquals(emptyList<String>(), offenders)
    }

    @Test
    fun `right to left, a touch counts from the right where the track starts`() {
        // The exact ends: left to right the track starts at x = 0; right to left it starts at x = width.
        assertEquals(0f, ScrubberMath.fraction(0f, 100, rtl = false), 0f)
        assertEquals(1f, ScrubberMath.fraction(100f, 100, rtl = false), 0f)
        assertEquals(1f, ScrubberMath.fraction(0f, 100, rtl = true), 0f)
        assertEquals(0f, ScrubberMath.fraction(100f, 100, rtl = true), 0f)
        assertEquals(0.1f, ScrubberMath.fraction(10f, 100, rtl = false), 0.001f)
        assertEquals(0.9f, ScrubberMath.fraction(10f, 100, rtl = true), 0.001f)
        // A finger past either end stays at that end.
        assertEquals(0f, ScrubberMath.fraction(150f, 100, rtl = true), 0f)
        assertEquals(1f, ScrubberMath.fraction(-20f, 100, rtl = true), 0f)

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
