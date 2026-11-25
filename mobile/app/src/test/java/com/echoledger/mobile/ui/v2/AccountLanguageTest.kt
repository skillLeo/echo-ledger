package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.settings.AppearancePage
import com.echoledger.mobile.ui.v2.shell.AppLook
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * One language everywhere (BE-06, the desktop's Urdu Phase 0): signed in, the phone speaks the account's `language`
 * (GET /api/account), else the last one known on this phone while the account loads or can't be read, never the
 * phone's own; Settings › Appearance › App language then sets the account's (PATCH /api/account), for every device.
 * Signed out, the choice made here, which may follow the phone. The account's avatar colour is kept too (DES-08).
 */
class AccountLanguageTest : ScreenTest() {
    private fun sample(name: String) = File("../../docs/api-samples/$name.json").readText()

    /** A sample account in [language] with avatar colour [colour]. */
    private fun account(language: String, colour: Int = 3) = sample("account")
        .replace(Regex("\"language\":\\s*\"[a-z]+\""), "\"language\": \"$language\"")
        .replace(Regex("\"avatar_color\":\\s*\\d+"), "\"avatar_color\": $colour")

    private val signedIn = AppSettings(serverUrl = "https://echoledger.test", deviceId = "d1")

    @Test
    fun signedInTheAccountsLanguageWinsAndNeverThePhones() {
        assertEquals("ur", AppLook.choice(signedIn.copy(accountLanguage = "ur")))
        assertEquals("en", AppLook.choice(signedIn.copy(accountLanguage = "en", appLanguage = "ur")))
        // Not read yet on this phone: a language chosen here, else English; never "system" (the phone's).
        assertEquals("ur", AppLook.choice(signedIn.copy(appLanguage = "ur")))
        assertEquals("en", AppLook.choice(signedIn))
        // Signed out: the choice made here, which may follow the phone.
        assertEquals("system", AppLook.choice(AppSettings(accountLanguage = "ur")))
        assertEquals("ur", AppLook.choice(AppSettings(appLanguage = "ur")))
    }

    @Test
    fun aSyncKeepsTheAccountsLanguageAndColourAndSigningOutForgetsThem() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/account", FakeServer.json(200, account("ur", colour = 5)))
        vm.refresh()
        pumpUntil("kept") { vm.settings.value.accountLanguage == "ur" && vm.settings.value.avatarColor == 5 }
        assertEquals("ur", AppLook.choice(vm.settings.value))
        // The account can't be read on the next sync: the last one known stays.
        server.on("GET", "/api/account", FakeServer.json(500, """{"message":"x"}"""))
        vm.refresh()
        pumpUntil("synced") { !vm.syncing.value }
        assertEquals("ur", vm.settings.value.accountLanguage)
        vm.unpair()
        pumpUntil("signed out") { !vm.settings.value.paired }
        assertNull(vm.settings.value.accountLanguage)
        assertNull(vm.settings.value.avatarColor)
    }

    private fun appearance() = show {
        val settings by vm.settings.collectAsState()
        EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
            androidx.compose.runtime.CompositionLocalProvider(
                com.echoledger.mobile.ui.v2.shell.LocalEchoToasts provides com.echoledger.mobile.ui.v2.components.rememberEchoToastState()
            ) {
                AppearancePage(
                    settings,
                    vm::updateSettings,
                    UiFlags(emptyMap()),
                    onSwitchToClassic = {},
                    highlight = null,
                    onBack = {},
                    onAccountLanguage = { vm.setAccountLanguage(it) }
                )
            }
        }
    }

    @Test
    fun signedInTheChoiceGoesToTheAccountAndAsOnThePhoneIsNotOffered() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("PATCH", "/api/account", FakeServer.json(200, account("ur")))
        appearance()
        // "As on the phone" is the text size's only (the language's is the account's, never the phone's).
        assertEquals("never the phone's", 1, compose.onAllNodesWithText("As on the phone").fetchSemanticsNodes().size)
        compose.onNode(hasText("اردو") and hasClickAction()).performScrollTo().performClick()
        pumpUntil("sent") { server.requestsTo("PATCH", "/api/account").isNotEmpty() }
        assertTrue(server.requestsTo("PATCH", "/api/account").single().bodyText().contains("\"language\":\"ur\""))
        pumpUntil("kept") { vm.settings.value.accountLanguage == "ur" }
    }

    @Test
    fun aRefusedChoiceIsPutBackWithAMessage() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("PATCH", "/api/account", FakeServer.json(500, """{"message":"x"}"""))
        runBlocking { app.graph.settings.update { it.copy(accountLanguage = "en") } }
        vm.setAccountLanguage("ur")
        pumpUntil("put back") { vm.message.value == "Couldn't change the language. Try again." }
        assertEquals("en", vm.settings.value.accountLanguage)
    }

    @Test
    fun signedOutAsOnThePhoneIsOfferedAndStaysOnThisPhone() {
        appearance()
        // Signed out the language offers it too, beside the text size's.
        assertEquals(2, compose.onAllNodesWithText("As on the phone").fetchSemanticsNodes().size)
        compose.onNode(hasText("اردو") and hasClickAction()).performScrollTo().performClick()
        pumpUntil("chosen") { vm.settings.value.appLanguage == "ur" }
        assertTrue(server.requestsTo("PATCH", "/api/account").isEmpty())
    }
}
