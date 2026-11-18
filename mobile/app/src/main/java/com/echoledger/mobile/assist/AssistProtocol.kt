package com.echoledger.mobile.assist

import com.echoledger.mobile.ui.v2.assist.AssistMessage
import com.echoledger.mobile.ui.v2.assist.EndStatus
import com.echoledger.mobile.ui.v2.assist.LiveMode
import com.echoledger.mobile.ui.v2.assist.MeetingType
import com.echoledger.mobile.ui.v2.assist.ModeReason
import com.echoledger.mobile.ui.v2.assist.SendKind
import com.echoledger.mobile.ui.v2.assist.SuggestionKind
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put

/**
 * The Live Assist relay's messages (docs/API_ASSIST.md 1.9.0-q, "WebSocket (relay)" and "HTTP mode"): what the phone
 * sends, framed with its `seq` on the relay, and what it reads back (the same messages on both transports). Words stay out of logs ([toString] of a message never shows them).
 */
sealed class ClientMessage(val type: String) {
    /** The fields given, each replacing what the relay had (an empty value clears it); see [AssistContext]. */
    class ContextUpdate(val fields: JsonObject) : ClientMessage("context_update")

    class TurnPartial(val turn: Turn) : ClientMessage("turn_partial")

    class TurnFinal(val turn: Turn) : ClientMessage("turn_final")

    class Command(val kind: SendKind, val turnId: String? = null, val suggestionId: String? = null, val language: String? = null) :
        ClientMessage(kind.wire)

    class TokenRefresh(val token: String) : ClientMessage("token_refresh")

    data object PracticeNext : ClientMessage("practice_next")

    /** The JSON text with this connection's [seq]. */
    fun frame(seq: Int): String = json(seq)

    /** The JSON text for HTTP mode (contract 1.9.0-q): one POST per message, no `seq`. */
    fun body(): String = json(null)

    private fun json(seq: Int?): String = buildJsonObject {
        put("type", type)
        seq?.let { put("seq", it) }
        when (this@ClientMessage) {
            is ContextUpdate -> fields.forEach { (k, v) -> put(k, v) }

            is TurnPartial -> turn.into(this)

            is TurnFinal -> turn.into(this)

            is Command -> {
                turnId?.let { put("turn_id", it) }
                suggestionId?.let { put("suggestion_id", it) }
                language?.let { put("language", it) }
            }

            is TokenRefresh -> put("token", token)

            PracticeNext -> Unit
        }
    }.toString()

    override fun toString(): String = "ClientMessage($type)"
}

/** One caption turn for the relay: `other` (the person on the call) or `me`; [turnId] shared by its partials and final. */
data class Turn(
    val turnId: String,
    val other: Boolean,
    val text: String,
    val startMs: Long? = null,
    val endMs: Long? = null,
    val silenceMs: Long? = null,
    val language: String? = null
) {
    internal fun into(o: kotlinx.serialization.json.JsonObjectBuilder) {
        o.put("turn_id", turnId)
        o.put("channel", if (other) "other" else "me")
        o.put("text", text)
        language?.let { o.put("language", it) }
        startMs?.let { o.put("start_ms", it) }
        endMs?.let { o.put("end_ms", it) }
        silenceMs?.let { o.put("silence_ms", it) }
    }

    override fun toString(): String = "Turn($turnId, other: $other, <${text.length} chars>)"
}

/** What the relay sends: the card's messages, plus the connection's own. */
sealed interface RelayEvent {
    data class Ready(
        val sessionId: String,
        val mode: LiveMode,
        val allowedModes: List<LiveMode>,
        val resumed: Boolean,
        val practice: Boolean,
        val expiresAt: String?,
        val messagesPerSecond: Int,
        val maxMessageBytes: Int
    ) : RelayEvent

    data class TokenRefreshed(val expiresAt: String?) : RelayEvent

    data class Error(
        val code: String,
        val message: String,
        val retry: Boolean,
        val fatal: Boolean,
        val suggestionId: String?,
        val inReplyTo: Int?
    ) : RelayEvent

    /** Any message the card shows. */
    data class Card(val message: AssistMessage) : RelayEvent
}

/** Reads the relay's messages (an unknown `type` or bad JSON is null: the relay may add messages). */
object RelayDecoder {
    fun decode(text: String, receivedAtMs: Long, meetingType: MeetingType?): RelayEvent? {
        val o = runCatching { com.echoledger.mobile.data.ApiJson.parseToJsonElement(text).jsonObject }.getOrNull() ?: return null
        return when (o.str("type")) {
            "ready" -> RelayEvent.Ready(
                sessionId = o.str("session_id").orEmpty(),
                mode = LiveMode.from(o.str("mode")),
                allowedModes = o.list("allowed_modes").map(LiveMode::from),
                resumed = o.bool("resumed") ?: false,
                practice = o.bool("practice") ?: false,
                expiresAt = o.str("expires_at"),
                messagesPerSecond = o.obj("limits")?.int("messages_per_second")?.takeIf { it > 0 } ?: AssistOutbox.DEFAULT_RATE,
                maxMessageBytes = o.obj("limits")?.int("max_message_bytes")?.takeIf { it > 0 } ?: MAX_MESSAGE_BYTES
            )

            "token_refreshed" -> RelayEvent.TokenRefreshed(o.str("expires_at"))

            "error" -> RelayEvent.Error(
                code = o.str("code").orEmpty(),
                message = o.str("message").orEmpty(),
                retry = o.bool("retry") ?: false,
                fatal = o.bool("fatal") ?: false,
                suggestionId = o.str("suggestion_id"),
                inReplyTo = o.int("in_reply_to")
            )

            "suggestion_start" -> RelayEvent.Card(
                AssistMessage.SuggestionStart(
                    suggestionId = o.str("suggestion_id").orEmpty(),
                    turnId = o.str("turn_id"),
                    kind = kind(o.str("kind")),
                    speculative = o.bool("speculative") ?: false,
                    replaces = o.str("replaces"),
                    mode = LiveMode.from(o.str("mode")),
                    draft = o.bool("draft") ?: false,
                    receivedAtMs = receivedAtMs
                )
            )

            "token" -> RelayEvent.Card(AssistMessage.Token(o.str("suggestion_id").orEmpty(), o.str("text").orEmpty()))

            "suggestion_end" -> RelayEvent.Card(AssistMessage.SuggestionEnd(o.str("suggestion_id").orEmpty(), status(o.str("status"))))

            "skip" -> RelayEvent.Card(AssistMessage.Skip(o.str("suggestion_id"), o.str("turn_id")))

            "mode_changed" -> RelayEvent.Card(
                AssistMessage.ModeChanged(LiveMode.from(o.str("to") ?: o.str("mode")), reason(o.str("reason")))
            )

            "practice_question" -> RelayEvent.Card(
                AssistMessage.PracticeQuestion(
                    o.str("question_id").orEmpty(),
                    o.str("turn_id").orEmpty(),
                    o.int("index") ?: 0,
                    o.int("total") ?: 0,
                    o.str("text").orEmpty()
                )
            )

            "practice_done" -> RelayEvent.Card(AssistMessage.PracticeDone(o.int("asked") ?: 0))

            else -> null
        }
    }

    private fun kind(wire: String?): SuggestionKind = when (wire) {
        "help_now" -> SuggestionKind.HelpNow
        "regenerate" -> SuggestionKind.Regenerate
        "shorter" -> SuggestionKind.Shorter
        "formal" -> SuggestionKind.Formal
        "translate" -> SuggestionKind.Translate
        "upgrade" -> SuggestionKind.Upgrade
        else -> SuggestionKind.Answer
    }

    private fun status(wire: String?): EndStatus = when (wire) {
        "cancelled" -> EndStatus.Cancelled
        "superseded" -> EndStatus.Superseded
        else -> EndStatus.Done
    }

    private fun reason(wire: String?): ModeReason = when (wire) {
        "budget" -> ModeReason.Budget
        "budget_restored" -> ModeReason.BudgetRestored
        "plan" -> ModeReason.Plan
        "assist_time" -> ModeReason.AssistTime
        else -> ModeReason.Requested
    }

    private fun JsonObject.str(key: String): String? = (this[key] as? JsonPrimitive)?.takeUnless { it is JsonNull }?.contentOrNull
    private fun JsonObject.bool(key: String): Boolean? = (this[key] as? JsonPrimitive)?.booleanOrNull
    private fun JsonObject.int(key: String): Int? = (this[key] as? JsonPrimitive)?.intOrNull
    private fun JsonObject.obj(key: String): JsonObject? = this[key] as? JsonObject
    private fun JsonObject.list(key: String): List<String> =
        runCatching { this[key]?.jsonArray?.mapNotNull { (it as? JsonPrimitive)?.contentOrNull } }.getOrNull().orEmpty()

    /** The relay's message limit when its `ready` names none (2 MiB). */
    const val MAX_MESSAGE_BYTES = 2 * 1024 * 1024
}
