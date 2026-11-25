package com.echoledger.mobile.ui.v2

import android.provider.Settings
import androidx.compose.material3.Text
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.translate.LiveTranslateService
import com.echoledger.mobile.translate.LiveTranslateSession
import com.echoledger.mobile.translate.TranslateMode
import com.echoledger.mobile.translate.TranslateStatus
import com.echoledger.mobile.translate.TranslateToggleActivity
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.live.V2LiveTranslatePage
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * Game translate and Watch translate in the new design (parity.csv PH-OTH-030): Start opens the toggle for the mode,
 * On says the language and Stop stops the service; what it still needs, each with its fix; why the last start ended,
 * closable; Try it; the mode's settings; and the Live tab opens this page, not classic's.
 */
class V2LiveTranslateParityTest : ScreenTest() {
    private var setups = 0
    private var settingsOpened = 0

    @After
    fun stopped() {
        LiveTranslateService.running.value = null
        TranslateStatus.remember(app, null)
    }

    private fun page(mode: TranslateMode = TranslateMode.GAME, language: EchoLanguage = EchoLanguage.English) = show {
        EchoV2Theme(darkTheme = false, language = language) {
            CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                V2LiveTranslatePage(vm, mode, onBack = {}, onSetup = { setups++ }, onSettings = { settingsOpened++ })
            }
        }
    }

    private fun seen(words: String) = compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()

    private fun press(words: String) {
        val node = compose.onAllNodes((hasText(words) or hasContentDescription(words)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    @Test
    fun startOpensTheToggleForTheModeOnSaysTheLanguageAndStopStopsIt() {
        page()
        assertTrue(seen("Off"))
        press("Start")
        val started = shadowOf(app).nextStartedActivity
        assertEquals(TranslateToggleActivity::class.java.name, started.component?.className)
        assertEquals("game", started.getStringExtra(LiveTranslateService.EXTRA_MODE))
        LiveTranslateService.running.value = TranslateMode.GAME
        pumpUntil("on") { seen("On · translating to") }
        press("Stop")
        val stop = shadowOf(app).nextStartedService
        assertEquals(LiveTranslateService.ACTION_STOP, stop.action)
    }

    @Test
    fun eachMissingPermissionHasItsFixAndTheHelperOpensSetup() {
        org.robolectric.shadows.ShadowSettings.setCanDrawOverlays(false)
        page()
        assertTrue(seen("Before you start"))
        assertTrue(seen("Display over other apps"))
        compose.onAllNodes(hasText("Allow") and hasClickAction()).onFirst().performClick()
        assertEquals(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, shadowOf(app).nextStartedActivity.action)
        // This test phone has no capture helper: Set up opens Setup.
        assertTrue(seen("Needed to hear voice chat."))
        press("Set up")
        assertEquals(1, setups)
    }

    @Test
    fun whyTheLastStartEndedIsSaidAndClosesAndTheSettingsOpen() {
        TranslateStatus.remember(app, LiveTranslateSession.NO_CREDIT)
        page(TranslateMode.WATCH)
        pumpUntil("the reason") { seen("Needs credit. Live Translate stops by itself until this is fixed.") }
        press("Close")
        pumpUntil("closed") { !seen("Needs credit") }
        assertEquals(null, TranslateStatus.blocked(app))
        press("Video settings")
        assertEquals(1, settingsOpened)
        assertTrue(seen("Plays a short English line and subtitles it."))
        assertTrue(seen("About $0.18 an hour"))
    }

    @Test
    fun inUrduThePageIsInUrdu() {
        TranslateStatus.remember(app, LiveTranslateSession.NO_CREDIT)
        page(language = EchoLanguage.Urdu)
        pumpUntil("Urdu") { seen("گیم ترجمہ") && seen("کریڈٹ درکار ہے") }
        assertTrue(seen("شروع کریں"))
        assertTrue(seen("گیم کی ترتیبات"))
    }

    @Test
    fun theLiveTabOpensTheNewPage() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        show {
            EchoV2Shell(
                vm,
                UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true, "ui_v2.live" to true)),
                darkTheme = false,
                classic = ClassicScreens(
                    home = { Text("Classic activity") },
                    library = { Text("Classic library") },
                    live = { Text("Classic live") },
                    ask = { Text("Classic projects") },
                    settings = { Text("Classic settings") },
                    upgrade = {},
                    liveEntry = { entry, _ -> Text("Classic ${entry.name}") }
                ),
                onSwitchToClassic = {},
                language = EchoLanguage.English
            )
        }
        compose.onAllNodes(hasText("Live") and hasClickAction()).onFirst().performClick()
        compose.waitForIdle()
        press("Watch translate")
        pumpUntil("the new page") { seen("Video settings") }
        assertTrue(!seen("Classic WatchTranslate"))
    }
}
