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
import androidx.compose.ui.test.onNodeWithContentDescription
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

    /**
     * WhatsApp as if it were installed, with a flat stand-in of its mark (the desktop's SourceLogos paths: its bubble and
     * handset on its green), so the card shows an app's own icon as it does on a phone that has the app.
     */
    @org.junit.Before
    fun installWhatsApp() {
        val app = androidx.test.core.app.ApplicationProvider.getApplicationContext<android.app.Application>()
        val pm = org.robolectric.Shadows.shadowOf(app.packageManager)
        pm.installPackage(
            android.content.pm.PackageInfo().apply {
                packageName = com.echoledger.mobile.core.CallTargets.WHATSAPP
                applicationInfo =
                    android.content.pm.ApplicationInfo().apply { packageName = com.echoledger.mobile.core.CallTargets.WHATSAPP }
            }
        )
        val size = 96
        val mark = android.graphics.Bitmap.createBitmap(size, size, android.graphics.Bitmap.Config.ARGB_8888)
        android.graphics.Canvas(mark).apply {
            scale(size / 24f, size / 24f)
            val paint = android.graphics.Paint(android.graphics.Paint.ANTI_ALIAS_FLAG)
            paint.color = android.graphics.Color.parseColor("#25D366")
            drawCircle(12f, 12f, 12f, paint)
            paint.color = android.graphics.Color.WHITE
            paint.style = android.graphics.Paint.Style.STROKE
            paint.strokeWidth = 1.5f
            paint.strokeJoin = android.graphics.Paint.Join.ROUND
            drawPath(androidx.core.graphics.PathParser.createPathFromPathData(BUBBLE), paint)
            paint.style = android.graphics.Paint.Style.FILL
            drawPath(androidx.core.graphics.PathParser.createPathFromPathData(HANDSET), paint)
        }
        pm.setApplicationIcon(
            com.echoledger.mobile.core.CallTargets.WHATSAPP,
            android.graphics.drawable.BitmapDrawable(app.resources, mark)
        )
        com.echoledger.mobile.ui.design.AppIcons.forget()
    }

    /** Waits for the installed icon, loaded off the main thread, before a picture is taken. */
    private fun awaitLogo() {
        val px = with(compose.density) { com.echoledger.mobile.ui.v2.tokens.EchoTokens.Component.avatarSize.roundToPx() }
        compose.waitUntil(5_000) { com.echoledger.mobile.ui.design.AppIcons.cached("v2-source:WHATSAPP:$px") != null }
        compose.waitForIdle()
    }
    private var screen by mutableStateOf<CallCardScreen>(ask)
    private var old by mutableStateOf(false)

    /** Something else of the call choice to draw instead of the card (the start banner, the pill menu). */
    private var other by mutableStateOf<(@androidx.compose.runtime.Composable () -> Unit)?>(null)
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
        other?.let {
            it()
            return
        }
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
        // The length is isolated (FSI…PDI) inside the line, in every language.
        compose.onNodeWithText("\u20680:44\u2069 · just now").assertIsDisplayed()
        compose.onNodeWithText("Swipe away to keep audio only").assertIsDisplayed()
        compose.onNodeWithText("Delete").performClick()
        compose.onNodeWithText("Transcribe").performClick()
        assertEquals(listOf("delete", "transcribe"), events)
        listOf("Keep", "Skip", "Don't keep", "Save audio").forEach { compose.onAllNodesWithText(it).assertCountEquals(0) }
    }

    @Test
    fun `the card shows the app's own logo, a Latin name in the Latin face in Urdu, and the length isolated`() {
        language = EchoLanguage.Urdu
        show(ask)
        awaitLogo()
        compose.onNodeWithContentDescription("واٹس ایپ", substring = true).assertExists()
        val meta = compose.onNodeWithText("0:44", substring = true).fetchSemanticsNode()
            .config[androidx.compose.ui.semantics.SemanticsProperties.Text].single()
        assertEquals("\u20680:44\u2069 · ابھی", meta.text)
        val latin = meta.spanStyles.single { it.item.fontFamily != null }
        assertEquals(
            "the length, the dot and its spaces are in the Latin face",
            "\u20680:44\u2069 · ",
            meta.text.substring(latin.start, latin.end)
        )
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

    /**
     * The rest of the call choice for VISUAL-OK, light and dark, English and Urdu, at the phone's text size: the three
     * confirmation lines, the grouped line, the start banner and a call's pill menu (new-<part>-<theme>-<lang>.png).
     */
    @Test
    fun `the lines, the grouped line, the start banner and the pill menu are drawn for review`() {
        out.mkdirs()
        val parts: List<Pair<String, (@androidx.compose.runtime.Composable () -> Unit)?>> = listOf(
            "line-transcribing" to null,
            "line-audio" to null,
            "line-deleted" to null,
            "grouped" to null,
            "start-banner" to {
                com.echoledger.mobile.overlay.StartBannerContent(onDontRecord = {}, modifier = Modifier.padding(horizontal = 16.dp))
            },
            "pill-menu" to {
                Box(Modifier.padding(horizontal = 16.dp)) {
                    com.echoledger.mobile.ui.v2.live.V2RecordingPill(
                        com.echoledger.mobile.recording.PillState(
                            mode = com.echoledger.mobile.recording.PillState.Mode.MENU,
                            elapsedMs = 44_000,
                            call = true,
                            choice = Pick.AUDIO.wire
                        ),
                        0,
                        "Muneeb Quraishi",
                        {}
                    )
                }
            }
        )
        val screens = mapOf(
            "line-transcribing" to CallCardScreen.Done(Pick.TRANSCRIBE),
            "line-audio" to CallCardScreen.Done(Pick.AUDIO),
            "line-deleted" to CallCardScreen.Done(Pick.DELETE),
            "grouped" to CallCardScreen.Grouped(3)
        )
        for ((name, draw) in parts) {
            for (d in listOf(false, true)) {
                for (l in EchoLanguage.entries) {
                    dark = d
                    language = l
                    scale = 1f
                    other = draw
                    show(screens[name] ?: ask)
                    val variant = "$name-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}"
                    compose.onRoot().captureRoboImage(File(out, "new-$variant.png"))
                }
            }
        }
        other = null
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
                        awaitLogo()
                        val variant = "$size-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}"
                        if (!oldCard) assertFits(variant)
                        compose.onRoot().captureRoboImage(File(out, "${if (oldCard) "old" else "new"}-$variant.png"))
                    }
                }
            }
        }
    }

    private companion object {
        /** WhatsApp's bubble and handset on a 24-unit tile (desktop/src/components/SourceLogos.tsx). */
        const val BUBBLE = "M12 4.6a7.4 7.4 0 0 0-6.4 11.1L4.6 19.4l3.8-1a7.4 7.4 0 1 0 3.6-13.8z"
        const val HANDSET = "M9.3 7.6c.2 0 .4 0 .5.4l.7 1.7c.1.2 0 .4-.1.6l-.5.6c-.1.1-.1.3 0 .5.4.7.9 1.3 1.5 1.8.5.4 1 .7 " +
            "1.6 1 .2.1.4 0 .5-.1l.6-.7c.2-.2.4-.2.6-.1l1.6.8c.2.1.3.3.3.5 0 .5-.2 1-.6 1.3-.5.4-1.1.6-1.7.5-1.1-.2-2.2-.7-3.1-1.4" +
            "-1-.8-1.9-1.8-2.5-2.9-.4-.7-.5-1.5-.3-2.2.2-.4.4-.8.8-1.1.2-.2.4-.2.6-.2z"
    }
}
