package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.onAllNodesWithContentDescription
import com.echoledger.mobile.R
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.recording.PillState
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.live.V2RecordingPill
import com.echoledger.mobile.ui.v2.shell.OverlayLook
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import java.io.File
import kotlinx.coroutines.flow.MutableStateFlow
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Settings › Appearance's App language and Text size reach what v2 draws outside the app's window (docs/v2/audit/parity.csv
 * PH-SET-060 and 061): the recording pill, the after-call card and the Live Assist card speak the chosen language at the
 * chosen size, and change when the setting does.
 */
class OverlayLookTest : ScreenTest() {
    private val stored = MutableStateFlow(AppSettings(appLanguage = "ur", textSize = "largest"))
    private var fontScale by mutableStateOf(0f)

    @Test
    fun thePillSpeaksTheAppLanguageAtTheChosenSizeAndFollowsAChange() {
        val clock = "02:14"
        val urdu = V2Testing.resources(EchoLanguage.Urdu).getString(R.string.v2_live_pill_recording, clock)
        val english = V2Testing.resources(EchoLanguage.English).getString(R.string.v2_live_pill_recording, clock)
        show {
            OverlayLook(stored) {
                fontScale = LocalDensity.current.fontScale
                V2RecordingPill(PillState(elapsedMs = 134_000), 0, null, {})
            }
        }
        pumpUntil("Urdu") { compose.onAllNodesWithContentDescription(urdu, substring = true).fetchSemanticsNodes().isNotEmpty() }
        val phone = app.resources.configuration.fontScale
        assertEquals(phone * 1.3f, fontScale, 0.01f)
        stored.value = AppSettings(appLanguage = "en", textSize = "system")
        pumpUntil("English") { compose.onAllNodesWithContentDescription(english, substring = true).fetchSemanticsNodes().isNotEmpty() }
        assertEquals(phone, fontScale, 0.01f)
    }

    @Test
    fun theThreeWindowsOutsideTheAppDrawWithTheSettingsLook() {
        // The pill, the after-call card and the Live Assist card are each drawn inside OverlayLook with the stored settings.
        val overlay = File("src/main/java/com/echoledger/mobile/overlay")
        for ((file, drawn) in listOf(
            "ControlPill.kt" to "V2RecordingPill(",
            "DecisionPopup.kt" to "V2AfterCallCard(",
            "AssistCardWindow.kt" to "V2AssistHost("
        )) {
            val source = File(overlay, file).readText()
            val at = source.indexOf(drawn)
            assertTrue("$file draws $drawn", at > 0)
            val before = source.substring(0, at)
            assertTrue("$file wraps $drawn in OverlayLook", before.lastIndexOf("OverlayLook(") > before.lastIndexOf("EchoV2Theme("))
            assertTrue("$file reads the stored settings", source.contains("overlaySettings("))
        }
    }
}
