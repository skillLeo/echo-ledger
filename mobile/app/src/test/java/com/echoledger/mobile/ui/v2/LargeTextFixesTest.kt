package com.echoledger.mobile.ui.v2

import android.app.Application
import android.content.res.Configuration
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.SemanticsNodeInteraction
import androidx.compose.ui.test.getUnclippedBoundsInRoot
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.ui.v2.components.EchoListRow
import com.echoledger.mobile.ui.v2.shell.Profile
import com.echoledger.mobile.ui.v2.shell.ProfileCardSlot
import com.echoledger.mobile.ui.v2.shell.ProfileState
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * The large-text findings of the Day 5 bug bash, each held at 100 % and 200 % text (docs/v2/bugbash/phone-day5.md), with
 * real text measurement (native graphics).
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class LargeTextFixesTest {
    @get:Rule
    val compose = createComposeRule()

    private var scale by mutableFloatStateOf(1f)
    private var language by mutableStateOf(EchoLanguage.English)

    /** As the phone draws at [scale] text: the configuration's font scale and its density (Android 14's scaling). */
    private fun show(content: @Composable () -> Unit) = compose.setContent {
        val base = LocalContext.current
        val config = LocalConfiguration.current
        val scaled = remember(scale) { base.createConfigurationContext(Configuration(config).apply { fontScale = scale }) }
        CompositionLocalProvider(LocalDensity provides Density(scaled), LocalConfiguration provides scaled.resources.configuration) {
            EchoV2Theme(darkTheme = false, language = language) { content() }
        }
    }

    private fun SemanticsNodeInteraction.layout(): TextLayoutResult {
        val results = mutableListOf<TextLayoutResult>()
        fetchSemanticsNode().config[SemanticsActions.GetTextLayoutResult].action?.invoke(results)
        return results.single()
    }

    private fun at(s: Float) {
        scale = s
        compose.waitForIdle()
    }

    @Test
    fun aLibraryTitleTakesTwoLinesAtLargeText() {
        val title = "Shaheen Pharmaceuticals, quarterly order review"
        show { EchoListRow(title, meta = "WhatsApp · Omar", titleEnd = "14:05", onClick = {}) }
        // At 100 % one line, as before.
        assertEquals(1, compose.onNodeWithText(title, useUnmergedTree = true).layout().lineCount)
        // P-085: at 200 % the title has a second line instead of stopping after a dozen letters.
        at(2f)
        assertEquals(2, compose.onNodeWithText(title, useUnmergedTree = true).layout().lineCount)
    }

    @Test
    fun upgradeGoesUnderTheUsageLinesAtLargeText() {
        val profile = Profile("Ayesha Siddiqui", "ayesha@bilaltraders.pk", "Starter", 212, 300, 1_240_000_000, 318)
        show { ProfileCardSlot(ProfileState.Ready(profile), {}, {}) }
        for (l in EchoLanguage.entries) {
            language = l
            at(1f)
            val res = V2Testing.resources(l)
            val minutes = res.getString(com.echoledger.mobile.R.string.v2_minutes_used, 212L, 300L)
            val upgrade = res.getString(com.echoledger.mobile.R.string.v2_upgrade)
            fun usageBottom() = compose.onNodeWithText(minutes, useUnmergedTree = true).getUnclippedBoundsInRoot().bottom
            fun buttonTop() = compose.onNodeWithText(upgrade, useUnmergedTree = true).getUnclippedBoundsInRoot().top
            // At 100 % Upgrade sits beside the usage lines.
            assertTrue("$l: beside", buttonTop() < usageBottom())
            // P-086: at 200 % it goes under them, so they keep the card's width.
            at(2f)
            assertTrue("$l: under", buttonTop() >= usageBottom())
            assertTrue("$l: a line or two", compose.onNodeWithText(minutes, useUnmergedTree = true).layout().lineCount <= 2)
        }
    }

    @Test
    fun aLiveTabExplanationNeverEndsOnOneWordAlone() {
        language = EchoLanguage.Urdu
        show { com.echoledger.mobile.ui.v2.live.V2LiveTab(liveAssist = true, liveTranslate = true, onOpen = {}) }
        val res = V2Testing.resources(EchoLanguage.Urdu)
        val lines = listOf(
            com.echoledger.mobile.R.string.v2_livetab_record_help,
            com.echoledger.mobile.R.string.v2_livetab_voice_note_help,
            com.echoledger.mobile.R.string.v2_livetab_translate_help,
            com.echoledger.mobile.R.string.v2_livetab_dictation_help,
            com.echoledger.mobile.R.string.v2_livetab_captions_help,
            com.echoledger.mobile.R.string.v2_assist_start_help
        )
        // P-088: at 200 % the Urdu explanations ended with one word alone on their last line ("ریکارڈ").
        for (s in listOf(1f, 1.3f, 2f)) {
            at(s)
            for (id in lines) {
                val words = res.getString(id)
                val layout = compose.onNodeWithText(words, useUnmergedTree = true).layout()
                if (layout.lineCount < 2) continue
                val last = words.substring(layout.getLineStart(layout.lineCount - 1), layout.getLineEnd(layout.lineCount - 1)).trim()
                assertTrue("$s: \"$words\" ends on \"$last\"", last.contains(' '))
            }
        }
    }
}
