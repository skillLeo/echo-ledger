package com.echoledger.mobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Test

class PairingPayloadTest {
    private val pinA = "sha256/brzvtCELCIZUo4sD/qPX0ccRtPsd3DY6RfmxpOU9oB4="
    private val pinB = "sha256/s/tdAOmUzd8syaTuqfgGvFcn6DzA5Cmb+Vby1ST+U3Y="

    @Test
    fun `parses the payload the server puts in the pairing QR`() {
        val text = """{"v":1,"url":"https://transcript.skillleo.com","code":"PZPYNF0D","pins":["$pinA","$pinB"]}"""
        val payload = PairingPayload.parse(text)
        assertNotNull(payload)
        assertEquals("https://transcript.skillleo.com", payload!!.url)
        assertEquals("PZPYNF0D", payload.code)
        assertEquals(listOf(pinA, pinB), payload.pins)
    }

    @Test
    fun `trims whitespace and a trailing slash, and tolerates a missing version`() {
        val payload = PairingPayload.parse("""  {"url":"https://ledger.example.com/","code":"ABCD-1234","pins":[]}  """)
        assertEquals("https://ledger.example.com", payload?.url)
        assertEquals("ABCD-1234", payload?.code)
    }

    @Test
    fun `rejects anything that is not a pairing payload`() {
        assertNull(PairingPayload.parse("PZPYNF0D"))
        assertNull(PairingPayload.parse("https://example.com/some-link"))
        assertNull(PairingPayload.parse("""{"hello":"world"}"""))
        assertNull(PairingPayload.parse(""))
    }

    @Test
    fun `rejects unknown versions, plain http and malformed codes`() {
        assertNull(PairingPayload.parse("""{"v":2,"url":"https://a.example.com","code":"ABCD1234"}"""))
        assertNull(PairingPayload.parse("""{"v":1,"url":"http://a.example.com","code":"ABCD1234"}"""))
        assertNull(PairingPayload.parse("""{"v":1,"url":"https://a.example.com","code":"AB"}"""))
        assertNull(PairingPayload.parse("""{"v":1,"url":"https://a.example.com","code":"ABCD 1234; drop"}"""))
        assertNull(PairingPayload.parse("""{"v":1,"url":"not a url","code":"ABCD1234"}"""))
    }

    @Test
    fun `allows plain http only for local development hosts`() {
        assertEquals("http://10.0.2.2:8000", PairingPayload.parse("""{"v":1,"url":"http://10.0.2.2:8000","code":"ABCD1234"}""")?.url)
    }

    @Test
    fun `drops malformed pins but keeps the valid ones`() {
        val payload = PairingPayload.parse("""{"v":1,"url":"https://a.example.com","code":"ABCD1234","pins":["$pinA","junk",42]}""")
        assertEquals(listOf(pinA), payload?.pins)
    }
}
