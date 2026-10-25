package com.echoledger.mobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Test

class PairingPayloadTest {
    private val pinA = "sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
    private val pinB = "sha256/BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBE="

    @Test
    fun `parses the payload the server puts in the pairing QR`() {
        val text = """{"v":1,"url":"https://echoledger.example.test","code":"TEST2345","pins":["$pinA","$pinB"]}"""
        val payload = PairingPayload.parse(text)
        assertNotNull(payload)
        assertEquals("https://echoledger.example.test", payload!!.url)
        assertEquals("TEST2345", payload.code)
        assertEquals(listOf(pinA, pinB), payload.pins)
    }

    @Test
    fun `trims whitespace and a trailing slash, and tolerates a missing version`() {
        val payload = PairingPayload.parse("""  {"url":"https://ledger.example.test/","code":"ABCD-1234","pins":[]}  """)
        assertEquals("https://ledger.example.test", payload?.url)
        assertEquals("ABCD-1234", payload?.code)
    }

    @Test
    fun `rejects anything that is not a pairing payload`() {
        assertNull(PairingPayload.parse("TEST2345"))
        assertNull(PairingPayload.parse("https://example.test/some-link"))
        assertNull(PairingPayload.parse("""{"hello":"world"}"""))
        assertNull(PairingPayload.parse(""))
    }

    @Test
    fun `rejects unknown versions, plain http and malformed codes`() {
        assertNull(PairingPayload.parse("""{"v":2,"url":"https://a.example.test","code":"ABCD1234"}"""))
        assertNull(PairingPayload.parse("""{"v":1,"url":"http://a.example.test","code":"ABCD1234"}"""))
        assertNull(PairingPayload.parse("""{"v":1,"url":"https://a.example.test","code":"AB"}"""))
        assertNull(PairingPayload.parse("""{"v":1,"url":"https://a.example.test","code":"ABCD 1234; drop"}"""))
        assertNull(PairingPayload.parse("""{"v":1,"url":"not a url","code":"ABCD1234"}"""))
    }

    @Test
    fun `allows plain http only for local development hosts`() {
        assertEquals("http://10.0.2.2:8000", PairingPayload.parse("""{"v":1,"url":"http://10.0.2.2:8000","code":"ABCD1234"}""")?.url)
    }

    @Test
    fun `drops malformed pins but keeps the valid ones`() {
        val payload = PairingPayload.parse("""{"v":1,"url":"https://a.example.test","code":"ABCD1234","pins":["$pinA","junk",42]}""")
        assertEquals(listOf(pinA), payload?.pins)
    }
}
