package com.echoledger.mobile.ui

import android.net.Uri
import com.echoledger.mobile.core.SettingsTransfer
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Settings export / import through the picked file, and the flag that ends the wizard (B19). */
class SettingsFilesTest : AppTest() {
    private lateinit var vm: MainViewModel
    private lateinit var file: File

    @Before
    fun prepare() {
        vm = MainViewModel(app).clearedAfterTest()
        file = File(app.cacheDir, "settings-test.json").apply { delete() }
    }

    private fun settings() = runBlocking { app.graph.settings.current() }

    private fun awaitMessage(text: String) = pumpUntil("the message \"$text\"") { vm.message.value == text }

    @Test
    fun `exporting writes the preferences and none of the secrets`() {
        runBlocking {
            app.pairLocally(server, token = "7|eld_secrettoken", pins = listOf("sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="))
            app.graph.settings.update {
                it.copy(captionsEnabled = false, decisionTimeout = "wait", appLockEnabled = true, consentAcknowledged = true)
            }
        }

        vm.exportSettingsTo(Uri.fromFile(file))
        awaitMessage("Settings saved")

        val text = file.readText()
        assertTrue(text.contains("\"echoledger-settings\""))
        assertTrue(text.contains("\"decisionTimeout\": \"wait\""))
        listOf("eld_secrettoken", server.baseUrl, "sha256/", "01JDEVICE", "Test phone", "appLockEnabled", "consentAcknowledged").forEach {
            assertFalse("the file must not contain $it", text.contains(it))
        }
    }

    @Test
    fun `importing applies the preferences and keeps the pairing and the token`() {
        runBlocking { app.pairLocally(server, token = "7|eld_secrettoken") }
        file.writeText(SettingsTransfer.export(settings().copy(captionsEnabled = false, decisionTimeout = "discard", quietHours = true)))
        runBlocking { app.graph.settings.update { it.copy(captionsEnabled = true, decisionTimeout = "keep", quietHours = false) } }

        vm.importSettingsFrom(Uri.fromFile(file))
        awaitMessage("Settings imported")

        val now = settings()
        assertFalse(now.captionsEnabled)
        assertEquals("discard", now.decisionTimeout)
        assertTrue(now.quietHours)
        assertTrue(now.paired)
        assertEquals(server.baseUrl, now.serverUrl)
        assertEquals("7|eld_secrettoken", runBlocking { app.graph.settings.token() })
    }

    @Test
    fun `importing a file that is not a settings file changes nothing and says so`() {
        file.writeText("""{"hello":"world"}""")

        vm.importSettingsFrom(Uri.fromFile(file))

        awaitMessage(SettingsTransfer.NOT_A_SETTINGS_FILE)
        assertEquals(com.echoledger.mobile.data.AppSettings(), settings())
    }

    @Test
    fun `importing a file that cannot be read says so`() {
        vm.importSettingsFrom(Uri.fromFile(File(app.cacheDir, "missing.json")))

        awaitMessage("The file could not be read.")
    }

    @Test
    fun `finishing the wizard saves the flag once and records consent only when ticked`() {
        vm.finishOnboarding()
        pumpUntil("flag saved") { settings().onboardingDone }
        assertFalse(settings().consentAcknowledged)

        runBlocking { app.graph.settings.update { it.copy(onboardingDone = false) } }
        vm.finishOnboarding(consent = true)
        pumpUntil("consent saved") { settings().consentAcknowledged }
        assertTrue(settings().onboardingDone)
    }
}
