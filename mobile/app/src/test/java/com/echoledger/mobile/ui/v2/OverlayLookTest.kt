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
import kotlinx.coroutines.cancel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.stateIn
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Settings › Appearance's App language and Text size reach what v2 draws outside the app's window (docs/v2/audit/parity.csv
 * PH-SET-060 and 061): the recording pill, the after-call card and the Live Assist card speak the chosen language at the
 * chosen size, and change when the setting does.
 */
class OverlayLookTest : ScreenTest() {
    private val stored = MutableStateFlow<AppSettings?>(AppSettings(appLanguage = "ur", textSize = "largest"))
    private var fontScale by mutableStateOf(0f)

    @Test
    fun thePillSpeaksTheAppLanguageAtTheChosenSizeAndFollowsAChange() {
        val clock = "02:14"
        val urdu = V2Testing.resources(EchoLanguage.Urdu).getString(R.string.v2_live_pill_recording, clock)
        val english = V2Testing.resources(EchoLanguage.English).getString(R.string.v2_live_pill_recording, clock)
        val scales = mutableListOf<Float>()
        show {
            OverlayLook(stored) {
                fontScale = LocalDensity.current.fontScale
                scales += fontScale
                V2RecordingPill(PillState(elapsedMs = 134_000), 0, null, {})
            }
        }
        pumpUntil("Urdu") { compose.onAllNodesWithContentDescription(urdu, substring = true).fetchSemanticsNodes().isNotEmpty() }
        val phone = app.resources.configuration.fontScale
        assertEquals(phone * 1.3f, fontScale, 0.01f)
        // From its first frame: it starts from the app's hot copy of the settings, never the phone's size first.
        assertEquals(phone * 1.3f, scales.first(), 0.01f)
        stored.value = AppSettings(appLanguage = "en", textSize = "system")
        pumpUntil("English") { compose.onAllNodesWithContentDescription(english, substring = true).fetchSemanticsNodes().isNotEmpty() }
        assertEquals(phone, fontScale, 0.01f)
    }

    @Test
    fun windowsOutsideTheAppUseTheAppsFontEvenWithThePhonesFontOnAndTheCardFollowsTheTheme() {
        // The after-call card in a handwritten phone font was hard to read over other apps (the call-choice plan, 2.0.5).
        stored.value = AppSettings(phoneFont = true)
        var face: androidx.compose.ui.text.font.FontFamily? = null
        var dark: Boolean? = null
        var light by mutableStateOf(false)
        show {
            OverlayLook(stored, dark = !light) {
                face = com.echoledger.mobile.ui.v2.theme.EchoV2.type.body.fontFamily
                dark =
                    com.echoledger.mobile.ui.v2.theme.EchoV2.colors.bgCanvas == com.echoledger.mobile.ui.v2.theme.EchoV2Colors.Dark.bgCanvas
            }
        }
        pumpUntil("drawn") { face != null }
        assertEquals(com.echoledger.mobile.ui.v2.theme.EchoV2Type.of(EchoLanguage.English, phoneFont = false).body.fontFamily, face)
        assertEquals(true, dark)
        light = true
        pumpUntil("light") { dark == false }
    }

    private fun pillShown() = compose.onAllNodesWithContentDescription("02:14", substring = true).fetchSemanticsNodes().isNotEmpty()

    @Test
    fun atAColdStartThePillWaitsForTheSettingsInsteadOfShowingThePhonesLook() {
        // A cold store, slow to answer (a process a call has just started), made hot as the app makes it.
        val read = java.util.concurrent.atomic.AtomicBoolean(false)
        val cold = kotlinx.coroutines.flow.flow {
            kotlinx.coroutines.delay(SLOW_STORE_MS)
            read.set(true)
            emit(AppSettings(appLanguage = "ur", textSize = "largest"))
        }
        val scope = kotlinx.coroutines.CoroutineScope(kotlinx.coroutines.SupervisorJob() + kotlinx.coroutines.Dispatchers.Default)
        val hot = cold.stateIn(scope, kotlinx.coroutines.flow.SharingStarted.Eagerly, null)
        val scales = mutableListOf<Float>()
        compose.mainClock.autoAdvance = false
        val started = System.nanoTime()
        show {
            OverlayLook(hot) {
                scales += LocalDensity.current.fontScale
                V2RecordingPill(PillState(elapsedMs = 134_000), 0, null, {})
            }
        }
        repeat(3) { compose.mainClock.advanceTimeByFrame() }
        val firstFramesMs = (System.nanoTime() - started) / 1_000_000
        // The first frames came at once, while the store was still being read: nothing waited for it...
        assertTrue("drawn while the store was still being read", !read.get())
        assertTrue("first frames after $firstFramesMs ms", firstFramesMs < SLOW_STORE_MS)
        // ...and drew nothing rather than the phone's look.
        assertTrue(scales.isEmpty())
        assertTrue(!pillShown())
        // Then the person's language and size, from its first frame.
        pumpUntil("read") { read.get() }
        val urdu = V2Testing.resources(EchoLanguage.Urdu).getString(R.string.v2_live_pill_recording, "02:14")
        pumpUntil("Urdu") {
            compose.mainClock.advanceTimeByFrame()
            compose.onAllNodesWithContentDescription(urdu, substring = true).fetchSemanticsNodes().isNotEmpty()
        }
        val phone = app.resources.configuration.fontScale
        assertTrue("never the phone's size: $scales", scales.all { kotlin.math.abs(it - phone * 1.3f) < 0.01f })
        scope.cancel()
    }

    @Test
    fun aStoreThatNeverAnswersStillShowsThePillInThePhonesLook() {
        val never = MutableStateFlow<AppSettings?>(null)
        val scales = mutableListOf<Float>()
        compose.mainClock.autoAdvance = false
        show {
            OverlayLook(never) {
                scales += LocalDensity.current.fontScale
                V2RecordingPill(PillState(elapsedMs = 134_000), 0, null, {})
            }
        }
        compose.mainClock.advanceTimeBy(com.echoledger.mobile.ui.v2.shell.OVERLAY_WAIT_MS - 100)
        assertTrue(!pillShown())
        compose.mainClock.advanceTimeBy(200)
        compose.mainClock.advanceTimeByFrame()
        assertTrue(pillShown())
        assertEquals(app.resources.configuration.fontScale, scales.last(), 0.01f)
    }

    @Test
    fun aWindowAskedForBeforeTheFirstReadAsksTheStoreForIt() {
        val live = com.echoledger.mobile.ui.v2.shell.overlaySettings(app)
        assertTrue(live === app.graph.liveSettings)
        pumpUntil("read") { app.graph.liveSettings.value != null }
    }

    @Test
    fun noWindowOutsideTheAppBlocksTheMainThreadForTheSettings() {
        val look = File("src/main/java/com/echoledger/mobile/ui/v2/shell/AppLook.kt").readText()
        assertTrue(!look.contains("runBlocking"))
        // The settings are read as the app starts, off the main thread, for the windows that open next.
        val start = File("src/main/java/com/echoledger/mobile/EchoLedgerApp.kt").readText()
            .substringAfter("override fun onCreate()").substringBefore("\n    }\n")
        assertTrue(start, start.contains("graph.settings.current()") && start.contains("Dispatchers.IO"))
    }

    @Test
    fun theAppsHotCopyFollowsTheStore() {
        vm.updateSettings { it.copy(appLanguage = "ur", textSize = "larger") }
        pumpUntil("followed") { app.graph.liveSettings.value?.let { it.appLanguage == "ur" && it.textSize == "larger" } == true }
        // A save reaches it at once, without waiting for anything to read the store.
        kotlinx.coroutines.runBlocking { app.graph.settings.update { it.copy(textSize = "smaller") } }
        assertEquals("smaller", app.graph.liveSettings.value?.textSize)
        assertTrue(com.echoledger.mobile.ui.v2.shell.overlaySettings(app) === app.graph.liveSettings)
    }

    @Test
    fun theThreeWindowsOutsideTheAppDrawWithTheSettingsLook() {
        // The pill, the after-call card, the start banner and the Live Assist card are each drawn inside OverlayLook with
        // the stored settings.
        val overlay = File("src/main/java/com/echoledger/mobile/overlay")
        for ((file, drawn) in listOf(
            "ControlPill.kt" to "V2RecordingPill(",
            "DecisionPopup.kt" to "CallChoiceCard(",
            "StartBanner.kt" to "StartBannerContent(",
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

    private companion object {
        const val SLOW_STORE_MS = 1_000L
    }
}
