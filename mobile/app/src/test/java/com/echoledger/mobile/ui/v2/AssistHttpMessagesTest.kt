package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.unit.LayoutDirection
import androidx.test.core.app.ApplicationProvider
import com.echoledger.mobile.R
import com.echoledger.mobile.assist.AssistHub
import com.echoledger.mobile.assist.HttpLiveClient
import com.echoledger.mobile.ui.v2.assist.AssistCard
import com.echoledger.mobile.ui.v2.assist.AssistMessage
import com.echoledger.mobile.ui.v2.assist.AssistProblem
import com.echoledger.mobile.ui.v2.assist.LiveMode
import com.echoledger.mobile.ui.v2.assist.V2AssistCard
import com.echoledger.mobile.ui.v2.assist.V2AssistStart
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
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

/**
 * Live Assist's HTTP-mode messages (contract 1.9.0-q) in the screen's own words: the card's problem for each refusal the
 * phone's HTTP client reports (426 update the app, 503 can't connect right now, a message that couldn't reach the
 * server, 429 too many requests), and the start screen's for 426 and 503. Pictures: the card for each, light and dark,
 * English and Urdu, beside a note where the classic screen would be (Live Assist has none).
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class AssistHttpMessagesTest {
    @get:Rule
    val compose = createComposeRule()

    private val res get() = ApplicationProvider.getApplicationContext<Application>().resources

    private val outDir =
        File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone/live-assist")

    @Before
    fun setUp() = AssistHub.reset()

    @After
    fun tearDown() = AssistHub.reset()

    /** Each HTTP-mode error as the client sends it to the card, with its English and Urdu words. */
    private data class Case(val name: String, val error: AssistMessage.Error, val problem: AssistProblem, val en: String, val ur: String)

    private val cases = listOf(
        Case(
            "update-app",
            AssistMessage.Error("app_update_required", AssistHub.UPDATE_APP, false, null, true),
            AssistProblem.UpdateApp,
            "Live Assist needs the latest version of the app.",
            "لائیو اسسٹ کے لیے ایپ کا تازہ ترین ورژن چاہیے۔"
        ),
        Case(
            "cant-connect",
            AssistMessage.Error("relay_unavailable", AssistHub.RELAY_DOWN, true, null, false),
            AssistProblem.CantConnect,
            "Live Assist can't connect right now. Try again in a few minutes.",
            "لائیو اسسٹ ابھی رابطہ نہیں کر پا رہا۔ چند منٹ بعد دوبارہ کوشش کریں۔"
        ),
        Case(
            "unreachable",
            AssistMessage.Error("connection_failed", HttpLiveClient.CONNECTION_FAILED, true, null, false),
            AssistProblem.Unreachable,
            "Live Assist couldn't reach the server. Try again.",
            "لائیو اسسٹ سرور تک نہیں پہنچ سکا۔ دوبارہ کوشش کریں۔"
        ),
        Case(
            "too-many",
            AssistMessage.Error("rate_limited", HttpLiveClient.TOO_FAST, true, null, false),
            AssistProblem.TooFast,
            "Too many requests. Wait a moment and try again.",
            "بہت زیادہ درخواستیں۔ تھوڑا رک کر دوبارہ کوشش کریں۔"
        )
    )

    private val ready = AssistMessage.Ready("s1", LiveMode.Balanced, listOf(LiveMode.Balanced), resumed = false, meetingType = null)

    /** The card after the client's error; 426 also ends the session (the client ends it), so the card says so. */
    private fun card(case: Case) =
        AssistCard.reduce(listOfNotNull(ready, case.error, AssistMessage.Ended.takeIf { case.problem == AssistProblem.UpdateApp }))

    @Test
    fun eachRefusalIsItsOwnProblemOnTheCard() {
        cases.forEach { assertEquals(it.name, it.problem, card(it).problem) }
    }

    @Test
    fun theCardSaysEachInTheScreensLanguage() {
        var case by mutableStateOf(cases.first())
        var language by mutableStateOf(EchoLanguage.English)
        compose.setContent { EchoV2Theme(darkTheme = true, language = language) { V2AssistCard(card(case), true, {}, {}, {}) } }
        for (c in cases) {
            for (l in EchoLanguage.entries) {
                case = c
                language = l
                compose.waitForIdle()
                compose.onNodeWithText(if (l == EchoLanguage.Urdu) c.ur else c.en).assertIsDisplayed()
                // An app too old ends the session: the card says it ended and offers nothing to tap.
                val help = res.getString(R.string.v2_assist_help_now)
                if (c.problem == AssistProblem.UpdateApp) {
                    if (l == EchoLanguage.English) compose.onNodeWithText(res.getString(R.string.v2_assist_ended)).assertIsDisplayed()
                    compose.onAllNodesWithText(help).assertCountEquals(0)
                    compose.onAllNodesWithText("ابھی مدد کریں").assertCountEquals(0)
                }
            }
        }
    }

    @Test
    fun theStartScreenSaysUpdateAndCantConnectInUrduToo() {
        var result by mutableStateOf<AssistHub.Start>(AssistHub.Start.UpdateApp)
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.Urdu) {
                V2AssistStart(onBack = {}, onRecord = {}, start = { result }, cardAllowed = { true }, showCard = {}, onAllowCard = {})
            }
        }
        AssistHub.recordingSession("rec-1")
        compose.waitForIdle()
        val startNow = "ابھی شروع کریں"
        fun tapStart() {
            compose.onNodeWithText(startNow, substring = true).performClick()
            compose.waitForIdle()
        }
        tapStart()
        compose.onNodeWithText(cases[0].ur).assertIsDisplayed()
        result = AssistHub.Start.CantConnect
        tapStart()
        compose.onNodeWithText(cases[1].ur).assertIsDisplayed()
    }

    /** Where the classic half of a side-by-side picture would be: Live Assist has no classic screen. */
    @Composable
    private fun NoClassic() = Box(
        Modifier.background(EchoV2.colors.bgSubtle).padding(EchoTokens.Space.s16)
    ) {
        // English for whoever reads the picture, left to right on an Urdu screen too.
        Text(
            "Classic: no screen. Live Assist is new in v2.",
            style = EchoV2.type.meta.copy(textDirection = TextDirection.Ltr),
            color = EchoV2.colors.textSecondary
        )
    }

    @Test
    fun pictures() {
        var case by mutableStateOf(cases.first())
        var dark by mutableStateOf(false)
        var language by mutableStateOf(EchoLanguage.English)
        compose.setContent {
            EchoV2Theme(darkTheme = dark, language = language) {
                // Classic on the left and v2 on the right in every language; the card keeps the screen's direction.
                val screen = LocalLayoutDirection.current
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Row(
                        Modifier.fillMaxWidth().background(EchoV2.colors.bgCanvas).padding(EchoTokens.Space.s16).testTag("shot"),
                        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
                    ) {
                        Box(Modifier.weight(1f)) { NoClassic() }
                        Box(Modifier.weight(2f)) {
                            CompositionLocalProvider(LocalLayoutDirection provides screen) { V2AssistCard(card(case), true, {}, {}, {}) }
                        }
                    }
                }
            }
        }
        for (c in cases) {
            for (d in listOf(false, true)) {
                for (l in EchoLanguage.entries) {
                    case = c
                    dark = d
                    language = l
                    compose.waitForIdle()
                    val file = "http-${c.name}-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}.png"
                    compose.onNodeWithTag("shot").captureRoboImage(File(outDir, file))
                }
            }
        }
    }
}
