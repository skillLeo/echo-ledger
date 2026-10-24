package com.echoledger.mobile.net

import okhttp3.CertificatePinner
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull

/**
 * SPKI pin handling (pure parsing unit-tested). Pins arrive at pairing as "sha256/<base64>" (current + backup) and
 * apply only to the EchoLedger server host; provider WebSockets use normal certificate validation.
 */
object Pins {
    private val PIN = Regex("^sha256/[A-Za-z0-9+/]{43}=$")

    fun normalize(pins: List<String>): List<String> = pins.map { it.trim() }.filter { PIN.matches(it) }.distinct()

    fun pinnerFor(baseUrl: String, pins: List<String>): CertificatePinner? {
        val host = baseUrl.toHttpUrlOrNull()?.host ?: return null
        val valid = normalize(pins)
        if (valid.isEmpty()) return null
        return CertificatePinner.Builder().apply { valid.forEach { add(host, it) } }.build()
    }

    fun isLocalDev(baseUrl: String): Boolean {
        val url = baseUrl.toHttpUrlOrNull() ?: return false
        return !url.isHttps && url.host in setOf("10.0.2.2", "localhost", "127.0.0.1")
    }
}
