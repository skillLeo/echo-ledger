package com.echoledger.mobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class CallTargetsTest {
    @Test
    fun `both WhatsApp and WhatsApp Business are call targets, nothing else`() {
        assertEquals(setOf("com.whatsapp", "com.whatsapp.w4b"), CallTargets.PACKAGES)
        assertTrue(CallTargets.isTarget("com.whatsapp.w4b"))
        assertFalse(CallTargets.isTarget("com.google.android.dialer"))
        assertFalse(CallTargets.isTarget(null))
    }

    @Test
    fun `a uid shared with other packages still finds WhatsApp Business`() {
        assertEquals("com.whatsapp.w4b", CallTargets.firstTarget(arrayOf("com.example.helper", "com.whatsapp.w4b")))
        assertNull(CallTargets.firstTarget(arrayOf("com.example.helper")))
        assertNull(CallTargets.firstTarget(null))
    }

    @Test
    fun `detection labels follow the same list`() {
        assertEquals(SourceApp.WHATSAPP, SourceApp.fromPackage(CallTargets.WHATSAPP))
        assertEquals(SourceApp.WHATSAPP_BUSINESS, SourceApp.fromPackage(CallTargets.WHATSAPP_BUSINESS))
        CallTargets.PACKAGES.forEach { assertTrue(it, SourceApp.fromPackage(it) != null) }
    }
}
