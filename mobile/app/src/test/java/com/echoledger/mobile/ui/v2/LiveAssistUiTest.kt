package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.text.style.ResolvedTextDirection
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.ui.v2.assist.AssistCard
import com.echoledger.mobile.ui.v2.assist.AssistFixtures
import com.echoledger.mobile.ui.v2.assist.AssistMessage
import com.echoledger.mobile.ui.v2.assist.CONSENT_TAG
import com.echoledger.mobile.ui.v2.assist.CardState
import com.echoledger.mobile.ui.v2.assist.EndStatus
import com.echoledger.mobile.ui.v2.assist.FixtureAssistAdapter
import com.echoledger.mobile.ui.v2.assist.LiveMode
import com.echoledger.mobile.ui.v2.assist.MeetingType
import com.echoledger.mobile.ui.v2.assist.ModeReason
import com.echoledger.mobile.ui.v2.assist.SendKind
import com.echoledger.mobile.ui.v2.assist.SuggestionKind
import com.echoledger.mobile.ui.v2.assist.TEXT_TAG
import com.echoledger.mobile.ui.v2.assist.V2AssistCard
import com.echoledger.mobile.ui.v2.assist.V2AssistHost
import com.echoledger.mobile.ui.v2.live.LiveEntry
import com.echoledger.mobile.ui.v2.live.V2LiveTab
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/** The Live tab and the Live Assist card on a fixture stream: what they show, send and report, and how they look. */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class LiveAssistUiTest {
    @get:Rule
    val compose = createComposeRule()

    private val outDir =
        File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone/live")

    private fun show(language: EchoLanguage = EchoLanguage.English, content: @Composable () -> Unit) =
        compose.setContent { EchoV2Theme(darkTheme = false, language = language, content = content) }

    @Test
    fun liveAssistIsOnTheLiveTabOnlyWithItsFlag() {
        var on by mutableStateOf(false)
        val opened = mutableListOf<LiveEntry>()
        show { V2LiveTab(liveAssist = on, liveTranslate = true, onOpen = { opened += it }) }
        compose.onNodeWithText("Start Live Assist").assertDoesNotExist()
        compose.onNodeWithText("Voice note").performClick()
        on = true
        compose.onNodeWithText("Start Live Assist").performClick()
        assertEquals(listOf(LiveEntry.VoiceNote, LiveEntry.LiveAssist), opened)
    }

    @Test
    fun theFixtureStreamFillsTheCardAndTheButtonsAsk() {
        val adapter = FixtureAssistAdapter(meetingType = MeetingType.ExistingClient, stepMs = 10)
        show { V2AssistHost(adapter, startExpanded = true) }
        compose.mainClock.advanceTimeBy(500)
        compose.waitForIdle()
        compose.onNodeWithText("Delivery to the Hyderabad godown by Thursday", substring = true).assertExists()
        compose.onNodeWithText("Help me now").performClick()
        compose.onNodeWithText("Shorter").performClick()
        compose.onNodeWithText("More formal").performClick()
        // The language line's label also says Urdu; the button is the one that clicks.
        compose.onNode(
            androidx.compose.ui.test.hasText("Urdu") and androidx.compose.ui.test.hasClickAction()
        ).performClick()
        assertEquals(listOf(SendKind.HelpNow, SendKind.Shorter, SendKind.Formal, SendKind.Translate), adapter.sent)
        // "shown" once, when the first text was drawn.
        assertEquals(listOf("s-1"), adapter.shown.map { it.first })
        assertTrue(adapter.shown.single().second >= 0)
    }

    @Test
    fun theDotLightsWhenASuggestionIsReadyAndOpensTheCard() {
        var expanded by mutableStateOf(false)
        val state = AssistCard.reduce(AssistFixtures.clientCall)
        show { V2AssistCard(state, expanded, { expanded = it }, {}, {}) }
        compose.onNodeWithContentDescription("A suggestion is ready. Tap to see it.").performClick()
        compose.onNodeWithText("Help me now").assertExists()
    }

    @Test
    fun theInterviewReminderAndTheConsentLine() {
        val interview = CardState(sessionId = "a", meetingType = MeetingType.Interview)
        show { V2AssistCard(interview, true, {}, {}, {}) }
        compose.onNodeWithText("Check whether this interview allows AI help.").assertExists()
        compose.onNodeWithText("OK").performClick()
        compose.onNodeWithText("Check whether this interview allows AI help.").assertDoesNotExist()
    }

    @Test
    fun errorsAndModeChangesAreInPlainWords() {
        var state by mutableStateOf(
            AssistCard.reduce(listOf(AssistMessage.Error("budget_exhausted", "budget_exhausted", true, null, false)))
        )
        show { V2AssistCard(state, true, {}, {}, {}) }
        compose.onNodeWithText("Try again in a moment.").assertExists()
        compose.onNodeWithText("budget_exhausted").assertDoesNotExist()
        state = AssistCard.reduce(state, AssistMessage.ModeChanged(LiveMode.Fast, ModeReason.Budget))
        compose.onNodeWithText("Switched to Fast to stay within your assist hours.").assertExists()
    }

    @Test
    fun atLargeTextNothingIsCutAndTheTextScrollsPastSixLines() {
        val long = "Yes, 4,200 holds for sixty cartons if payment stays at 45 days and the order is confirmed by Friday.\n" +
            "- Delivery to the Hyderabad godown by Thursday, unloading by their own labour\n" +
            "- Their PO number on every invoice, and the Sukkur order on the same terms\n" +
            "- Rate held until the end of the month, then reviewed with the new freight charges"
        val state = AssistCard.reduce(
            listOf(
                AssistMessage.Ready("01JS", LiveMode.Balanced, listOf(LiveMode.Balanced), resumed = false, meetingType = null),
                AssistMessage.SuggestionStart("s-1", null, SuggestionKind.Answer, false, null, LiveMode.Balanced, false),
                AssistMessage.Token("s-1", long),
                AssistMessage.SuggestionEnd("s-1", EndStatus.Done)
            )
        )
        var scale by mutableStateOf(1f)
        show {
            val d = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(d.density, scale)) { V2AssistCard(state, true, {}, {}, {}) }
        }
        val scrolls = { compose.onNodeWithTag(TEXT_TAG).fetchSemanticsNode().config.contains(SemanticsActions.ScrollBy) }
        // Normal text: three lines, no scrolling.
        assertTrue(!scrolls())
        compose.onNodeWithText("Rate held", substring = true).assertDoesNotExist()
        // 200 %: every bullet is there, the area is at most six lines high and scrolls to the last one.
        scale = 2f
        compose.waitForIdle()
        assertTrue(scrolls())
        val node = compose.onNodeWithTag(TEXT_TAG).fetchSemanticsNode()
        val sixLines = EchoTokens.Type.bodyMobile.lineHeight.value * 6 * 2f * node.layoutInfo.density.density
        assertTrue("${node.size.height} > $sixLines", node.size.height <= sixLines + 1)
        compose.onNodeWithText("Rate held", substring = true).performScrollTo().assertIsDisplayed()
    }

    @Test
    fun theConsentSentenceReadsLeftToRightOnAnUrduScreen() {
        show(EchoLanguage.Urdu) { V2AssistCard(AssistCard.reduce(AssistFixtures.clientCall), true, {}, {}, {}) }
        val node = compose.onNodeWithTag(CONSENT_TAG).fetchSemanticsNode()
        val results = mutableListOf<TextLayoutResult>()
        node.config[SemanticsActions.GetTextLayoutResult].action?.invoke(results)
        val layout = results.single()
        val text = layout.layoutInput.text.text
        assertTrue(text, text.endsWith("Is that okay with you?"))
        assertEquals(ResolvedTextDirection.Ltr, layout.getParagraphDirection(0))
        // Every line starts at the left, and the "?" is the last thing on the last line, at its right.
        val last = layout.lineCount - 1
        for (line in 0..last) assertTrue("line $line starts at ${layout.getLineLeft(line)}", layout.getLineLeft(line) < 1f)
        val mark = layout.getBoundingBox(text.lastIndex)
        assertEquals(last, layout.getLineForOffset(text.lastIndex))
        assertTrue("? at ${mark.right}, line ends at ${layout.getLineRight(last)}", mark.right >= layout.getLineRight(last) - 1f)
    }

    @Composable
    private fun Backdrop(content: @Composable () -> Unit) = Column(
        Modifier.fillMaxWidth().background(EchoV2.colors.bgCanvas).padding(EchoTokens.Space.s16).testTag("shot"),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
    ) { content() }

    @Test
    fun screenshots() {
        var language by mutableStateOf(EchoLanguage.English)
        var which by mutableStateOf("assist")
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = language) {
                val ready = AssistCard.reduce(AssistFixtures.clientCall)
                Backdrop {
                    when (which) {
                        "assist" -> {
                            V2AssistCard(ready, false, {}, {}, {})
                            V2AssistCard(ready.copy(sessionId = "x", meetingType = MeetingType.Boss), true, {}, {}, {})
                        }

                        "assist-states" -> {
                            V2AssistCard(ready.copy(meetingType = MeetingType.Interview), true, {}, {}, {})
                            V2AssistCard(
                                AssistCard.reduce(
                                    listOf(
                                        AssistMessage.ModeChanged(LiveMode.Fast, ModeReason.Budget),
                                        AssistMessage.Error("provider_timeout", "x", true, null, false)
                                    )
                                ),
                                true,
                                {},
                                {},
                                {}
                            )
                        }

                        else -> V2LiveTab(liveAssist = true, liveTranslate = true, onOpen = {})
                    }
                }
            }
        }
        for (name in listOf("assist", "assist-states", "live-tab")) {
            for (l in EchoLanguage.entries) {
                which = name
                language = l
                compose.waitForIdle()
                val file = "$name-${if (l == EchoLanguage.Urdu) "ur" else "en"}.png"
                if (name ==
                    "live-tab"
                ) {
                    compose.onRoot().captureRoboImage(File(outDir, file))
                } else {
                    compose.onNodeWithTag("shot").captureRoboImage(File(outDir, file))
                }
            }
        }
    }
}
