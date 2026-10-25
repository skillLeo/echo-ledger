package com.echoledger.mobile.core

import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.CaptionStyle
import kotlinx.serialization.ExperimentalSerializationApi
import kotlinx.serialization.descriptors.elementNames
import kotlinx.serialization.json.jsonObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class SettingsTransferTest {
    private val paired = AppSettings(
        serverUrl = "https://ledger.example.com",
        pins = listOf("sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="),
        deviceId = "01JDEVICE",
        deviceName = "Sam's Pixel",
        clientId = "01JCLIENT",
        consentAcknowledged = true,
        appLockEnabled = true,
        lockAfterMs = 60_000L,
        onboardingDone = true,
        captionsEnabled = false,
        quietHours = true,
        quietFrom = "21:00",
        decisionTimeout = "discard"
    )

    private fun applied(text: String, current: AppSettings = AppSettings()) =
        (SettingsTransfer.import(text, current) as SettingsTransfer.Result.Applied).settings

    @OptIn(ExperimentalSerializationApi::class)
    @Test
    fun `every setting is either portable or deliberately kept on the phone`() {
        val names = AppSettings.serializer().descriptor.elementNames.toSet()

        assertEquals("a new setting must be put in one of the two lists", names, SettingsTransfer.PORTABLE + SettingsTransfer.NOT_PORTABLE)
        assertTrue(SettingsTransfer.PORTABLE.intersect(SettingsTransfer.NOT_PORTABLE).isEmpty())
    }

    @Test
    fun `an export holds the preferences and never the pairing the token the lock or consent`() {
        val text = SettingsTransfer.export(paired)

        listOf("ledger.example.com", "sha256/", "01JDEVICE", "Sam's Pixel", "01JCLIENT", "token", "appLockEnabled", "lockAfterMs").forEach {
            assertFalse("the export must not contain $it", text.contains(it))
        }
        val doc = ApiJson.parseToJsonElement(text).jsonObject
        assertEquals("echoledger-settings", (doc["format"] as kotlinx.serialization.json.JsonPrimitive).content)
        // Unset values (no chosen microphone, automatic language) are left out of the file.
        assertEquals(SettingsTransfer.PORTABLE - setOf("inputDevice", "transcriptionLanguage"), doc["settings"]!!.jsonObject.keys)
        assertTrue(text.contains("\"decisionTimeout\": \"discard\""))
        assertTrue(text.endsWith("\n"))
    }

    @Test
    fun `an import applies the preferences and leaves the pairing the lock and consent alone`() {
        val text = SettingsTransfer.export(paired)
        val other =
            AppSettings(serverUrl = "https://other.example.com", deviceId = "01JOTHER", consentAcknowledged = false, onboardingDone = true)

        val result = applied(text, other)

        assertFalse(result.captionsEnabled)
        assertEquals("discard", result.decisionTimeout)
        assertEquals("21:00", result.quietFrom)
        assertEquals("https://other.example.com", result.serverUrl)
        assertEquals("01JOTHER", result.deviceId)
        assertFalse("consent is given in person", result.consentAcknowledged)
        assertFalse("a lock is switched on by unlocking once", result.appLockEnabled)
    }

    @Test
    fun `a file cannot smuggle in a pairing or turn the lock on`() {
        val text = """
            {"format":"echoledger-settings","version":1,"settings":{
              "serverUrl":"https://evil.example.com","pins":["x"],"deviceId":"evil","appLockEnabled":true,
              "consentAcknowledged":true,"onboardingDone":true,"captionsEnabled":false}}
        """.trimIndent()

        val result = applied(text)

        assertEquals(AppSettings().copy(captionsEnabled = false), result)
    }

    @Test
    fun `values this build does not understand are skipped one by one`() {
        val text = """
            {"format":"echoledger-settings","version":1,"settings":{
              "dictationMode":["not","a","string"],"decisionTimeout":"wait","quietFrom":"25:99","captionStyle":{"opacity":5,"lines":4}}}
        """.trimIndent()

        val result = applied(text)

        assertEquals("wait", result.decisionTimeout)
        assertEquals("toggle", result.dictationMode)
        assertEquals("22:00", result.quietFrom)
        assertEquals(CaptionStyle().opacity, result.captionStyle.opacity)
        assertEquals(CaptionStyle().lines, result.captionStyle.lines)
    }

    @Test
    fun `anything that is not an EchoLedger settings file is refused`() {
        listOf("", "not json", "[]", """{"format":"other","settings":{}}""", """{"format":"echoledger-settings"}""").forEach { text ->
            assertTrue("refused: $text", SettingsTransfer.import(text, AppSettings()) is SettingsTransfer.Result.Rejected)
        }
        val huge = """{"format":"echoledger-settings","settings":{"x":"${"a".repeat(SettingsTransfer.MAX_BYTES)}"}}"""
        assertTrue(SettingsTransfer.import(huge, AppSettings()) is SettingsTransfer.Result.Rejected)
    }

    @Test
    fun `a round trip of the portable preferences changes nothing`() {
        val tuned = AppSettings(
            captionStyle = CaptionStyle(size = "large", lines = 5, compact = true),
            autoRecordApps = mapOf("whatsapp" to false),
            transcriptionLanguage = "en+ur",
            inputDevice = "wired"
        )

        assertEquals(tuned, applied(SettingsTransfer.export(tuned)))
    }
}
