package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.AppearancePage
import com.echoledger.mobile.ui.v2.shell.Design
import com.echoledger.mobile.ui.v2.shell.DesignChoice
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The design switch both ways (docs/PLAN_V2_PARITY.md section 0): classic is the default; classic › Settings ›
 * Appearance › "Try the new design" turns every phone v2 screen on, and the new design's "Switch back to classic" turns
 * them all off again, whatever the server's flags say.
 */
class DesignSwitchTest : ScreenTest() {
    @After
    fun forget() = DesignChoice.set(app, null)

    private fun press(text: String) {
        val node = compose.onAllNodes(hasText(text) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    @Test
    fun classicIsTheDefault() {
        DesignChoice.load(app)
        assertNull(DesignChoice.current)
        assertFalse(UiFlags().shell)
        assertFalse(UiFlags().library)
    }

    @Test
    fun classicAppearanceOffersTheNewDesign() {
        show { SettingsScreen(vm, onSetup = {}, openPage = "appearance") }
        press("Try the new design")
        assertEquals(Design.V2, DesignChoice.current)
        // Every phone v2 screen is on, with no server flag at all; the choice is kept for the next start.
        val flags = UiFlags()
        for (key in UiFlags.PHONE_V2) assertTrue(key, flags.isOn(key))
        assertTrue(flags.offersClassicSwitch)
        DesignChoice.load(app)
        assertEquals(Design.V2, DesignChoice.current)
    }

    @Test
    fun theNewDesignSwitchesBackToClassic() {
        DesignChoice.set(app, Design.V2)
        val switched = mutableListOf<Boolean>()
        show {
            val settings by vm.settings.collectAsState()
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    AppearancePage(settings, vm::updateSettings, UiFlags(), { classic ->
                        switched += classic
                        DesignChoice.set(app, if (classic) Design.Classic else Design.V2)
                    }, null, {})
                }
            }
        }
        press("Switch back to classic")
        assertEquals(listOf(true), switched)
        assertEquals(Design.Classic, DesignChoice.current)
        // Classic wins over the server's flags, as "Switch back to classic" did with ui_classic.
        val server = UiFlags(UiFlags.PHONE_V2.associateWith { true })
        for (key in UiFlags.PHONE_V2) assertFalse(key, server.isOn(key))
        assertTrue(server.chosenClassic)
        // Live Assist is not a design: it follows the server.
        assertTrue(UiFlags(mapOf(UiFlags.LIVE_ASSIST to true)).isOn(UiFlags.LIVE_ASSIST))
    }

    @Test
    fun withoutAChoiceTheServersFlagsDecide() {
        assertNull(DesignChoice.current)
        assertTrue(UiFlags(mapOf(UiFlags.SHELL to true)).shell)
        assertFalse(UiFlags(mapOf(UiFlags.SHELL to true), classic = true).shell)
    }

    private val v2Flags = UiFlags.PHONE_V2.joinToString(",") { "\"$it\": true" }

    @Test
    fun theServerKeepsTheChoiceWhenItHasTheSwitch() {
        kotlinx.coroutines.runBlocking { app.pairLocally(server) }
        server.on(
            "PUT",
            "/api/account/ui",
            FakeServer.json(
                200,
                """{"ui_flags": {$v2Flags}, "ui_classic": false,
                   "ui_design": {"choice": "v2", "current": "v2", "can_try": false, "can_switch_back": true}}"""
            )
        )
        val answer = kotlinx.coroutines.runBlocking { DesignChoice.choose(app, vm, Design.V2) }
        assertEquals(com.echoledger.mobile.ui.MainViewModel.DesignAnswer.Taken, answer)
        val body = server.requestsTo("PUT", "/api/account/ui").single().bodyText()
        assertEquals("""{"design":"v2"}""", body)
        // The server's answer decides; nothing is kept on this phone.
        assertNull(DesignChoice.current)
        val settings = kotlinx.coroutines.runBlocking { app.graph.settings.current() }
        val flags = UiFlags(settings.uiFlags, settings.uiClassic, DesignChoice.current, settings.uiDesign)
        assertTrue(flags.shell)
        assertTrue(flags.offersClassicSwitch)
        assertFalse(DesignChoice.offersTry(settings.uiDesign))
    }

    @Test
    fun nothingToTryIsSaidAndChangesNothing() {
        kotlinx.coroutines.runBlocking { app.pairLocally(server) }
        val refused = FakeServer.json(422, """{"code": "design_not_available", "message": "No new design to try."}""")
        server.on("PUT", "/api/account/ui", refused)
        val answer = kotlinx.coroutines.runBlocking { DesignChoice.choose(app, vm, Design.V2) }
        assertEquals(com.echoledger.mobile.ui.MainViewModel.DesignAnswer.NotAvailable, answer)
        assertNull(DesignChoice.current)
        assertFalse(UiFlags().shell)
    }

    @Test
    fun anOlderServerLeavesTheChoiceOnThisPhone() {
        kotlinx.coroutines.runBlocking { app.pairLocally(server) }
        val older = FakeServer.json(422, """{"code": "validation_failed", "message": "classic is required"}""")
        server.on("PUT", "/api/account/ui", older)
        val answer = kotlinx.coroutines.runBlocking { DesignChoice.choose(app, vm, Design.Classic) }
        assertEquals(com.echoledger.mobile.ui.MainViewModel.DesignAnswer.OnThisPhone, answer)
        assertEquals(Design.Classic, DesignChoice.current)
        assertFalse(UiFlags(mapOf(UiFlags.SHELL to true)).shell)
    }

    @Test
    fun classicOffersTheNewDesignOnlyWhileTheServerHasOneToTry() {
        val offer = com.echoledger.mobile.data.UiDesignDto(current = "classic", canTry = true)
        assertTrue(DesignChoice.offersTry(offer))
        assertFalse(DesignChoice.offersTry(offer.copy(canTry = false)))
        assertTrue("an older server", DesignChoice.offersTry(null))
        DesignChoice.set(app, Design.V2)
        assertFalse("already on the new design", DesignChoice.offersTry(null))
    }
}
