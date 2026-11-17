package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.ui.v2.assist.AssistCard
import com.echoledger.mobile.ui.v2.assist.AssistFixtures
import com.echoledger.mobile.ui.v2.assist.AssistMessage
import com.echoledger.mobile.ui.v2.assist.CardState
import com.echoledger.mobile.ui.v2.assist.FixtureAssistAdapter
import com.echoledger.mobile.ui.v2.assist.LiveMode
import com.echoledger.mobile.ui.v2.assist.MeetingType
import com.echoledger.mobile.ui.v2.assist.ModeReason
import com.echoledger.mobile.ui.v2.assist.SendKind
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
