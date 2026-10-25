package com.echoledger.mobile.core

import com.echoledger.mobile.testsupport.ServerFixtures
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Ignore
import org.junit.Test

/** Hostile and unusual QR contents for the pairing payload parser. */
class PairingPayloadParsingTest {
    private val pinA = ServerFixtures.PIN_A
    private val pinB = ServerFixtures.PIN_B

    private fun payload(url: String, code: String = "TEST2345", pins: String = "[]", version: String = "\"v\":1,") =
        """{$version"url":"$url","code":"$code","pins":$pins}"""

    // ---- https and http ---------------------------------------------------------------------------------------------

    @Test
    fun `the exact payload produced by the server QR is accepted`() {
        // PairingService::qrPayload: json_encode with unescaped slashes, normalized code, pins from settings.
        val text = """{"v":1,"url":"https://echoledger.example.test","code":"TEST2345","pins":["$pinA","$pinB"]}"""
        val parsed = PairingPayload.parse(text)
        assertEquals(PairingPayload("https://echoledger.example.test", "TEST2345", listOf(pinA, pinB)), parsed)
    }

    @Test
    fun `a server on a non-default port keeps the port`() {
        assertEquals("https://ledger.example.com:8443", PairingPayload.parse(payload("https://ledger.example.com:8443"))?.url)
    }

    @Test
    fun `a sub-directory installation keeps its path`() {
        assertEquals("https://example.com/echoledger", PairingPayload.parse(payload("https://example.com/echoledger/"))?.url)
    }

    @Test
    fun `plain http is accepted for the emulator host and loopback only`() {
        assertEquals("http://10.0.2.2:8000", PairingPayload.parse(payload("http://10.0.2.2:8000"))?.url)
        assertEquals("http://localhost:8000", PairingPayload.parse(payload("http://localhost:8000"))?.url)
        assertEquals("http://127.0.0.1:8000", PairingPayload.parse(payload("http://127.0.0.1:8000"))?.url)
    }

    @Test
    fun `plain http is rejected for every other host including look-alikes`() {
        listOf(
            "http://example.com",
            "http://192.168.1.10:8000",
            "http://localhost.evil.example.com",
            "http://127.0.0.1.evil.example.com",
            "http://10.0.2.2.evil.example.com",
            "http://[::1]:8000",
            "http://0.0.0.0:8000"
        ).forEach { assertNull("should reject $it", PairingPayload.parse(payload(it))) }
    }

    @Test
    fun `other schemes are rejected`() {
        listOf(
            "ftp://example.com",
            "file:///etc/passwd",
            "javascript:alert(1)",
            "ws://example.com",
            "wss://example.com",
            "//example.com",
            "example.com"
        ).forEach {
            assertNull("should reject $it", PairingPayload.parse(payload(it)))
        }
    }

    @Ignore("A6.M7: a pairing payload with an upper-case HTTPS scheme is rejected")
    @Test
    fun `an upper case https scheme is the same address`() {
        assertNotNull("URL schemes are case-insensitive", PairingPayload.parse(payload("HTTPS://ledger.example.com")))
    }

    @Ignore("A6.M7: a pairing payload whose address embeds credentials is accepted")
    @Test
    fun `an address with embedded credentials is rejected so the host shown to the user is the host contacted`() {
        assertNull(PairingPayload.parse(payload("https://trusted.example.com@evil.example.net")))
        assertNull(PairingPayload.parse(payload("https://user:secret@ledger.example.com")))
    }

    // ---- garbage ------------------------------------------------------------------------------------------------------

    @Test
    fun `text that is not a JSON object yields nothing`() {
        listOf(
            "",
            "   ",
            "null",
            "[]",
            "[{}]",
            "\"string\"",
            "123",
            "true",
            "{",
            "}",
            "{}",
            "{\"url\":",
            "\u0000\u0001\u0002",
            "<html></html>",
            "TEST-2345"
        ).forEach {
            assertNull("should reject [${it.take(20)}]", PairingPayload.parse(it))
        }
    }

    @Test
    fun `fields of the wrong type are rejected`() {
        listOf(
            """{"v":1,"url":null,"code":"TEST2345"}""",
            """{"v":1,"url":5,"code":"TEST2345"}""",
            """{"v":1,"url":["https://a.example.com"],"code":"TEST2345"}""",
            """{"v":1,"url":"https://a.example.com","code":null}""",
            """{"v":1,"url":"https://a.example.com","code":{"x":1}}""",
            """{"v":1,"url":"https://a.example.com"}""",
            """{"v":1,"code":"TEST2345"}"""
        ).forEach { assertNull("should reject $it", PairingPayload.parse(it)) }
    }

    @Test
    fun `an unsupported version is rejected and a string version is read as a number`() {
        assertNull(PairingPayload.parse(payload("https://a.example.com", version = "\"v\":2,")))
        assertNull(PairingPayload.parse(payload("https://a.example.com", version = "\"v\":0,")))
        assertNull(PairingPayload.parse(payload("https://a.example.com", version = "\"v\":\"2\",")))
        assertNotNull(PairingPayload.parse(payload("https://a.example.com", version = "\"v\":\"1\",")))
        assertNotNull(PairingPayload.parse(payload("https://a.example.com", version = "")))
    }

    @Test
    fun `unknown extra fields are ignored`() {
        val text = """{"v":1,"url":"https://a.example.com","code":"TEST2345","pins":[],""" +
            """"name":"Acme","expires":"soon","nested":{"a":[1,2]}}"""
        assertEquals("https://a.example.com", PairingPayload.parse(text)?.url)
    }

    @Test
    fun `surrounding whitespace and newlines are tolerated`() {
        val text = "\n\t  " + payload("https://a.example.com") + "  \r\n"
        assertNotNull(PairingPayload.parse(text))
    }

    // ---- codes -------------------------------------------------------------------------------------------------------

    @Test
    fun `codes between four and thirty-two characters of letters digits and hyphens are accepted`() {
        listOf("ABCD", "TEST-2345", "test2345", "A".repeat(32)).forEach {
            assertEquals(it, PairingPayload.parse(payload("https://a.example.com", code = it))?.code)
        }
        listOf("ABC", "A".repeat(33), "", "AB CD", "AB_CD", "AB.CD", "AB;CD", "AB\\nCD", "AB/CD").forEach {
            assertNull("should reject code [$it]", PairingPayload.parse(payload("https://a.example.com", code = it)))
        }
    }

    // ---- oversized ---------------------------------------------------------------------------------------------------

    @Test
    fun `a payload padded to several megabytes is parsed without trouble`() {
        val padding = "x".repeat(4 * 1024 * 1024)
        val text = """{"v":1,"url":"https://a.example.com","code":"TEST2345","pins":[],"pad":"$padding"}"""
        val started = System.nanoTime()
        assertNotNull(PairingPayload.parse(text))
        assertTrue("took ${(System.nanoTime() - started) / 1_000_000} ms", (System.nanoTime() - started) < 5_000_000_000L)
    }

    @Test
    fun `thousands of pins are normalized to the valid distinct ones`() {
        val pins = (0 until 20_000).joinToString(",") { "\"" + (if (it % 2 == 0) pinA else "sha256/junk$it") + "\"" }
        val parsed = PairingPayload.parse(payload("https://a.example.com", pins = "[$pins]"))
        assertEquals(listOf(pinA), parsed?.pins)
    }

    @Test
    fun `absurdly nested JSON does not crash the app`() {
        val nested = "[".repeat(50_000) + "]".repeat(50_000)
        // The only requirement is that parsing returns (a payload or null) instead of throwing.
        PairingPayload.parse("""{"deep":$nested}""")
        PairingPayload.parse("""{"v":1,"url":"https://a.example.com","code":"TEST2345","pins":$nested}""")
    }

    @Test
    fun `an address of a hundred thousand characters does not crash the parser`() {
        PairingPayload.parse(payload("https://" + "a".repeat(100_000) + ".example.com"))
        PairingPayload.parse(payload("https://a.example.com/" + "p/".repeat(50_000)))
    }

    // ---- unicode -----------------------------------------------------------------------------------------------------

    @Test
    fun `an internationalized host name is accepted`() {
        val parsed = PairingPayload.parse(payload("https://bücher.example"))
        assertNotNull(parsed)
    }

    @Test
    fun `codes with non-ASCII characters are rejected`() {
        listOf(
            "ABCD１２３４", // full-width digits
            "ABCD١٢٣٤", // Arabic-Indic digits
            "ABCD​1234", // zero-width space
            "ÄBCD1234", // accented capital A
            "ABCD😀😀" // emoji
        ).forEach { assertNull("should reject [$it]", PairingPayload.parse(payload("https://a.example.com", code = it))) }
    }

    @Test
    fun `control characters and bidirectional overrides in the address are rejected or neutralised`() {
        listOf("https://exa‮mple.com", "https://exa\u0000mple.com", "https://exa\nmple.com", "https://exa mple.com").forEach { url ->
            val parsed = PairingPayload.parse(payload(url))
            assertTrue(
                "$url must not survive as written",
                parsed == null || (parsed.url.none { it.isISOControl() || it in '‪'..'‮' || it == ' ' })
            )
        }
    }

    @Test
    fun `non-string pins and unicode junk among the pins are dropped`() {
        val parsed = PairingPayload.parse(
            payload("https://a.example.com", pins = """["$pinA", 7, null, true, {"a":1}, ["x"], "sha256/éé", "$pinB"]""")
        )
        assertEquals(listOf(pinA, pinB), parsed?.pins)
    }

    @Test
    fun `pins that are not an array are treated as none`() {
        assertEquals(emptyList<String>(), PairingPayload.parse(payload("https://a.example.com", pins = "\"$pinA\""))?.pins)
        assertEquals(emptyList<String>(), PairingPayload.parse(payload("https://a.example.com", pins = "null"))?.pins)
        assertEquals(emptyList<String>(), PairingPayload.parse("""{"v":1,"url":"https://a.example.com","code":"TEST2345"}""")?.pins)
    }

    @Test
    fun `duplicate and padded pins are collapsed in order`() {
        val parsed = PairingPayload.parse(payload("https://a.example.com", pins = """["  $pinA  ", "$pinB", "$pinA"]"""))
        assertEquals(listOf(pinA, pinB), parsed?.pins)
    }
}
