package com.echoledger.mobile.core

import android.net.Uri
import java.security.MessageDigest
import java.security.SecureRandom
import java.util.Base64

/**
 * Signing in with an account (since v1.5, pure, unit-tested): the server address as typed, the words for the
 * server's sign-in answers, and the browser sign-in's PKCE pair (RFC 7636, S256) and state.
 */
object SignIn {
    /** "transcript.example.com" or "https://…/" → "https://transcript.example.com"; a typed scheme is kept (as pairing does). */
    fun serverUrl(typed: String): String {
        val trimmed = typed.trim().trimEnd('/')
        val withScheme = if (trimmed.startsWith("https://") || trimmed.startsWith("http://")) trimmed else "https://$trimmed"
        return withScheme
    }

    fun message(code: String?, fallback: String?): String = when (code) {
        "otp_required" -> "Enter the code from your authenticator app (or a recovery code)."
        "invalid_otp" -> "That code is wrong or used. Try the next one."
        "invalid_credentials" -> "The email or password is wrong."
        "email_not_verified" -> "Verify your email first: open the link in the email the console sent you."
        "too_many_attempts" -> "Too many tries. Wait a few minutes and try again."
        "invalid_code" -> "That sign-in did not finish. Start again."
        else -> fallback ?: "Signing in failed"
    }

    /** A browser sign-in in progress: the page to open and what to check when the phone is sent back. */
    data class Browser(val serverUrl: String, val verifier: String, val state: String, val deviceName: String) {
        fun challenge(): String =
            Base64.getUrlEncoder().withoutPadding().encodeToString(MessageDigest.getInstance("SHA-256").digest(verifier.toByteArray()))

        fun url(): String = Uri.parse("$serverUrl/device/sign-in").buildUpon()
            .appendQueryParameter("code_challenge", challenge())
            .appendQueryParameter("code_challenge_method", "S256")
            .appendQueryParameter("state", state)
            .appendQueryParameter("redirect_uri", REDIRECT)
            .appendQueryParameter("name", deviceName)
            .appendQueryParameter("platform", "android")
            .build().toString()

        companion object {
            const val REDIRECT = "echoledger://signed-in"
            private val random = SecureRandom()

            fun start(serverUrl: String, deviceName: String): Browser = Browser(serverUrl, token(48), token(24), deviceName)

            /** URL-safe random text of [bytes] bytes (verifier: 64 characters, inside RFC 7636's 43-128). */
            private fun token(bytes: Int): String =
                ByteArray(bytes).also(random::nextBytes).let { Base64.getUrlEncoder().withoutPadding().encodeToString(it) }
        }
    }

    /** The code and state of echoledger://signed-in?code=…&state=…; null for any other link. */
    fun callback(data: Uri?): Pair<String?, String?>? = data?.takeIf { it.scheme == "echoledger" && it.host == "signed-in" }?.let {
        it.getQueryParameter("code") to
            it.getQueryParameter("state")
    }
}
