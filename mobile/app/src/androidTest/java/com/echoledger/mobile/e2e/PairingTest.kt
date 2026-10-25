package com.echoledger.mobile.e2e

import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.uiautomator.By
import com.echoledger.mobile.e2e.support.AppState
import com.echoledger.mobile.e2e.support.Backend
import com.echoledger.mobile.e2e.support.E2eTest
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
        Ui.clickText("Pair")
    }

    @Test
    fun firstLaunchShowsThePairingScreenWithoutAnyPermissionPrompt() {
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        listOf("Scan QR code", "Server address", "Pairing code", "Name for this phone (optional)").forEach {
            assertTrue("missing \"$it\"", Ui.has(it))
        }
        assertTrue(Ui.hasContaining("Your access token is encrypted with the Android Keystore"))
        Ui.snapshot("pairing-form")
        assertFalse("no permission dialog at first launch", Ui.device.hasObject(By.pkg(Pattern.compile(".*permissioncontroller"))))
        assertFalse(AppState.settings().paired)
        assertNull(AppState.token())
    }

    @Test
    fun pairButtonStaysDisabledUntilACodeIsEntered() {
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        assertFalse(Ui.isButtonEnabled("Pair"))
        Ui.fillField("Pairing code", "ABCD-EFGH")
        Ui.hideKeyboard()
        assertTrue(Ui.isButtonEnabled("Pair"))
        Ui.field("Pairing code").text = ""
        Ui.hideKeyboard()
        assertFalse(Ui.isButtonEnabled("Pair"))
    }

    @Test
    fun typedServerAddressAndCodePairThePhoneAndOpenTheEmptyLibrary() {
        Backend.wipeSessions()
        Backend.awaitPairingBudget()
        val code = Backend.pairingCode()
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        Ui.fillField("Server address", code.url)
        Ui.fillField("Pairing code", code.display)
        Ui.fillField("Name for this phone (optional)", "Emulator typed pairing")
        submit()
        Backend.notePairingAttempt()
        Ui.awaitTextContaining("Paired with", Ui.LONG)
        Ui.awaitText("No recordings yet", Ui.LONG)
        assertTrue(Backend.deviceNames().contains("Emulator typed pairing"))
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
        Ui.awaitText("Pair this phone")
        Ui.fillField("Pairing code", code.payload)
        Ui.awaitTextGone("Server address")
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
        Ui.awaitText("Pair this phone")
        Ui.fillField("Server address", Backend.flakyServerUrl)
        Ui.fillField("Pairing code", "ZZZZ-ZZZZ")
        submit()
        Ui.awaitText("This pairing code is invalid, expired, or already used.", Ui.LONG)
        Ui.snapshot("pairing-wrong-code")
        assertTrue(Ui.has("Pair this phone"))
        assertEquals(before, Backend.deviceNames().size)
        assertFalse(AppState.settings().paired)
        assertNull(AppState.token())
    }

    @Test
    fun aCodeThatWasAlreadyUsedIsRefused() {
        Backend.awaitPairingBudget()
        val code = Backend.pairingCode(viaFlaky = true)
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        Ui.fillField("Server address", code.url)
        Ui.fillField("Pairing code", code.display)
        submit()
        Ui.awaitDescription("Settings", Ui.LONG)
        AppState.reset()
        Backend.awaitPairingBudget()
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        Ui.fillField("Server address", code.url)
        Ui.fillField("Pairing code", code.display)
        submit()
        Ui.awaitText("This pairing code is invalid, expired, or already used.", Ui.LONG)
        assertFalse(AppState.settings().paired)
    }

    @Test
    fun payloadsThatAreNotEchoLedgerPairingCodesAreRejectedWithoutContactingTheServer() {
        val notACode = "That isn't an EchoLedger pairing code. In your admin open Devices → Pair a device."
        val payloads = listOf(
            """{"hello":"world"}""",
            """{"v":1,"url":"https://ledger.example.com","code":"@@@@","pins":[]}""",
            """{"v":2,"url":"https://ledger.example.com","code":"ABCD2345","pins":[]}""",
            """{"v":1,"url":"http://ledger.example.com","code":"ABCD2345","pins":[]}"""
        )
        val before = Backend.deviceNames().size
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        payloads.forEach { payload ->
            Ui.fillField("Pairing code", payload)
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
        Ui.awaitText("Pair this phone")
        Ui.fillField("Pairing code", "ABCD-EFGH")
        submit()
        Ui.awaitText(message)
        Ui.awaitTextGone(message, Ui.LONG)
        Ui.fillField("Server address", "http://ledger.example.com")
        submit()
        Ui.awaitText(message)
        assertTrue("still on the pairing screen", Ui.has("Pair this phone"))
        assertFalse(AppState.settings().paired)
        assertEquals(before, Backend.deviceNames().size)
    }

    @Test
    fun pairingSurvivesARestartAndTheTokenIsNotStoredInPlainText() {
        Backend.awaitPairingBudget()
        val code = Backend.pairingCode(viaFlaky = true)
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        Ui.fillField("Server address", code.url)
        Ui.fillField("Pairing code", code.display)
        submit()
        Ui.awaitDescription("Settings", Ui.LONG)
        val token = checkNotNull(AppState.token())
        Ui.launchApp()
        Ui.awaitText("Library")
        Ui.snapshot("library-after-pairing")
        assertFalse("restart must not show the pairing form", Ui.has("Pair this phone"))
        val secret = token.substringAfter('|')
        val roots = listOf(Ui.context.filesDir.parentFile!!, Ui.context.noBackupFilesDir.parentFile!!).distinct()
        val leaks = roots.flatMap { it.walkTopDown().filter(File::isFile).toList() }
            .filter { runCatching { it.readBytes().toString(Charsets.ISO_8859_1).contains(secret) }.getOrDefault(false) }
        assertTrue("token found in plain text in ${leaks.map { it.path }}", leaks.isEmpty())
    }
}
