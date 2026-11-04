package com.echoledger.mobile.core

import com.echoledger.mobile.testsupport.AppTest
import java.security.MessageDigest
import java.util.Base64
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** Signing in with an account (since v1.5): the address, the answers in words, and the browser sign-in's PKCE pair. */
class SignInTest : AppTest() {
    @Test
    fun `an address gets https when it has no scheme, and loses a trailing slash`() {
        assertEquals("https://echo.example.test", SignIn.serverUrl(" echo.example.test/ "))
        assertEquals("http://10.0.2.2:8000", SignIn.serverUrl("http://10.0.2.2:8000/"))
        assertEquals("https://echo.example.test", SignIn.serverUrl("https://echo.example.test"))
    }

    @Test
    fun `the browser sign-in sends an S256 challenge of a long random verifier and checks the state on return`() {
        val one = SignIn.Browser.start("https://echo.example.test", "S25 Ultra")
        val two = SignIn.Browser.start("https://echo.example.test", "S25 Ultra")
        assertTrue(one.verifier.length in 43..128)
        assertTrue(one.verifier != two.verifier && one.state != two.state)
        val expected = Base64.getUrlEncoder().withoutPadding().encodeToString(
            MessageDigest.getInstance("SHA-256").digest(one.verifier.toByteArray())
        )
        assertEquals(expected, one.challenge())
        val url = android.net.Uri.parse(one.url())
        assertEquals("/device/sign-in", url.path)
        assertEquals(one.challenge(), url.getQueryParameter("code_challenge"))
        assertEquals("S256", url.getQueryParameter("code_challenge_method"))
        assertEquals("echoledger://signed-in", url.getQueryParameter("redirect_uri"))
        assertEquals(one.state, url.getQueryParameter("state"))
        assertEquals("c0de" to "st", SignIn.callback(android.net.Uri.parse("echoledger://signed-in?code=c0de&state=st")))
        assertNull(SignIn.callback(android.net.Uri.parse("echoledger://open?link=x")))
    }

    @Test
    fun `the sign-in answers read in plain words`() {
        assertEquals("The email or password is wrong.", SignIn.message("invalid_credentials", null))
        assertTrue(SignIn.message("otp_required", null).contains("authenticator"))
        assertEquals("Server says no", SignIn.message(null, "Server says no"))
    }
}
