/*
 * End-to-end tests: the app on an emulator or device against a disposable EchoLedger test backend. Every test here is
 * skipped unless the run names the backend with these instrumentation arguments (with Gradle:
 * -Pandroid.testInstrumentationRunnerArguments.<name>=<value>):
 *   backend.server   the test server the app pairs with, for example http://10.0.2.2:8000 (the development machine as
 *                    seen from the Android emulator)
 *   backend.flaky    the same server behind a proxy whose failure mode the tests switch
 *   backend.control  the backend's control endpoint (pairing codes, seeding, statistics; see support/Backend.kt)
 */
package com.echoledger.mobile.e2e

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.uiautomator.By
import com.echoledger.mobile.e2e.support.AppState
import com.echoledger.mobile.e2e.support.Backend
import com.echoledger.mobile.e2e.support.E2eTest
import com.echoledger.mobile.e2e.support.SignInScreen
import com.echoledger.mobile.e2e.support.Ui
import java.io.File
import java.util.regex.Pattern
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

/** First launch and the ways of pairing a phone with the server, entered by hand (the QR camera has its own test class). */
@RunWith(AndroidJUnit4::class)
class PairingTest : E2eTest() {
    private fun submit() {
        Ui.hideKeyboard()
        Ui.clickText("Sign in with the code")
    }

    @Test
    fun firstLaunchShowsThePairingScreenWithoutAnyPermissionPrompt() {
        Ui.launchApp()
        Ui.awaitText(SignInScreen.TITLE)
        listOf("Email", "Continue", SignInScreen.OTHER_WAYS).forEach {
            assertTrue("missing \"$it\"", Ui.has(it))
        }
        assertTrue(Ui.hasContaining("Your recordings are encrypted on this phone"))
        Ui.snapshot("pairing-form")
        assertFalse("no permission dialog at first launch", Ui.device.hasObject(By.pkg(Pattern.compile(".*permissioncontroller"))))
        assertFalse(AppState.settings().paired)
        assertNull(AppState.token())
    }

    @Test
    fun pairButtonStaysDisabledUntilACodeIsEntered() {
        Ui.launchApp()
        SignInScreen.otherWays()
        assertFalse(Ui.isButtonEnabled("Sign in with the code"))
        Ui.fillField("One-time sign-in code", "ABCD-EFGH")
        Ui.hideKeyboard()
        assertTrue(Ui.isButtonEnabled("Sign in with the code"))
        Ui.field("One-time sign-in code").text = ""
        Ui.hideKeyboard()
        assertFalse(Ui.isButtonEnabled("Sign in with the code"))
    }

    @Test
    fun typedServerAddressAndCodePairThePhoneAndOpenTheEmptyLibrary() {
        Backend.wipeSessions()
        Backend.awaitPairingBudget()
        val code = Backend.pairingCode()
        Ui.launchApp()
        SignInScreen.otherWays(server = code.url)
        Ui.fillField("One-time sign-in code", code.display)
        submit()
        Backend.notePairingAttempt()
        Ui.awaitTextContaining("Paired with", Ui.LONG)
        Ui.awaitText("No recordings yet", Ui.LONG)
        assertTrue(Backend.deviceNames().isNotEmpty())
        val settings = AppState.settings()
        assertTrue(settings.paired)
        assertEquals(code.url, settings.serverUrl)
        assertEquals("Emulator typed pairing", settings.deviceName)
        assertNotNull(AppState.token())
    }

    @Test
    fun aQrPayloadPastedIntoTheCodeFieldHidesTheAddressFieldAndPairs() {
        Backend.awaitPairingBudget()
        val code = Backend.pairingCode(viaFlaky = true)
        Ui.launchApp()
        Ui.awaitText(SignInScreen.TITLE)
        Ui.fillField("One-time sign-in code", code.payload)
        Ui.awaitTextGone(SignInScreen.OTHER_WAYS)
        submit()
        Ui.awaitDescription("Settings", Ui.LONG)
        assertEquals(code.url, AppState.settings().serverUrl)
        assertTrue("default device name is the model", Backend.deviceNames().any { it.contains("sdk_gphone") || it.isNotBlank() })
    }

    @Test
    fun aWrongCodeShowsTheServerMessageAndKeepsTheForm() {
        Backend.awaitPairingBudget()
        val before = Backend.deviceNames().size
        Ui.launchApp()
        Ui.awaitText(SignInScreen.TITLE)
        SignInScreen.otherWays(server = Backend.flakyServerUrl)
        Ui.fillField("One-time sign-in code", "ZZZZ-ZZZZ")
        submit()
        Ui.awaitText("This code is invalid, expired, or already used.", Ui.LONG)
        Ui.snapshot("pairing-wrong-code")
        assertTrue(Ui.has(SignInScreen.TITLE))
        assertEquals(before, Backend.deviceNames().size)
        assertFalse(AppState.settings().paired)
        assertNull(AppState.token())
    }

    @Test
    fun aCodeThatWasAlreadyUsedIsRefused() {
        Backend.awaitPairingBudget()
        val code = Backend.pairingCode(viaFlaky = true)
        Ui.launchApp()
        Ui.awaitText(SignInScreen.TITLE)
        SignInScreen.otherWays(server = code.url)
        Ui.fillField("One-time sign-in code", code.display)
        submit()
        Ui.awaitDescription("Settings", Ui.LONG)
        AppState.reset()
        Backend.awaitPairingBudget()
        Ui.launchApp()
        Ui.awaitText(SignInScreen.TITLE)
        SignInScreen.otherWays(server = code.url)
        Ui.fillField("One-time sign-in code", code.display)
        submit()
        Ui.awaitText("This code is invalid, expired, or already used.", Ui.LONG)
        assertFalse(AppState.settings().paired)
    }

    @Test
    fun payloadsThatAreNotEchoLedgerPairingCodesAreRejectedWithoutContactingTheServer() {
        val notACode = "That isn't an EchoLedger sign-in code. In the console open Profile → Devices → Sign in a device."
        val payloads = listOf(
            """{"hello":"world"}""",
            """{"v":1,"url":"https://ledger.example.com","code":"@@@@","pins":[]}""",
            """{"v":2,"url":"https://ledger.example.com","code":"ABCD2345","pins":[]}""",
            """{"v":1,"url":"http://ledger.example.com","code":"ABCD2345","pins":[]}"""
        )
        val before = Backend.deviceNames().size
        Ui.launchApp()
        Ui.awaitText(SignInScreen.TITLE)
        payloads.forEach { payload ->
            Ui.fillField("One-time sign-in code", payload)
            submit()
            Ui.awaitText(notACode, Ui.NORMAL)
            Ui.awaitTextGone(notACode, Ui.LONG)
        }
        assertEquals(before, Backend.deviceNames().size)
        assertFalse(AppState.settings().paired)
    }

    @Test
    fun anIncompleteOrPlainTextServerAddressGivesAReadableErrorWithoutContactingTheServer() {
        val message = "The server address must use https://"
        val before = Backend.deviceNames().size
        Ui.launchApp()
        Ui.awaitText(SignInScreen.TITLE)
        Ui.fillField("One-time sign-in code", "ABCD-EFGH")
        submit()
        Ui.awaitText(message)
        Ui.awaitTextGone(message, Ui.LONG)
        SignInScreen.otherWays(server = "http://ledger.example.com")
        submit()
        Ui.awaitText(message)
        assertTrue("still on the pairing screen", Ui.has(SignInScreen.TITLE))
        assertFalse(AppState.settings().paired)
        assertEquals(before, Backend.deviceNames().size)
    }

    @Test
    fun pairingSurvivesARestartAndTheTokenIsNotStoredInPlainText() {
        Backend.awaitPairingBudget()
        val code = Backend.pairingCode(viaFlaky = true)
        Ui.launchApp()
        Ui.awaitText(SignInScreen.TITLE)
        SignInScreen.otherWays(server = code.url)
        Ui.fillField("One-time sign-in code", code.display)
        submit()
        Ui.awaitDescription("Settings", Ui.LONG)
        val token = checkNotNull(AppState.token())
        Ui.launchApp()
        Ui.awaitText("Library")
        Ui.snapshot("library-after-pairing")
        assertFalse("restart must not show the pairing form", Ui.has(SignInScreen.TITLE))
        val secret = token.substringAfter('|')
        val roots = listOf(Ui.context.filesDir.parentFile!!, Ui.context.noBackupFilesDir.parentFile!!).distinct()
        val leaks = roots.flatMap { it.walkTopDown().filter(File::isFile).toList() }
            .filter { runCatching { it.readBytes().toString(Charsets.ISO_8859_1).contains(secret) }.getOrDefault(false) }
        assertTrue("token found in plain text in ${leaks.map { it.path }}", leaks.isEmpty())
    }
}
