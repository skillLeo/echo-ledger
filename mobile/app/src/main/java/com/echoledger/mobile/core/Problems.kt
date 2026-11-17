package com.echoledger.mobile.core

import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.net.EMPTY_ANSWER
import com.echoledger.mobile.net.HTTPS_ONLY
import com.echoledger.mobile.net.NotPairedException
import com.echoledger.mobile.net.UNREADABLE_ANSWER
import java.io.IOException

/**
 * What went wrong, in words for people (since v1.8.1, bug P-024): one mapper from a failure to a sentence. A
 * connection's own text ("Unable to resolve host …"), an exception's name or a bare HTTP status never reaches the
 * screen; they stay in Copy diagnostics. The server's own message is used when it is written for people (and names no
 * engine or vendor).
 */
object Problems {
    const val OFFLINE = "Can't reach the server. Check the connection and try again."
    const val SERVER = "The server had a problem. Try again in a minute."
    const val SIGN_IN = "Sign in on this phone first."
    const val CERTIFICATE = "The server's certificate is not the one this phone expects. Check the server address."

    /** The sentence for [e]; [fallback] says what did not work ("The link could not be made.") when nothing better is known. */
    fun text(e: Throwable?, fallback: String): String = when (e) {
        null -> fallback

        is NotPairedException -> SIGN_IN

        is ApiException -> when {
            e.status >= 500 -> SERVER
            else -> VendorNames.plain(e.message?.takeUnless(::technical), fallback)
        }

        // A pinned certificate that does not match: never "can't reach", which would send people looking at the network.
        is javax.net.ssl.SSLException -> CERTIFICATE

        // The client's own sentences for an empty or unreadable answer are for people already.
        is IOException -> e.message?.takeIf { it == EMPTY_ANSWER || it == UNREADABLE_ANSWER } ?: OFFLINE

        is IllegalStateException -> e.message?.takeIf { it == SIGN_IN } ?: fallback

        // The client's own check of a typed address; any other argument error is a library's words.
        is IllegalArgumentException -> e.message?.takeIf { it == HTTPS_ONLY } ?: fallback

        else -> fallback
    }

    /** A server answer without words for people: "Request failed (404)", or an exception's text passed through. */
    private fun technical(message: String): Boolean = message.startsWith("Request failed (") ||
        Regex("""\b[a-z]+(\.[a-z]+)+\.[A-Z]\w*(Exception|Error)\b""").containsMatchIn(message)
}
