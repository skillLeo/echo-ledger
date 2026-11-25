package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.test.captureToImage
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
@org.robolectric.annotation.GraphicsMode(org.robolectric.annotation.GraphicsMode.Mode.NATIVE)
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
        // Not read yet on this phone: English, never the phone's ("system") nor a choice made while signed out.
        assertEquals("en", AppLook.choice(signedIn.copy(appLanguage = "ur")))
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
        // The choice made while signed out is never touched.
        assertEquals("system", vm.settings.value.appLanguage)
    }

    private fun held(): Pair<java.util.concurrent.CountDownLatch, java.util.concurrent.atomic.AtomicBoolean> =
        java.util.concurrent.CountDownLatch(1) to java.util.concurrent.atomic.AtomicBoolean(false)

    /** Review of BE-06: a sync whose answer was read before a change landed never undoes the change. */
    @Test
    fun aSyncDuringAChangeNeverUndoesIt() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        runBlocking { app.graph.settings.update { it.copy(accountLanguage = "en") } }
        val (release, asked) = held()
        server.on("PATCH", "/api/account") { _ ->
            asked.set(true)
            release.await(10, java.util.concurrent.TimeUnit.SECONDS)
            FakeServer.json(200, account("ur"))
        }
        server.on("GET", "/api/account", FakeServer.json(200, account("en")))
        vm.setAccountLanguage("ur")
        pumpUntil("the change on its way") { asked.get() }
        vm.refresh()
        pumpUntil("the sync read the account") { server.requestsTo("GET", "/api/account").isNotEmpty() && !vm.syncing.value }
        assertEquals("the older answer undid nothing", "ur", vm.settings.value.accountLanguage)
        release.countDown()
        pumpUntil("saved") { server.requestsTo("PATCH", "/api/account").isNotEmpty() }
        assertEquals("ur", vm.settings.value.accountLanguage)
    }

    /** Review of BE-06: two quick choices end on the second, whatever order their answers come in. */
    @Test
    fun twoQuickChoicesEndOnTheLatest() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        val (release, asked) = held()
        server.on("PATCH", "/api/account") { req ->
            if (req.bodyText().contains("\"ur\"")) {
                asked.set(true)
                release.await(10, java.util.concurrent.TimeUnit.SECONDS)
                FakeServer.json(200, account("ur"))
            } else {
                FakeServer.json(200, account("en"))
            }
        }
        vm.setAccountLanguage("ur")
        pumpUntil("the first on its way") { asked.get() }
        vm.setAccountLanguage("en")
        pumpUntil("the second saved") { server.requestsTo("PATCH", "/api/account").size >= 1 && vm.settings.value.accountLanguage == "en" }
        release.countDown()
        pumpUntil("both answered") { server.requestsTo("PATCH", "/api/account").size == 2 }
        compose.waitForIdle()
        assertEquals("the latest choice stays", "en", vm.settings.value.accountLanguage)
    }

    /** A language the app doesn't speak keeps the last one known. */
    @Test
    fun anUnknownLanguageFromTheServerKeepsTheLastOneKnown() {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/account", FakeServer.json(200, account("ur")))
        vm.refresh()
        pumpUntil("ur") { vm.settings.value.accountLanguage == "ur" }
        server.on("GET", "/api/account", FakeServer.json(200, account("ar")))
        vm.refresh()
        pumpUntil("synced") { !vm.syncing.value && server.requestsTo("GET", "/api/account").size >= 2 }
        assertEquals("ur", vm.settings.value.accountLanguage)
    }

    /** Signed out again, the choice made while signed out comes back; the next account brings its own. */
    @Test
    fun signingOutBringsBackTheSignedOutChoiceAndTheNextAccountItsOwn() {
        runBlocking { app.graph.settings.update { it.copy(appLanguage = "en") } }
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on("GET", "/api/account", FakeServer.json(200, account("ur")))
        vm.refresh()
        pumpUntil("account A in Urdu") { AppLook.choice(vm.settings.value) == "ur" }
        vm.unpair()
        pumpUntil("signed out") { !vm.settings.value.paired }
        assertEquals("the signed-out choice", "en", AppLook.choice(vm.settings.value))
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/account", FakeServer.json(200, account("en")))
        pumpUntil("signed in") { vm.settings.value.paired }
        assertEquals("not account A's while B is read", "en", AppLook.choice(vm.settings.value))
        vm.refresh()
        pumpUntil("account B") { vm.settings.value.accountLanguage == "en" }
    }

    /** Before the settings are read (a cold start's first notification): the language kept at the last save, else English. */
    @Test
    fun beforeTheSettingsAreReadTheKeptLanguageElseEnglish() {
        assertEquals("en", com.echoledger.mobile.notify.AppWords.choiceOf(null, null))
        assertEquals("ur", com.echoledger.mobile.notify.AppWords.choiceOf(null, "ur"))
        assertEquals("en", com.echoledger.mobile.notify.AppWords.choiceOf(signedIn.copy(accountLanguage = "en"), "ur"))
        AppLook.keep(app, "ur")
        AppLook.load(app)
        assertEquals("kept for the next start", "ur", AppLook.lastKnown)
    }

    /** DES-08: the signed-in person's initials on the account's colour (drawn), never grey; the desktop's fallback hash. */
    @Test
    fun thePersonsInitialsAreDrawnOnTheirColour() {
        assertEquals(4, com.echoledger.mobile.ui.v2.shell.personColour("sample@example.test"))
        assertEquals(5, com.echoledger.mobile.ui.v2.shell.personColour("Bilal@Example.com "))
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                androidx.compose.runtime.CompositionLocalProvider(com.echoledger.mobile.ui.v2.shell.LocalAvatarColour provides 5) {
                    com.echoledger.mobile.ui.v2.shell.PersonAvatar("SO", androidx.compose.ui.Modifier.testTag("avatar"))
                }
            }
        }
        val image = compose.onNode(androidx.compose.ui.test.hasTestTag("avatar"), useUnmergedTree = true).captureToImage().asAndroidBitmap()
        val drawn = image.getPixel(image.width / 2, image.height / 5)
        val want = com.echoledger.mobile.ui.v2.tokens.EchoTokens.Light.avatar5.toArgb()
        val near = listOf(16, 8, 0).all { kotlin.math.abs((drawn shr it and 0xff) - (want shr it and 0xff)) <= 3 }
        assertTrue("avatar5 drawn: ${Integer.toHexString(drawn)}", near)
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
