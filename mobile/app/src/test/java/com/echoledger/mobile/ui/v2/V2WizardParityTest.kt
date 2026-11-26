package com.echoledger.mobile.ui.v2

import android.Manifest
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isHeading
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.recording.MicTestResult
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.OnboardingScreen
import com.echoledger.mobile.ui.v2.setup.V2WizardTest
import com.echoledger.mobile.ui.v2.setup.WizardDesign
import com.echoledger.mobile.ui.v2.shell.DesignChoice
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking
import mockwebserver3.MockResponse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * The first-run wizard and the new design (parity.csv PH-OTH-039, the lead's decision): before sign-in it is classic's;
 * once signed in, the server's flags are read and, when they say the new design, the steps left (the test and Done) use
 * it; no answer within a few seconds is classic; a skipped sign-in stays classic and asks nothing.
 */
class V2WizardParityTest : ScreenTest() {
    @Before
    fun noChoice() = DesignChoice.set(app, null)

    private fun config(flags: String) = ServerFixtures.config().trimEnd().removeSuffix("}") + ""","ui_flags": $flags}"""

    private fun seen(words: String) = compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()

    private fun press(words: String) {
        compose.onAllNodes(hasText(words) and hasClickAction()).onFirst().performClick()
        compose.waitForIdle()
    }

    /** Signed in already: Get started and Continue go past the sign-in step to the test. */
    private fun toTheTest() {
        runBlocking { app.pairLocally(server) }
        pumpUntil("paired") { vm.settings.value.paired }
        show { OnboardingScreen(vm) }
        press("Get started")
        press("Continue")
    }

    /** The step's title is a heading in the new design; classic's is plain text. */
    private fun newDesign(title: String) = compose.onAllNodes(hasText(title) and isHeading()).fetchSemanticsNodes().isNotEmpty()

    @Test
    fun whenTheFlagsSayTheNewDesignTheStepsAfterSignInUseIt() {
        server.on("GET", "/api/config", FakeServer.json(200, config("""{"ui_v2.shell": true}""")))
        toTheTest()
        pumpUntil("the new test step") { newDesign("Try a 10-second test") }
        assertEquals(1, server.requestsTo("GET", "/api/config").size)
        press("Skip the test")
        pumpUntil("the new last step") { newDesign("You are set") }
        compose.onNode(isToggleable()).performClick()
        press("Start using EchoLedger")
        pumpUntil("finished") { runBlocking { app.graph.settings.current() }.let { it.onboardingDone && it.consentAcknowledged } }
    }

    @Test
    fun whenTheFlagsSayClassicTheWizardStaysClassic() {
        server.on("GET", "/api/config", FakeServer.json(200, config("""{"ui_v2.shell": false}""")))
        toTheTest()
        pumpUntil("the test step") { seen("Try a 10-second test") }
        pumpUntil("the flags were read") { server.requestsTo("GET", "/api/config").isNotEmpty() }
        compose.waitForIdle()
        assertFalse(newDesign("Try a 10-second test"))
    }

    @Test
    fun aServerThatDoesNotAnswerInTimeLeavesItClassic() {
        server.on(
            "GET",
            "/api/config",
            MockResponse.Builder().code(200).addHeader("Content-Type", "application/json")
                .body(config("""{"ui_v2.shell": true}""")).headersDelay(WizardDesign.WAIT_MS * 3, TimeUnit.MILLISECONDS).build()
        )
        toTheTest()
        val started = System.currentTimeMillis()
        pumpUntil("the classic test step") { seen("Try a 10-second test") }
        assertTrue("fell back within the wait", System.currentTimeMillis() - started < WizardDesign.WAIT_MS * 2)
        assertFalse(newDesign("Try a 10-second test"))
    }

    @Test
    fun aSkippedSignInStaysClassicAndAsksNothing() {
        show { OnboardingScreen(vm) }
        press("Get started")
        press("Continue")
        press("Skip for now")
        pumpUntil("the test step") { seen("Try a 10-second test") }
        assertFalse(newDesign("Try a 10-second test"))
        assertTrue(server.requestsTo("GET", "/api/config").isEmpty())
    }

    @Test
    fun theNewTestStepRecordsSaysWhatItHeardAndContinues() {
        shadowOf(app).grantPermissions(Manifest.permission.RECORD_AUDIO)
        var next = 0
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                V2WizardTest(onNext = { next++ }, onSkip = {}, record = { level ->
                    level(0.6f)
                    MicTestResult(0.7f, heard = true, samples = ShortArray(0))
                })
            }
        }
        press("Start the test")
        pumpUntil("the verdict") { seen("Your microphone is working.") }
        press("Continue")
        assertEquals(1, next)
    }

    @Test
    fun inUrduTheNewStepsAreInUrdu() {
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.Urdu) { V2WizardTest(onNext = {}, onSkip = {}) }
        }
        assertTrue(seen("10 سیکنڈ کا ٹیسٹ آزمائیں"))
        assertTrue(seen("مرحلہ 4 از 5"))
        assertTrue(seen("سیٹ اپ چھوڑیں"))
    }
}
