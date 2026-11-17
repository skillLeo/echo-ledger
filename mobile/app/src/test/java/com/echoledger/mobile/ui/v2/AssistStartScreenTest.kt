package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.test.core.app.ApplicationProvider
import com.echoledger.mobile.R
import com.echoledger.mobile.assist.AssistHub
import com.echoledger.mobile.ui.v2.assist.AssistStartUi
import com.echoledger.mobile.ui.v2.assist.V2AssistStart
import com.echoledger.mobile.ui.v2.assist.V2AssistStartScreen
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/** Live Assist's start screen: what each state offers, what its buttons do, and how it looks (light, dark, Urdu). */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class AssistStartScreenTest {
    @get:Rule
    val compose = createComposeRule()

    private val res get() = ApplicationProvider.getApplicationContext<Application>().resources

    private val outDir = File("build/screenshots/assist-start")

    private val taps = mutableListOf<String>()

    @Before
    fun setUp() = AssistHub.reset()

    @After
    fun tearDown() = AssistHub.reset()

    private fun show(dark: Boolean = false, language: EchoLanguage = EchoLanguage.English, content: @Composable () -> Unit) =
        compose.setContent { EchoV2Theme(darkTheme = dark, language = language, content = content) }

    @Composable
    private fun Screen(ui: AssistStartUi) = V2AssistStartScreen(
        ui = ui,
        onBack = { taps += "back" },
        onRecord = { taps += "record" },
        onStart = { endOther -> taps += if (endOther) "end other and start" else "start" },
        onShowCard = { taps += "show card" },
        onAllowCard = { taps += "allow card" },
        onEnd = { taps += "end" }
    )

    @Test
    fun withoutARecordingItOffersToRecord() {
        show { Screen(AssistStartUi.NeedsRecording) }
        compose.onNodeWithText(res.getString(R.string.v2_assist_start_needs_recording)).assertIsDisplayed()
        compose.onNodeWithText(res.getString(R.string.v2_assist_start_record)).performClick()
        assertEquals(listOf("record"), taps)
    }

    @Test
    fun onARecordingItShowsTheConsentLineAndStarts() {
        var ui by mutableStateOf<AssistStartUi>(AssistStartUi.CanStart())
        show { Screen(ui) }
        compose.onNodeWithText(res.getString(R.string.v2_assist_consent)).assertIsDisplayed()
        compose.onNodeWithText(res.getString(R.string.v2_assist_start_now)).performClick()
        ui = AssistStartUi.CanStart(busy = true)
        compose.onNodeWithText(res.getString(R.string.v2_assist_start_busy)).assertIsDisplayed()
        compose.onNodeWithText(res.getString(R.string.v2_assist_start_end_other)).performClick()
        ui = AssistStartUi.CanStart(problem = "Can't reach the server. Check the connection and try again.")
        compose.onNodeWithText("Can't reach the server. Check the connection and try again.").assertIsDisplayed()
        assertEquals(listOf("start", "end other and start"), taps)
    }

    @Test
    fun whileOnItShowsTheCardOrAsksToAllowItAndEnds() {
        var ui by mutableStateOf<AssistStartUi>(AssistStartUi.On(cardAllowed = true))
        show { Screen(ui) }
        compose.onNodeWithText(res.getString(R.string.v2_assist_start_on)).assertIsDisplayed()
        compose.onNodeWithText(res.getString(R.string.v2_assist_start_show_card)).performClick()
        ui = AssistStartUi.On(cardAllowed = false)
        compose.onNodeWithText(res.getString(R.string.v2_assist_start_allow)).performClick()
        compose.onNodeWithText(res.getString(R.string.v2_assist_start_end)).performClick()
        assertEquals(listOf("show card", "allow card", "end"), taps)
    }

    @Test
    fun theHubDecidesWhatTheScreenOffersAndAStartThatCantHappenSaysWhy() {
        show {
            V2AssistStart(
                onBack = {},
                onRecord = { taps += "record" },
                start = { AssistHub.Start.NotSetUp },
                cardAllowed = { true },
                showCard = { taps += "show card" },
                onAllowCard = {}
            )
        }
        compose.onNodeWithText(res.getString(R.string.v2_assist_start_record)).assertIsDisplayed()
        AssistHub.recordingSession("rec-1")
        compose.waitForIdle()
        compose.onNodeWithText(res.getString(R.string.v2_assist_start_now)).performClick()
        compose.waitForIdle()
        compose.onNodeWithText(res.getString(R.string.v2_assist_error_not_set_up)).assertIsDisplayed()
        assertEquals(emptyList<String>(), taps)
    }

    @Test
    fun screenshots() {
        var ui by mutableStateOf<AssistStartUi>(AssistStartUi.CanStart())
        var dark by mutableStateOf(false)
        var language by mutableStateOf(EchoLanguage.English)
        compose.setContent { EchoV2Theme(darkTheme = dark, language = language) { Screen(ui) } }
        val states = listOf(
            "start" to AssistStartUi.CanStart(),
            "needs-recording" to AssistStartUi.NeedsRecording,
            "on" to AssistStartUi.On(true)
        )
        for ((name, state) in states) {
            for (d in listOf(false, true)) {
                for (l in EchoLanguage.entries) {
                    ui = state
                    dark = d
                    language = l
                    compose.waitForIdle()
                    val file = "assist-$name-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}.png"
                    compose.onRoot().captureRoboImage(File(outDir, file))
                }
            }
        }
    }
}
