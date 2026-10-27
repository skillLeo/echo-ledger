package com.echoledger.mobile.data

import android.util.Base64
import androidx.datastore.preferences.core.stringPreferencesKey
import app.cash.turbine.test
import com.echoledger.mobile.testsupport.AesGcmSecretCipher
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.rawSettingsValue
import com.echoledger.mobile.testsupport.settingsDataStoreEdit
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test

/** Non-secret settings in DataStore and the encrypted device token, under Robolectric. */
class SettingsStoreTest : AppTest() {
    private val store get() = app.graph.settings

    private suspend fun plantRaw(name: String, value: String) = app.settingsDataStoreEdit { it[stringPreferencesKey(name)] = value }

    // ---- defaults and persistence -----------------------------------------------------------------------------------

    @Test
    fun `a fresh install has the documented defaults and no token`() = runBlocking {
        val settings = store.current()

        assertEquals(AppSettings(), settings)
        assertFalse(settings.paired)
        assertNull(settings.serverUrl)
        assertNull(settings.deviceId)
        assertTrue(settings.pins.isEmpty())
        assertTrue("captions are on by default", settings.captionsEnabled)
        assertFalse("auto-record is never armed by default", settings.autoRecordArmed)
        assertFalse("consent must be given explicitly", settings.consentAcknowledged)
        assertFalse("the app lock is opt-in", settings.appLockEnabled)
        assertEquals("voice_communication", settings.micSource)
        assertNull(store.token())
    }

    @Test
    fun `a phone paired before the wizard existed counts as set up and a new one does not`() = runBlocking {
        plantRaw("settings_json", """{"serverUrl":"https://ledger.example.com","deviceId":"01JDEVICE"}""")
        assertTrue(store.current().onboardingDone)

        plantRaw("settings_json", """{"captionsEnabled":false}""")
        assertFalse("not paired: the wizard still has to run", store.current().onboardingDone)
    }

    @Test
    fun `an explicit wizard flag is never overridden by the pairing`() = runBlocking {
        store.update { it.copy(serverUrl = "https://ledger.example.com", deviceId = "01JDEVICE", onboardingDone = false) }
        assertFalse("paired in the middle of the wizard", store.current().onboardingDone)

        store.update { it.copy(onboardingDone = true) }
        assertTrue(store.current().onboardingDone)
        store.clearPairing()
        assertTrue("unpairing never brings the wizard back", store.current().onboardingDone)
    }

    @Test
    fun `updates persist every field and are visible to a second store on the same file`() = runBlocking {
        store.update {
            it.copy(
                serverUrl = "https://ledger.example.com",
                pins = listOf("sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="),
                deviceId = "01JDEVICE",
                deviceName = "Pixel 9",
                autoRecordArmed = true,
                captionsEnabled = false,
                consentAcknowledged = true,
                clientId = "01JCLIENT",
                micSource = "mic",
                appLockEnabled = true
            )
        }

        val again = SettingsStore(app, AesGcmSecretCipher()).current()
        assertEquals("https://ledger.example.com", again.serverUrl)
        assertEquals(listOf("sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="), again.pins)
        assertEquals("01JDEVICE", again.deviceId)
        assertEquals("Pixel 9", again.deviceName)
        assertTrue(again.autoRecordArmed)
        assertFalse(again.captionsEnabled)
        assertTrue(again.consentAcknowledged)
        assertEquals("01JCLIENT", again.clientId)
        assertEquals("mic", again.micSource)
        assertTrue(again.appLockEnabled)
        assertTrue(again.paired)
    }

    @Test
    fun `the app lock flag can be switched on and off and nothing else changes`() = runBlocking {
        store.update { it.copy(captionsEnabled = false) }

        store.update { it.copy(appLockEnabled = true) }
        assertTrue(store.current().appLockEnabled)
        assertFalse(store.current().captionsEnabled)

        store.update { it.copy(appLockEnabled = false) }
        assertFalse(store.current().appLockEnabled)
        assertFalse(store.current().captionsEnabled)
    }

    @Test
    fun `a device counts as paired only with both a server address and a device id`() = runBlocking {
        store.update { it.copy(serverUrl = "https://a.example.com") }
        assertFalse(store.current().paired)
        store.update { it.copy(serverUrl = null, deviceId = "01JDEVICE") }
        assertFalse(store.current().paired)
        store.update { it.copy(serverUrl = "https://a.example.com") }
        assertTrue(store.current().paired)
    }

    @Test
    fun `the settings flow emits the new value after each update`() = runBlocking {
        store.settings.test {
            assertEquals(AppSettings(), awaitItem())
            store.update { it.copy(appLockEnabled = true) }
            assertTrue(awaitItem().appLockEnabled)
            store.update { it.copy(micSource = "mic") }
            assertEquals("mic", awaitItem().micSource)
            cancelAndIgnoreRemainingEvents()
        }
    }

    @Test
    fun `concurrent updates are serialized and none is lost`() = runBlocking {
        (1..40).map { n -> async { store.update { it.copy(pins = it.pins + "pin-$n") } } }.awaitAll()

        val pins = store.current().pins
        assertEquals(40, pins.size)
        assertEquals((1..40).map { "pin-$it" }.toSet(), pins.toSet())
    }

    // ---- token ---------------------------------------------------------------------------------------------------------

    @Test
    fun `a token survives a round trip but is never stored as plain text`() = runBlocking {
        val token = "7|eld_0123456789abcdefABCDEF"

        store.saveToken(token)

        assertEquals(token, store.token())
        val raw = app.rawSettingsValue("device_token_enc")
        assertNotNull(raw)
        assertFalse("the stored value must not contain the token", raw!!.contains(token) || raw.contains("eld_"))
        val bytes = Base64.decode(raw, Base64.NO_WRAP)
        assertFalse(String(bytes, Charsets.ISO_8859_1).contains("eld_"))
    }

    @Test
    fun `the stored token is bound to its purpose through the associated data`() = runBlocking {
        val cipher = AesGcmSecretCipher()
        val bound = SettingsStore(app, cipher)
        bound.saveToken("7|eld_secret")

        val raw = Base64.decode(app.rawSettingsValue("device_token_enc"), Base64.NO_WRAP)

        assertEquals("7|eld_secret", String(cipher.decrypt(raw, "echoledger.device_token".toByteArray())))
        val rejected = assertThrows(Exception::class.java) { cipher.decrypt(raw, "echoledger.library".toByteArray()) }
        assertNotNull(rejected)
    }

    @Test
    fun `unusual tokens round trip intact`() = runBlocking {
        listOf("x", "7|" + "e".repeat(100_000), "7|tökén — 🔑", "line1\nline2").forEach {
            store.saveToken(it)
            assertEquals(it, store.token())
        }
    }

    @Test
    fun `saving a new token replaces the old one`() = runBlocking {
        store.saveToken("first")
        store.saveToken("second")
        assertEquals("second", store.token())
    }

    @Test
    fun `corrupt token data reads as no token rather than crashing`() = runBlocking {
        listOf("", "not base64 at all %%%", "AAAA", Base64.encodeToString(ByteArray(64) { it.toByte() }, Base64.NO_WRAP)).forEach {
            plantRaw("device_token_enc", it)
            assertNull("[$it]", store.token())
        }
    }

    @Test
    fun `a token sealed with a key that no longer exists reads as no token`() = runBlocking {
        store.saveToken("7|eld_secret")

        val rekeyed = SettingsStore(app, AesGcmSecretCipher())

        assertNull("keystore reset or restored backup", rekeyed.token())
    }

    @Test
    fun `clearing the pairing drops the token and device but keeps preferences and the server address`() = runBlocking {
        store.update {
            it.copy(
                serverUrl = "https://a.example.com",
                pins = listOf("p"),
                deviceId = "d",
                deviceName = "n",
                captionsEnabled = false,
                appLockEnabled = true,
                clientId = "c"
            )
        }
        store.saveToken("7|eld_secret")

        store.clearPairing()

        val settings = store.current()
        assertNull(store.token())
        assertFalse(settings.paired)
        assertNull(settings.deviceId)
        assertNull(settings.deviceName)
        assertFalse("preferences survive unpairing", settings.captionsEnabled)
        assertTrue(settings.appLockEnabled)
        assertEquals("https://a.example.com", settings.serverUrl)
        assertNull("the token entry itself is removed", app.rawSettingsValue("device_token_enc"))
    }

    @Test
    fun `clearing the pairing of an unpaired phone is harmless`() = runBlocking {
        store.clearPairing()
        store.clearPairing()
        assertEquals(AppSettings(), store.current())
    }

    // ---- corrupt and foreign data --------------------------------------------------------------------------------------

    @Test
    fun `corrupt settings JSON falls back to the defaults and the next update repairs it`() = runBlocking {
        listOf("{not json", "", "[]", "null", "\"string\"", "{\"serverUrl\": 5}", "{\"pins\": \"x\"}").forEach {
            plantRaw("settings_json", it)
            assertEquals("[$it]", AppSettings(), store.current())
        }

        store.update { it.copy(captionsEnabled = false) }

        assertFalse(store.current().captionsEnabled)
        assertEquals(
            "the repaired value is valid JSON again",
            false,
            ApiJson.decodeFromString(AppSettings.serializer(), app.rawSettingsValue("settings_json")!!).captionsEnabled
        )
    }

    @Test
    fun `settings written by a newer version with extra fields are still read`() = runBlocking {
        plantRaw(
            "settings_json",
            """{"serverUrl":"https://a.example.com","deviceId":"d","futureFlag":true,"nested":{"a":[1]},"appLockEnabled":true}"""
        )

        val settings = store.current()

        assertEquals("https://a.example.com", settings.serverUrl)
        assertTrue(settings.appLockEnabled)
        assertTrue(settings.paired)
    }

    @Test
    fun `settings written by an older version without the newer fields use their defaults`() = runBlocking {
        plantRaw("settings_json", """{"serverUrl":"https://a.example.com","deviceId":"d"}""")

        val settings = store.current()

        assertTrue(settings.paired)
        assertFalse(settings.appLockEnabled)
        assertTrue(settings.captionsEnabled)
        assertEquals("voice_communication", settings.micSource)
    }

    @Test
    fun `a stored token is not disturbed by settings updates`() = runBlocking {
        store.saveToken("7|eld_secret")
        store.update { it.copy(micSource = "mic") }
        store.update { it.copy(appLockEnabled = true) }
        assertEquals("7|eld_secret", store.token())
    }

    @Test
    fun `screenshots are allowed by default and an older stored hide-content default is reset once`() = runBlocking {
        assertEquals(false, AppSettings().secureScreen)

        plantRaw("settings_json", """{"serverUrl":"https://x.example","deviceId":"d1","secureScreen":true,"onboardingDone":true}""")
        assertEquals(false, store.current().secureScreen)

        store.update { it.copy(secureScreen = true) }
        assertEquals("a choice made afterwards is kept", true, store.current().secureScreen)
    }
}
