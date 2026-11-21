package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.text.TextLayoutResult
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.WordDto
import com.echoledger.mobile.ui.v2.library.TranscriptRow
import com.echoledger.mobile.ui.v2.library.lines
import com.echoledger.mobile.ui.v2.shell.V2Text
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Transcript lines against classic's (docs/v2/audit/parity.csv PH-REC-037, 038): the line being played is the active
 * one and the word being spoken is marked; unsure words are named for screen readers and a tap on one plays from it.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi", application = Application::class)
class V2TranscriptLineParityTest {
    @get:Rule
    val compose = createComposeRule()

    private val text: V2Text = V2Text.from(V2Testing.resources(EchoLanguage.English))

    private val segments = listOf(
        SegmentDto(1, "Me", 0, 4_000, "Hello there.", listOf(WordDto("Hello", 0, 500, 0.98), WordDto("there.", 600, 1_000, 0.97))),
        SegmentDto(
            2,
            "Client",
            5_000,
            9_000,
            "Rate is forty rupees.",
            listOf(
                WordDto("Rate", 5_000, 5_400, 0.95),
                WordDto("is", 5_500, 5_700, 0.96),
                WordDto("forty", 6_000, 6_500, 0.31),
                WordDto("rupees.", 6_600, 7_200, 0.94)
            )
        )
    )

    @Test
    fun theLineBeingPlayedIsActiveAndTheWordBeingSpokenIsKnown() {
        val shown = lines(segments, null, emptyMap(), text, activeIndex = 1, positionMs = 6_100, foundIndex = null, kept = emptySet())
        assertEquals(listOf(false, true), shown.map { it.active })
        // "forty" (the third word) started at 6.0 s.
        assertEquals(2, shown[1].spoken)
        assertEquals(-1, shown[0].spoken)
    }

    private fun layoutOf(words: String): TextLayoutResult {
        val node = compose.onNodeWithText(words, substring = true, useUnmergedTree = true).fetchSemanticsNode()
        val out = mutableListOf<TextLayoutResult>()
        node.config[SemanticsActions.GetTextLayoutResult].action?.invoke(out)
        return out.single()
    }

    @Test
    fun theSpokenWordIsMarkedAndAnUnsureWordIsNamedAndPlaysFromItself() {
        val line = lines(segments, null, emptyMap(), text, activeIndex = 1, positionMs = 6_100, foundIndex = null, kept = emptySet())[1]
        val played = mutableListOf<Long>()
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                TranscriptRow(line, canPlay = true, onPlay = { played += it }, onActions = {})
            }
        }
        val layout = layoutOf("forty")
        val words = layout.layoutInput.text
        val forty = words.text.indexOf("forty")
        // The word being spoken has its own background, on that word only.
        assertTrue(words.spanStyles.any { it.item.background.alpha > 0f && it.start == forty && it.end == forty + "forty".length })
        compose.onNodeWithText("forty", substring = true, useUnmergedTree = true)
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.StateDescription, "Not sure of 1 word"))
        // A tap on a word plays from the unsure word under it (the offset the text gives for the tap), else the line.
        val (shownWords, ranges) = com.echoledger.mobile.core.Uncertain.layout(segments[1].words)
        assertEquals(words.text, shownWords)
        assertEquals(6_000L, com.echoledger.mobile.core.Uncertain.playFrom(segments[1], ranges, shownWords.indexOf("forty") + 2))
        assertEquals(5_000L, com.echoledger.mobile.core.Uncertain.playFrom(segments[1], ranges, shownWords.indexOf("Rate") + 1))
        // A tap elsewhere on the line plays from the line.
        compose.onNodeWithText("forty", substring = true).performClick()
        assertEquals(listOf(5_000L), played)
    }
}
