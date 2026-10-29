package com.echoledger.mobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class CallTargetsTest {
    @Test
    fun `the call-app list holds the call apps and no game or dialer`() {
        val expected = setOf(
            "com.whatsapp", "com.whatsapp.w4b", "com.google.android.apps.tachyon", "com.google.android.apps.meetings",
            "us.zoom.videomeetings", "com.microsoft.teams", "org.telegram.messenger", "org.telegram.messenger.web",
            "org.thoughtcrime.securesms", "com.facebook.orca", "com.instagram.android", "com.skype.raider", "com.Slack",
            "com.discord"
        )
        assertEquals(expected, CallTargets.DEFAULT_PACKAGES)
        assertTrue(CallTargets.isTarget("com.whatsapp.w4b"))
        assertFalse(CallTargets.isTarget("com.google.android.dialer"))
        assertFalse(CallTargets.isTarget("com.supercell.clashofclans"))
        assertFalse(CallTargets.isTarget("com.pubg.imobile"))
        assertFalse(CallTargets.isTarget(null))
    }

    @Test
    fun `a uid shared with other packages still finds WhatsApp Business`() {
        assertEquals("com.whatsapp.w4b", CallTargets.firstTarget(arrayOf("com.example.helper", "com.whatsapp.w4b")))
        assertNull(CallTargets.firstTarget(arrayOf("com.example.helper")))
        assertNull(CallTargets.firstTarget(null))
    }

    @Test
    fun `every known app is labelled with its own source`() {
        assertEquals(SourceApp.WHATSAPP, SourceApp.fromPackage(CallTargets.WHATSAPP))
        assertEquals(SourceApp.WHATSAPP_BUSINESS, SourceApp.fromPackage(CallTargets.WHATSAPP_BUSINESS))
        assertEquals(SourceApp.GOOGLE_MEET, SourceApp.fromPackage("com.google.android.apps.tachyon"))
        assertEquals(SourceApp.ZOOM, SourceApp.fromPackage("us.zoom.videomeetings"))
        assertEquals(SourceApp.MICROSOFT_TEAMS, SourceApp.fromPackage("com.microsoft.teams"))
        assertEquals(SourceApp.TELEGRAM, SourceApp.fromPackage("org.telegram.messenger"))
        assertEquals(SourceApp.SIGNAL, SourceApp.fromPackage("org.thoughtcrime.securesms"))
        assertEquals(SourceApp.MESSENGER, SourceApp.fromPackage("com.facebook.orca"))
        assertEquals(SourceApp.DISCORD, SourceApp.fromPackage("com.discord"))
        CallTargets.DEFAULT_PACKAGES.forEach { assertTrue(it, SourceApp.fromPackage(it) !in setOf(null, SourceApp.OTHER)) }
    }

    @Test
    fun `an app the person added is watched and recorded as Other`() {
        val list = CallTargets.packages(listOf(" com.example.calls ", "not a package", "", "com.whatsapp"))
        assertTrue("com.example.calls" in list)
        assertFalse("not a package" in list)
        assertEquals(CallTargets.DEFAULT_PACKAGES.size + 1, list.size)
        assertEquals("com.example.calls", CallTargets.firstTarget(arrayOf("com.example.calls"), list))
        assertNull(CallTargets.firstTarget(arrayOf("com.example.calls")))
        assertEquals(SourceApp.OTHER, SourceApp.fromPackage("com.example.calls"))
    }

    @Test
    fun `package names are cleaned`() {
        assertEquals("com.example.app", CallTargets.clean("  com.example.app "))
        assertNull(CallTargets.clean("example"))
        assertNull(CallTargets.clean("com..example"))
        assertNull(CallTargets.clean("com.example.app; rm -rf"))
        assertNull(CallTargets.clean("c." + "a".repeat(130)))
        assertNull(CallTargets.clean(null))
    }
}
