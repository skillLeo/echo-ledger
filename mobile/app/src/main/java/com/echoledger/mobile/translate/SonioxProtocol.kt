package com.echoledger.mobile.translate

import com.echoledger.mobile.data.LiveTranslateToken
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put

/** How fast Live Translate finishes a sentence (plan B1): a shorter wait after speech is faster but splits more. */
enum class SpeedPreset(val label: String, val endpointDelayMs: Int) {
    FASTEST("Fastest", 500),
    BALANCED("Balanced", 1_000),
    ACCURATE("Accurate", 1_500);

    companion object {
        fun of(name: String?): SpeedPreset = entries.firstOrNull { it.name.equals(name, ignoreCase = true) } ?: BALANCED
    }
}

/** One translated line: what was said (in its language) and its translation; [final] once Soniox has settled it. */
data class TranslatedLine(val original: String, val translation: String, val language: String?, val final: Boolean)

/** What Soniox sent back: settled tokens, the tokens still being heard, or an error / the end of the stream. */
data class SonioxReply(
    val finalOriginal: String = "",
    val finalTranslation: String = "",
    val partialOriginal: String = "",
    val partialTranslation: String = "",
    val language: String? = null,
    val endpoint: Boolean = false,
    val finished: Boolean = false,
    val errorCode: Int? = null,
    val errorMessage: String? = null
)

/**
 * Soniox's real-time protocol (soniox.com/docs, stt-rt v5): the first text frame is the configuration, then raw 16 kHz
 * mono PCM frames; each reply carries tokens, final or not, marked "original" or "translation"; `<end>` marks the end
 * of a sentence when endpoint detection is on. Pure, so it is tested without a network.
 */
object SonioxProtocol {
    const val SAMPLE_RATE = 16_000
    private const val END_TOKEN = "<end>"

    /** The first frame: the temporary key, the model, the audio format, the language hints and the translation. */
    fun config(token: LiveTranslateToken, speed: SpeedPreset): String = buildJsonObject {
        put("api_key", token.apiKey)
        put("model", token.model)
        put("audio_format", "pcm_s16le")
        put("sample_rate", SAMPLE_RATE)
        put("num_channels", 1)
        if (token.languageHints.isNotEmpty()) {
            put(
                "language_hints",
                buildJsonArray {
                    token.languageHints.forEach { add(JsonPrimitive(it)) }
                }
            )
        }
        put("enable_endpoint_detection", true)
        put("max_endpoint_delay_ms", speed.endpointDelayMs)
        put(
            "translation",
            buildJsonObject {
                put("type", token.translation.type)
                put("target_language", token.translation.targetLanguage)
            }
        )
    }.toString()

    /** One reply frame; unknown fields are ignored, a broken frame is an error reply (never a crash). */
    fun parse(frame: String): SonioxReply {
        val json = runCatching { kotlinx.serialization.json.Json.parseToJsonElement(frame).jsonObject }.getOrNull()
            ?: return SonioxReply(errorCode = -1, errorMessage = "Soniox sent something that is not JSON.")
        val errorCode = (json["error_code"] as? JsonPrimitive)?.intOrNull
        if (errorCode != null) {
            return SonioxReply(errorCode = errorCode, errorMessage = (json["error_message"] as? JsonPrimitive)?.contentOrNull)
        }
        val tokens = (json["tokens"] as? JsonArray).orEmpty().mapNotNull { it as? JsonObject }
        val finalOriginal = StringBuilder()
        val finalTranslation = StringBuilder()
        val partialOriginal = StringBuilder()
        val partialTranslation = StringBuilder()
        var language: String? = null
        var endpoint = false
        for (t in tokens) {
            val text = t["text"]?.jsonPrimitive?.contentOrNull ?: continue
            if (text == END_TOKEN) {
                endpoint = true
                continue
            }
            val final = t["is_final"]?.jsonPrimitive?.booleanOrNull == true
            val translated = t["translation_status"]?.jsonPrimitive?.contentOrNull == "translation"
            if (!translated) t["language"]?.jsonPrimitive?.contentOrNull?.let { language = it }
            when {
                final && translated -> finalTranslation.append(text)
                final -> finalOriginal.append(text)
                translated -> partialTranslation.append(text)
                else -> partialOriginal.append(text)
            }
        }
        return SonioxReply(
            finalOriginal = finalOriginal.toString(),
            finalTranslation = finalTranslation.toString(),
            partialOriginal = partialOriginal.toString(),
            partialTranslation = partialTranslation.toString(),
            language = language,
            endpoint = endpoint,
            finished = (json["finished"] as? JsonPrimitive)?.booleanOrNull == true
        )
    }

    /** Holds an open stream while nobody speaks (Soniox ends one after 20 s without audio or this). */
    const val KEEPALIVE: String = """{"type":"keepalive"}"""

    /** The empty frame that tells Soniox the audio has ended (it then sends the last tokens and `finished`). */
    const val END_OF_AUDIO: String = ""
}
