package com.echoledger.mobile.ui.v2

import android.content.res.Configuration
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.getBoundsInRoot
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTouchInput
import androidx.compose.ui.test.swipeLeft
import androidx.compose.ui.test.swipeRight
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.calls.CallChoice.Pick
import com.echoledger.mobile.ui.v2.live.CallCardScreen
import com.echoledger.mobile.ui.v2.live.CallChoiceCard
import com.echoledger.mobile.ui.v2.live.V2AfterCallCard
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
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
 * The after-call card on a 360 dp phone (the call-choice plan, 2.0.5): its words, its one filled button, the swipe and
 * TalkBack's way to it, the confirmation lines, and that it fits at the smallest, normal and largest text, light and
 * dark, in English and Urdu. The Roborazzi tasks write it, and the v1.1 card beside it, to
 * docs/design/screens/call-choice (new-*.png, old-*.png); a plain run only checks each one drew.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h740dp-xhdpi")
class CallChoiceCardTest {
    @get:Rule
    val compose = createComposeRule()

    private var dark by mutableStateOf(false)
    private var language by mutableStateOf(EchoLanguage.English)
    private var scale by mutableFloatStateOf(1f)
    private val events = mutableListOf<String>()
    private val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots"), "call-choice")

    private val ask = CallCardScreen.Ask("Muneeb Quraishi", 44_000, "whatsapp")
    private var screen by mutableStateOf<CallCardScreen>(ask)
    private var old by mutableStateOf(false)
    private var generation by mutableIntStateOf(0)
    private var started = false

    /** Shows [next] (a new card, as a new call would), drawing the screen once per test. */
    private fun show(next: CallCardScreen, oldCard: Boolean = false) {
        screen = next
        old = oldCard
        generation++
        if (!started) {
            started = true
            start()
        }
        compose.waitForIdle()
    }

    private fun start() = compose.setContent {
        val base = LocalContext.current
        val config = LocalConfiguration.current
        val scaled = remember(scale) { base.createConfigurationContext(Configuration(config).apply { fontScale = scale }) }
        CompositionLocalProvider(LocalDensity provides Density(scaled), LocalConfiguration provides scaled.resources.configuration) {
            // v1.1's card was always dark; the new one follows the app's theme.
            EchoV2Theme(darkTheme = dark || old, language = language) {
                Box(Modifier.background(EchoV2.colors.bgCanvas).padding(vertical = 24.dp)) {
                    key(generation) { Card() }
                }
            }
        }
    }

    @androidx.compose.runtime.Composable
    private fun Card() {
        if (old) {
            V2AfterCallCard(
                title = "Call with Muneeb Quraishi",
                durationMs = 44_000,
                sourceWire = "whatsapp",
                mode = com.echoledger.mobile.ui.v2.live.AfterCallMode.Ask,
                visibleMs = 10_000,
                onSave = {},
                onDelete = {},
                onUndo = {},
                onTitle = {},
                onTranscribe = {},
                countdown = false,
                modifier = Modifier.padding(horizontal = 16.dp)
            )
        } else {
            CallChoiceCard(
                screen = screen,
                onTranscribe = { events += "transcribe" },
                onDelete = { events += "delete" },
                onAudio = { events += "audio" },
                onUndo = { events += "undo" },
                onAlwaysYes = { events += "always" },
                onReview = { events += "review" },
                still = true,
                modifier = Modifier.padding(horizontal = 16.dp)
            )
        }
    }

    @Test
    fun `the question names the call, then Delete and Transcribe, the swipe line and nothing else`() {
        show(ask)
        compose.onNodeWithText("Muneeb Quraishi").assertIsDisplayed()
        compose.onNodeWithText("0:44 · just now", substring = true).assertIsDisplayed()
        compose.onNodeWithText("Swipe away to keep audio only").assertIsDisplayed()
        compose.onNodeWithText("Delete").performClick()
        compose.onNodeWithText("Transcribe").performClick()
        assertEquals(listOf("delete", "transcribe"), events)
        listOf("Keep", "Skip", "Don't keep", "Save audio").forEach { compose.onAllNodesWithText(it).assertCountEquals(0) }
    }

    @Test
    fun `a swipe left or right keeps the audio only, a small drag does not, and TalkBack has it as an action`() {
        show(ask)
        compose.onRoot().performTouchInput { swipeLeft() }
        compose.waitForIdle()
        assertEquals(listOf("audio"), events)
        show(ask)
        compose.onRoot().performTouchInput { swipeRight() }
        compose.waitForIdle()
        assertEquals(listOf("audio", "audio"), events)

        val card = compose.onNodeWithText("Muneeb Quraishi").fetchSemanticsNode()
        var node = card
        while (node.parent != null && SemanticsActions.CustomActions !in node.config) node = node.parent!!
        val action = node.config[SemanticsActions.CustomActions].single()
        assertEquals("Save audio only", action.label)
        action.action()
        assertEquals(listOf("audio", "audio", "audio"), events)
    }

    @Test
    fun `each confirmation is one line with Undo, and Saved as audio offers Transcribe`() {
        show(CallCardScreen.Done(Pick.TRANSCRIBE))
        compose.onNodeWithText("Transcribing…").assertIsDisplayed()
        compose.onAllNodesWithText("Transcribe").assertCountEquals(0)
        compose.onNodeWithText("Undo").performClick()

        screen = CallCardScreen.Done(Pick.AUDIO)
        compose.onNodeWithText("Saved as audio").assertIsDisplayed()
        compose.onNodeWithText("Transcribe").performClick()

        screen = CallCardScreen.Done(Pick.DELETE)
        compose.onNodeWithText("Deleted").assertIsDisplayed()
        compose.onNodeWithText("Undo").performClick()
        compose.onAllNodesWithText("Swipe away to keep audio only").assertCountEquals(0)

        screen = CallCardScreen.Grouped(3)
        compose.onNodeWithText("3 calls saved as audio").assertIsDisplayed()
        compose.onNodeWithText("Review").performClick()
        assertEquals(listOf("undo", "transcribe", "undo", "review"), events)
    }

    @Test
    fun `the first card has its hint, and a later one may offer Always do this`() {
        show(ask.copy(hint = true))
        compose.onNodeWithText("You can change this in Settings › After each call.").assertIsDisplayed()
        screen = ask.copy(always = Pick.AUDIO)
        compose.onAllNodesWithText("You can change this in Settings › After each call.").assertCountEquals(0)
        compose.onNodeWithText("Always do this?").assertIsDisplayed()
        compose.onNodeWithText("Yes").performClick()
        assertEquals(listOf("always"), events)
    }

    /** Every button inside the card, none on another, at each size and language. */
    private fun assertFits(variant: String) {
        val root = compose.onRoot().getBoundsInRoot()
        val words = if (language == EchoLanguage.Urdu) listOf("حذف کریں", "ٹرانسکرپٹ بنائیں") else listOf("Delete", "Transcribe")
        val bounds = words.map { compose.onNodeWithText(it).assertIsDisplayed().getBoundsInRoot() }
        bounds.forEach { b -> assertTrue("$variant: a button leaves the screen", b.left >= root.left && b.right <= root.right) }
        val (a, b) = bounds
        val apart = a.right <= b.left || b.right <= a.left || a.bottom <= b.top || b.bottom <= a.top
        assertTrue("$variant: the buttons overlap", apart)
    }

    @Test
    fun `it fits a 360 dp phone at every text size, light and dark, English and Urdu, and is drawn beside the old card`() {
        out.mkdirs()
        for (oldCard in listOf(false, true)) {
            for (d in listOf(false, true)) {
                for (l in EchoLanguage.entries) {
                    for ((size, s) in listOf("small" to 0.85f, "normal" to 1f, "largest" to 2f)) {
                        dark = d
                        language = l
                        scale = s
                        show(ask, oldCard)
                        val variant = "$size-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}"
                        if (!oldCard) assertFits(variant)
                        compose.onRoot().captureRoboImage(File(out, "${if (oldCard) "old" else "new"}-$variant.png"))
                    }
                }
            }
        }
    }
}
