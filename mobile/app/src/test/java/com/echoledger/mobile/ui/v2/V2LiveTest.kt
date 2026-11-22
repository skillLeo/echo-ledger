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
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.recording.PillEvent
import com.echoledger.mobile.recording.PillState
import com.echoledger.mobile.ui.v2.live.CallCardScreen
import com.echoledger.mobile.ui.v2.live.CallChoiceCard
import com.echoledger.mobile.ui.v2.live.V2RecordingPill
import com.echoledger.mobile.ui.v2.live.afterCallTitle
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

/**
 * The recording pill and the after-call card as components: the events and callbacks they send, their words, and how
 * they look. They are not wired to the recording service or the after-call window here; that, and the test of the real
 * pause, stop and delayed delete, comes with the wiring in the phone core.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class V2LiveTest {
    @get:Rule
    val compose = createComposeRule()

    private val outDir =
        File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone/live")

    private fun show(language: EchoLanguage = EchoLanguage.English, dark: Boolean = false, content: @Composable () -> Unit) =
        compose.setContent { EchoV2Theme(darkTheme = dark, language = language, content = content) }

    @Test
    fun thePillSendsItsEventsToTheMachine() {
        val events = mutableListOf<PillEvent>()
        var state by mutableStateOf(PillState(elapsedMs = 134_000))
        show { V2RecordingPill(state, 0, "Omar Khan", { events += it }) }
        compose.onNodeWithContentDescription("Recording 02:14. Tap for the controls.").performClick()
        assertEquals(PillEvent.Tap, events.last())
        state = state.copy(mode = PillState.Mode.MENU)
        compose.onNodeWithText("Pause").performClick()
        compose.onNodeWithText("Stop").performClick()
        assertEquals(listOf(PillEvent.Tap, PillEvent.TogglePause, PillEvent.Stop), events)
        state = state.copy(stopArmedTicks = 0)
        compose.onNodeWithText("Tap again to stop").assertExists()
        compose.onNodeWithText("Discard").performClick()
        assertTrue(events.last() is PillEvent.DiscardNow)
    }

    @Test
    fun aDiscardingPillOffersUndoWithTheSecondsLeft() {
        val events = mutableListOf<PillEvent>()
        show { V2RecordingPill(PillState(elapsedMs = 60_000, discardAtMs = 5_000), 1_000, "Omar Khan", { events += it }) }
        compose.onNodeWithText("Undo 4").performClick()
        assertEquals(PillEvent.Undo, events.single())
    }

    @Test
    fun theCardsDeleteAndUndoCallTheirHostAndSwitchToItsLine() {
        var screen by mutableStateOf<CallCardScreen>(CallCardScreen.Ask("Omar Khan", 252_000, "whatsapp"))
        var deleted = 0
        var undone = 0
        show {
            CallChoiceCard(
                screen,
                onTranscribe = {},
                onDelete = {
                    deleted++
                    screen = CallCardScreen.Done(com.echoledger.mobile.calls.CallChoice.Pick.DELETE)
                },
                onAudio = {},
                onUndo = {
                    undone++
                    screen = CallCardScreen.Ask("Omar Khan", 252_000, "whatsapp")
                },
                still = true
            )
        }
        compose.onNodeWithText("Delete").performClick()
        assertEquals(1, deleted)
        compose.onNodeWithText("Deleted").assertExists()
        compose.onNodeWithText("Undo").performClick()
        assertEquals(1, undone)
        compose.onNodeWithText("Transcribe").assertExists()
    }

    @Test
    fun theCardsTitleIsInTheScreensLanguage() {
        val rec = LocalRecording(
            id = "r1", sourceApp = "whatsapp", startedAt = "2026-11-17T05:00:00Z", endedAt = "2026-11-17T05:04:12Z", durationMs = 252_000,
            mime = "audio/ogg", codec = "opus", channels = 2, sampleRate = 48_000, plainSize = 1, sha256 = "x", state = "pending_decision",
            callerName = "عمر خان"
        )
        assertEquals("عمر خان سے کال", afterCallTitle(V2Testing.resources(EchoLanguage.Urdu), rec))
        assertEquals("WhatsApp", afterCallTitle(V2Testing.resources(EchoLanguage.English), rec.copy(callerName = null)))
        // A number stays left to right inside the Urdu sentence: wrapped in direction marks, never mirrored.
        val number = afterCallTitle(V2Testing.resources(EchoLanguage.Urdu), rec.copy(callerName = null, callerNumber = "+92 300 1234567"))
        assertTrue(number, number.contains("+92 300 1234567") && number.endsWith("سے کال"))
        assertTrue(number, number.contains("\u202A+92 300 1234567\u202C"))
        assertEquals(
            "Call with +92 300 1234567",
            afterCallTitle(
                V2Testing.resources(EchoLanguage.English),
                rec.copy(callerName = null, callerNumber = "+92 300 1234567")
            ).filter {
                it.code !in
                    0x200E..0x202E
            }
        )
    }

    @Composable
    private fun Card(screen: CallCardScreen) = CallChoiceCard(screen, {}, {}, {}, {}, still = true)

    @Composable
    private fun Backdrop(content: @Composable () -> Unit) = Column(
        Modifier.fillMaxWidth().background(EchoV2.colors.bgCanvas).padding(EchoTokens.Space.s16).testTag("shot"),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
    ) { content() }

    @Test
    fun screenshots() {
        var language by mutableStateOf(EchoLanguage.English)
        var dark by mutableStateOf(false)
        var part by mutableStateOf("pill")
        compose.setContent {
            EchoV2Theme(darkTheme = dark, language = language) {
                val ur = language == EchoLanguage.Urdu
                val name = if (ur) "عمر خان" else "Omar Khan"
                Backdrop {
                    if (part == "pill") {
                        V2RecordingPill(PillState(elapsedMs = 134_000), 0, name, {})
                        V2RecordingPill(PillState(elapsedMs = 734_000, paused = true), 0, name, {})
                        V2RecordingPill(PillState(elapsedMs = 60_000, discardAtMs = 5_000), 1_000, name, {})
                        V2RecordingPill(PillState(mode = PillState.Mode.DOT, elapsedMs = 134_000), 0, name, {})
                        V2RecordingPill(PillState(mode = PillState.Mode.MENU, elapsedMs = 134_000, language = "UR+EN"), 0, name, {})
                    } else {
                        Card(CallCardScreen.Ask(name, 252_000, "whatsapp", hint = true))
                        Card(CallCardScreen.Done(com.echoledger.mobile.calls.CallChoice.Pick.AUDIO))
                        Card(CallCardScreen.Done(com.echoledger.mobile.calls.CallChoice.Pick.DELETE))
                    }
                }
            }
        }
        for (p in listOf("pill", "after-call")) {
            for (l in EchoLanguage.entries) {
                for (d in listOf(false, true)) {
                    part = p
                    language = l
                    dark = d
                    compose.waitForIdle()
                    val file = "$p-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}.png"
                    compose.onNodeWithTag("shot").captureRoboImage(File(outDir, file))
                }
            }
        }
    }
}
