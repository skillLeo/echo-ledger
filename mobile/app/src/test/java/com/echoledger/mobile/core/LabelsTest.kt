package com.echoledger.mobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class LabelsTest {
    @Test
    fun `whatsapp packages map to their source app`() {
        assertEquals(SourceApp.WHATSAPP, SourceApp.fromPackage("com.whatsapp"))
        assertEquals(SourceApp.WHATSAPP_BUSINESS, SourceApp.fromPackage("com.whatsapp.w4b"))
    }

    @Test
    fun `an unknown or null package is not a call app`() {
        assertNull(SourceApp.fromPackage("com.example.other"))
        assertNull(SourceApp.fromPackage(null))
    }

    @Test
    fun `wire values round trip and fall back safely`() {
        assertEquals(SourceApp.ZOOM, SourceApp.from("zoom"))
        assertEquals(SourceApp.OTHER, SourceApp.from("nope"))
        assertEquals(SourceDevice.DESKTOP, SourceDevice.from("desktop"))
        assertEquals(SourceDevice.WEB, SourceDevice.from(null))
    }

    @Test
    fun `channel one is Me and channel two is Client when stereo`() {
        assertEquals(Speakers.ME, Speakers.forChannel(1, stereo = true))
        assertEquals(Speakers.CLIENT, Speakers.forChannel(2, stereo = true))
        assertEquals(Speakers.SPEAKER, Speakers.forChannel(1, stereo = false))
    }
}
