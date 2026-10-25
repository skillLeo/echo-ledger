package com.echoledger.mobile.capture

import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.SourceDevice
import com.echoledger.mobile.core.Speakers
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.privilege.SetupStep
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Exhaustive checks of the capture eligibility rules, binder caller policy and package detection. */
class CaptureDecisionsTest {
    private fun caps(sdk: Int, helper: Boolean = true, mic: Boolean = true, notif: Boolean = true, overlay: Boolean = true) =
        Capabilities(sdk, helper, mic, notif, overlay)

    @Test
    fun `call capture eligibility changes exactly at Android 14`() {
        (29..37).forEach { sdk ->
            assertEquals("sdk $sdk", sdk >= 34, caps(sdk).callCaptureSupportedByOs)
            assertEquals("sdk $sdk with helper", sdk >= 34, caps(sdk, helper = true).canCaptureCalls)
            assertFalse("sdk $sdk without helper", caps(sdk, helper = false).canCaptureCalls)
        }
        assertEquals(34, Capabilities.MIN_CALL_CAPTURE_SDK)
    }

    @Test
    fun `microphone recording and the caption bubble depend only on their own permission`() {
        assertTrue(caps(29, mic = true).canRecordMic)
        assertFalse(caps(37, mic = false).canRecordMic)
        assertTrue(caps(29, overlay = true).canShowBubble)
        assertFalse(caps(37, overlay = false).canShowBubble)
    }

    @Test
    fun `setup steps are listed in a fixed order for every permission combination`() {
        for (mask in 0 until 16) {
            val mic = mask and 1 != 0
            val notif = mask and 2 != 0
            val helper = mask and 4 != 0
            val overlay = mask and 8 != 0
            val modern = caps(34, helper, mic, notif, overlay).missingSteps()
            val expected = buildList {
                if (!mic) add(SetupStep.MICROPHONE)
                if (!notif) add(SetupStep.NOTIFICATIONS)
                if (!helper) add(SetupStep.HELPER)
                if (!overlay) add(SetupStep.OVERLAY)
            }
            assertEquals("mask $mask on Android 14", expected, modern)
            assertFalse("mask $mask on Android 13", SetupStep.HELPER in caps(33, helper, mic, notif, overlay).missingSteps())
        }
    }

    @Test
    fun `nothing is missing when everything is granted`() {
        assertTrue(caps(34).missingSteps().isEmpty())
        assertTrue(caps(29, helper = false).missingSteps().isEmpty())
    }

    @Test
    fun `only the shell root and the app itself may hand over the capture binder`() {
        val own = 10_234
        assertTrue(CallerPolicy.isAllowed(2000, own))
        assertTrue(CallerPolicy.isAllowed(0, own))
        assertTrue(CallerPolicy.isAllowed(own, own))
        listOf(-1, 1, 999, 1000, 1999, 2001, 10_233, 10_235, Int.MAX_VALUE, Int.MIN_VALUE).forEach {
            assertFalse("uid $it", CallerPolicy.isAllowed(it, own))
        }
    }

    @Test
    fun `an app running as root or shell itself is still allowed and never widens access to neighbours`() {
        assertTrue(CallerPolicy.isAllowed(0, 0))
        assertTrue(CallerPolicy.isAllowed(2000, 2000))
        assertFalse(CallerPolicy.isAllowed(2000 + 1, 2000))
    }

    @Test
    fun `only the exact WhatsApp package names are recognised as call apps`() {
        assertEquals(SourceApp.WHATSAPP, SourceApp.fromPackage("com.whatsapp"))
        assertEquals(SourceApp.WHATSAPP_BUSINESS, SourceApp.fromPackage("com.whatsapp.w4b"))
        listOf(
            "COM.WHATSAPP",
            " com.whatsapp",
            "com.whatsapp ",
            "com.whatsapp.w4b.beta",
            "com.whatsapp2",
            "",
            "com.google.android.apps.meetings",
            "us.zoom.videomeetings"
        ).forEach {
            assertNull("[$it]", SourceApp.fromPackage(it))
        }
    }

    @Test
    fun `speaker names follow the channel convention for stereo and mono`() {
        assertEquals(Speakers.ME, Speakers.forChannel(1, stereo = true))
        assertEquals(Speakers.CLIENT, Speakers.forChannel(2, stereo = true))
        assertEquals(Speakers.ME, Speakers.forChannel(0, stereo = true))
        assertEquals(Speakers.ME, Speakers.forChannel(3, stereo = true))
        assertEquals(Speakers.SPEAKER, Speakers.forChannel(2, stereo = false))
        assertEquals(Speakers.SPEAKER, Speakers.forChannel(1, stereo = false))
        assertEquals("Me", Speakers.ME)
        assertEquals("Client", Speakers.CLIENT)
    }

    @Test
    fun `vocabulary labels match the shared values documented for every client`() {
        assertEquals(
            listOf(
                "whatsapp" to "WhatsApp",
                "whatsapp_business" to "WhatsApp Business",
                "whatsapp_web" to "WhatsApp Web",
                "google_meet" to "Google Meet",
                "zoom" to "Zoom",
                "microsoft_teams" to "Microsoft Teams",
                "voice_note" to "Voice note",
                "other" to "Other"
            ),
            SourceApp.entries.map { it.wire to it.label }
        )
        assertEquals(listOf("phone" to "Phone", "desktop" to "Desktop", "web" to "Web"), SourceDevice.entries.map { it.wire to it.label })
        assertEquals(SourceApp.OTHER, SourceApp.from(null))
        assertEquals(SourceApp.OTHER, SourceApp.from("WhatsApp")) // wire values are exact, lower case
        assertEquals(SourceDevice.WEB, SourceDevice.from("tablet"))
    }
}
