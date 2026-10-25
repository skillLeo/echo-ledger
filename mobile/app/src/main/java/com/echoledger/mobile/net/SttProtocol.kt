package com.echoledger.mobile.net

import android.util.Base64
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.longOrNull
import kotlinx.serialization.json.put

/**
 * Provider streaming message parsers (pure; fixtures in tests).
 * AssemblyAI v3: docs/research/stt-assemblyai.md R3 · ElevenLabs realtime: docs/research/stt-elevenlabs.md R8.
 */
sealed interface SttEvent {
    data object Started : SttEvent

    data class Partial(val text: String) : SttEvent

    data class Final(val text: String, val startMs: Long?, val endMs: Long?) : SttEvent

    data object Terminated : SttEvent

    data class Error(val message: String) : SttEvent
}

object SttProtocol {
    private val json = Json { ignoreUnknownKeys = true }

    const val ASSEMBLYAI_TERMINATE = """{"type":"Terminate"}"""

    fun parseAssemblyAi(raw: String): SttEvent? {
        val obj = runCatching { json.parseToJsonElement(raw).jsonObject }.getOrNull() ?: return null
        return when (obj.str("type")) {
            "Begin" -> SttEvent.Started

            "Turn" -> {
                val text = obj.str("transcript").orEmpty().trim()
                val endOfTurn = obj["end_of_turn"]?.jsonPrimitive?.booleanOrNull ?: false
                val formatted = obj["turn_is_formatted"]?.jsonPrimitive?.booleanOrNull ?: false
                when {
                    endOfTurn && formatted -> {
                        val words = runCatching { obj["words"]?.jsonArray }.getOrNull()
                        val start = words?.firstNotNullOfOrNull { it.field("start")?.longOrNull }
                        val end = words?.lastOrNull { it.field("end") != null }?.field("end")?.longOrNull
                        if (text.isEmpty()) null else SttEvent.Final(text, start, end)
                    }

                    endOfTurn -> null

                    // the formatted final follows
                    else -> SttEvent.Partial(text)
                }
            }

            "Termination" -> SttEvent.Terminated

            "Error" -> SttEvent.Error(obj.str("error") ?: "streaming error")

            else -> null
        }
    }

    fun parseElevenLabs(raw: String): SttEvent? {
        val obj = runCatching { json.parseToJsonElement(raw).jsonObject }.getOrNull() ?: return null
        val type = obj.str("message_type") ?: return null
        val text = obj.str("text").orEmpty().trim()
        return when (type) {
            "session_started" -> SttEvent.Started

            "partial_transcript" -> if (text.isEmpty()) null else SttEvent.Partial(text)

            "committed_transcript", "committed_transcript_with_timestamps" -> {
                if (text.isEmpty()) return null
                val words = runCatching { obj["words"]?.jsonArray }.getOrNull()
                val start = words?.firstNotNullOfOrNull { it.field("start")?.doubleOrNull }?.let { (it * 1000).toLong() }
                val end = words?.lastOrNull {
                    it.field("end")?.doubleOrNull != null
                }?.field("end")?.doubleOrNull?.let { (it * 1000).toLong() }
                SttEvent.Final(text, start, end)
            }

            else -> if (obj.containsKey("error") || type.contains("error")) SttEvent.Error(obj.str("error") ?: type) else null
        }
    }

    fun elevenLabsAudio(pcm: ByteArray, commit: Boolean = false): String = buildJsonObject {
        put("message_type", "input_audio_chunk")
        put("audio_base_64", Base64.encodeToString(pcm, Base64.NO_WRAP))
        put("commit", commit)
        put("sample_rate", 16_000)
    }.toString()

    /** AssemblyAI's billed seconds from a `Termination` message (`session_duration_seconds`), else null. */
    fun assemblyAiSessionSeconds(raw: String): Long? {
        val obj = runCatching { json.parseToJsonElement(raw) as? JsonObject }.getOrNull() ?: return null
        if (obj.str("type") != "Termination") return null
        val seconds = obj["session_duration_seconds"] as? JsonPrimitive ?: return null
        return seconds.longOrNull ?: seconds.doubleOrNull?.takeIf { it.isFinite() && it >= 0 }?.let { kotlin.math.ceil(it).toLong() }
    }

    private fun JsonObject.str(key: String): String? = (this[key] as? JsonPrimitive)?.contentOrNull

    /** A primitive field of a word; null for words that are not objects or fields of another type (never throws). */
    private fun JsonElement.field(key: String): JsonPrimitive? = ((this as? JsonObject)?.get(key)) as? JsonPrimitive
}
