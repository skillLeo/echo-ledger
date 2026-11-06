package com.echoledger.mobile.core

import com.echoledger.mobile.privilege.AppCallCapture
import com.echoledger.mobile.privilege.Capabilities
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** What the device check says on each kind of phone (since v1.5): it says what the app really does there. */
class DeviceCheckTest {
    private fun caps(sdk: Int = 34, helper: Boolean = true, mic: Boolean = true, overlay: Boolean = true, inBuild: Boolean = true) =
        Capabilities(sdk, helper, mic, notificationPermission = true, overlayPermission = overlay, helperInBuild = inBuild)

    private fun facts(
        capabilities: Capabilities = caps(),
        manufacturer: String = "Xiaomi",
        battery: Boolean = true,
        phoneLine: Boolean = true,
        signedIn: Boolean = true,
        liveTranslate: Boolean = true
    ) = PhoneFacts(
        manufacturer = manufacturer, brand = manufacturer, model = "23078PND5G", release = "14", appVersion = "1.5.0",
        capabilities = capabilities, batteryExempt = battery, hasPhoneLine = phoneLine,
        phoneCallsInBuild = capabilities.helperInBuild, signedIn = signedIn, liveTranslateInPlan = liveTranslate, memoryGb = 3
    )

    private fun row(key: String, facts: PhoneFacts) = DeviceCheck.rows(facts).single { it.key == key }

    @Test
    fun `an app call records what each Android version allows`() {
        assertEquals(AppCallCapture.BOTH_SIDES, caps(34).appCalls)
        assertEquals(AppCallCapture.BOTH_SIDES, caps(36).appCalls)
        assertEquals(AppCallCapture.NEEDS_HELPER, caps(34, helper = false).appCalls)
        assertEquals(AppCallCapture.VOICE_ONLY, caps(33).appCalls)
        assertEquals(AppCallCapture.VOICE_ONLY, caps(30).appCalls)
        assertEquals(AppCallCapture.VOICE_ONLY_NEEDS_HELPER, caps(30, helper = false).appCalls)
        assertEquals(AppCallCapture.NONE, caps(29).appCalls)
        assertEquals(AppCallCapture.NOT_IN_THIS_BUILD, caps(36, inBuild = false).appCalls)
    }

    @Test
    fun `nothing is promised up front that the phone cannot do`() {
        assertNull(DeviceCheck.callLine(AppCallCapture.BOTH_SIDES))
        assertEquals(
            "This phone can record only your voice in WhatsApp calls: hearing the other side needs Android 14 or newer.",
            DeviceCheck.callLine(AppCallCapture.VOICE_ONLY)
        )
        // Without the helper there is no "your voice only": Android gives other apps no sound during a call.
        assertTrue(DeviceCheck.callLine(AppCallCapture.VOICE_ONLY_NEEDS_HELPER)!!.contains("no sound during a call"))
        assertTrue(DeviceCheck.callLine(AppCallCapture.NEEDS_HELPER)!!.contains("no sound during a call"))
        assertTrue(DeviceCheck.callLine(AppCallCapture.NONE)!!.startsWith("This phone cannot record WhatsApp calls"))
    }

    @Test
    fun `each row says how far it works, with a fix where one exists`() {
        assertEquals(CheckState.OK, row("app_calls", facts()).state)
        assertEquals(CheckState.LIMITED, row("app_calls", facts(caps(33))).state)
        assertNull(row("app_calls", facts(caps(33))).fix)
        assertEquals(CheckFix.HELPER, row("app_calls", facts(caps(33, helper = false))).fix)
        assertEquals(CheckState.OFF, row("app_calls", facts(caps(29))).state)
        assertNull(row("app_calls", facts(caps(29))).fix)

        assertEquals(CheckFix.MICROPHONE, row("voice_notes", facts(caps(mic = false))).fix)
        assertEquals(CheckState.OFF, row("sim_calls", facts(phoneLine = false)).state)
        assertEquals(CheckState.OFF, row("sim_calls", facts(caps(29))).state)
        assertEquals(CheckFix.HELPER, row("sim_calls", facts(caps(helper = false))).fix)
        assertEquals(CheckState.OFF, row("captions", facts(signedIn = false)).state)
        assertEquals(CheckState.OK, row("live_translate", facts()).state)
        assertEquals(CheckFix.OVERLAY, row("live_translate", facts(caps(overlay = false))).fix)
        assertEquals(CheckState.OFF, row("live_translate", facts(liveTranslate = false)).state)
        assertEquals(CheckState.LIMITED, row("live_translate", facts(caps(33))).state)
        assertEquals(CheckFix.BATTERY, row("background", facts(battery = false)).fix)
        assertEquals("3 GB", row("memory", facts()).detail)
    }

    @Test
    fun `the Google Play build says calls are in the website version`() {
        val play = facts(caps(36, helper = false, inBuild = false))
        listOf("app_calls", "sim_calls", "live_translate").forEach {
            assertEquals(it, CheckState.OFF, row(it, play).state)
            assertEquals(it, Capabilities.NOT_IN_THIS_BUILD, row(it, play).detail)
            assertNull(it, row(it, play).fix)
        }
    }

    @Test
    fun `the report names the phone and holds nothing about the person`() {
        val f = facts(caps(33))
        val report = DeviceCheck.report(f, DeviceCheck.rows(f))
        assertTrue(
            report,
            report.startsWith("EchoLedger device check\nPhone: Xiaomi 23078PND5G · Android 14 (API 33)\nApp: 1.5.0 (website build)")
        )
        assertTrue(report, report.contains("Limited: App calls. This phone can record only your voice"))
        listOf("@", "http", "token").forEach { assertFalse(it, report.contains(it)) }
    }

    @Test
    fun `every key and state fits the server's report`() {
        DeviceCheck.rows(facts(caps(33, helper = false), battery = false)).forEach {
            assertTrue(it.key, Regex("^[a-z0-9_]{1,40}$").matches(it.key))
            assertTrue(it.detail, it.detail.length <= 300)
        }
        assertEquals(listOf("ok", "limited", "off"), CheckState.entries.map { it.wire })
    }

    @Test
    fun `a phone maker's sub-brands share its steps`() {
        assertEquals(PhoneBrand.XIAOMI, PhoneBrand.of("Xiaomi", "Redmi"))
        assertEquals(PhoneBrand.XIAOMI, PhoneBrand.of("POCO", "POCO"))
        assertEquals(PhoneBrand.OPPO, PhoneBrand.of("realme", "realme"))
        assertEquals(PhoneBrand.OPPO, PhoneBrand.of("OPPO", "OPPO"))
        assertEquals(PhoneBrand.ONEPLUS, PhoneBrand.of("OnePlus", "OnePlus"))
        assertEquals(PhoneBrand.VIVO, PhoneBrand.of("vivo", "iQOO"))
        assertEquals(PhoneBrand.HUAWEI, PhoneBrand.of("HONOR", "HONOR"))
        assertEquals(PhoneBrand.PIXEL, PhoneBrand.of("Google", "google"))
        assertEquals(PhoneBrand.SAMSUNG, PhoneBrand.of("samsung", "samsung"))
        assertEquals(PhoneBrand.OTHER, PhoneBrand.of("Nothing", "Nothing"))
        assertEquals(PhoneBrand.OTHER, PhoneBrand.of(null, null))
    }

    @Test
    fun `every brand has steps, and screens only where the maker has its own`() {
        PhoneBrand.entries.forEach {
            assertTrue(it.name, it.backgroundSteps.isNotEmpty())
            assertTrue(it.name, it.developerSteps.any { step -> step.contains("Wireless debugging") })
        }
        assertTrue(PhoneBrand.PIXEL.backgroundScreens.isEmpty())
        assertTrue(PhoneBrand.XIAOMI.backgroundScreens.isNotEmpty())
        assertTrue(PhoneBrand.XIAOMI.developerSteps.any { it.contains("USB debugging (Security settings)") })
    }
}
