package com.echoledger.mobile.core

import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.net.Pins
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonObject
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull

/**
 * The pairing QR shown in Admin → Devices → Pair a device (docs/API.md "Pairing"):
 * {"v":1,"url":"https://…","code":"XXXXXXXX","pins":["sha256/…"]}. Pure; unit-tested.
 */
data class PairingPayload(val url: String, val code: String, val pins: List<String>) {
    companion object {
        private val CODE = Regex("^[A-Za-z0-9-]{4,32}$")

        /** Returns null for anything that is not a well-formed EchoLedger pairing payload (any other QR code). */
        fun parse(text: String): PairingPayload? {
            val obj = runCatching { ApiJson.parseToJsonElement(text.trim()).jsonObject }.getOrNull() ?: return null
            val version = (obj["v"] as? JsonPrimitive)?.intOrNull
            if (version != null && version != 1) return null
            val url = (obj["url"] as? JsonPrimitive)?.contentOrNull?.trim()?.trimEnd('/') ?: return null
            if (url.toHttpUrlOrNull() == null || (!url.startsWith("https://") && !Pins.isLocalDev(url))) return null
            val code = (obj["code"] as? JsonPrimitive)?.contentOrNull?.trim() ?: return null
            if (!CODE.matches(code)) return null
            val pins = (obj["pins"] as? JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.contentOrNull }.orEmpty()
            return PairingPayload(url, code, Pins.normalize(pins))
        }
    }
}
