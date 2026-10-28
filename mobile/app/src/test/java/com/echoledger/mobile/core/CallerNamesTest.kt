package com.echoledger.mobile.core

import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** Caller names (v1.2): the name in WhatsApp's call notification, matched to the call recording it belongs to. */
class CallerNamesTest {
    @After
    fun clear() = CallerNames.clear()

    @Test
    fun `reads the name of a WhatsApp call notification and ignores messages and placeholders`() {
        assertEquals("Omar Khan", CallerNames.callerOf(CallerNames.WHATSAPP, "call", "Omar Khan", "Ongoing voice call"))
        assertEquals("Sara", CallerNames.callerOf(CallerNames.WHATSAPP_BUSINESS, null, " Sara ", "Incoming video call"))
        assertEquals("عمر", CallerNames.callerOf(CallerNames.WHATSAPP, null, "عمر", "وائس کال"))
        assertNull(CallerNames.callerOf(CallerNames.WHATSAPP, "msg", "Omar Khan", "See you tomorrow"))
        assertNull(CallerNames.callerOf(CallerNames.WHATSAPP, "call", "WhatsApp", "Ongoing voice call"))
        assertNull(CallerNames.callerOf("com.example.other", "call", "Omar Khan", "Ongoing voice call"))
    }

    @Test
    fun `gives a call recording the name seen during it, or just before it rang`() {
        CallerNames.record(CallerNames.WHATSAPP, "Omar Khan", 1_000_000)
        CallerNames.record(CallerNames.WHATSAPP, "Omar Khan", 1_060_000)
        CallerNames.record(CallerNames.WHATSAPP_BUSINESS, "Acme Support", 1_030_000)

        assertEquals("Omar Khan", CallerNames.forCall("whatsapp", 1_050_000, 1_200_000))
        assertEquals("Acme Support", CallerNames.forCall("whatsapp_business", 1_050_000, 1_200_000))
        assertNull(CallerNames.forCall("whatsapp", 2_000_000, 2_100_000))
        assertNull(CallerNames.forCall("zoom", 1_050_000, 1_200_000))
    }

    @Test
    fun `picks the name seen most often during the call`() {
        CallerNames.record(CallerNames.WHATSAPP, "Group: Sales", 1_000_000)
        CallerNames.record(CallerNames.WHATSAPP, "Omar Khan", 1_010_000)
        CallerNames.record(CallerNames.WHATSAPP, "Omar Khan", 1_020_000)

        assertEquals("Omar Khan", CallerNames.forCall("whatsapp", 1_000_000, 1_100_000))
    }
}
