package com.echoledger.mobile.assist

/**
 * [AssistServer] on the phone's own API client (docs/API_ASSIST.md "Sessions"). The relay's pins come from the last
 * config (`relay_pins`, never the server's own pins); HTTP mode's POSTs go over the API client itself (the server's own
 * pins, and the connection it keeps). A call that fails is null or false, never an exception.
 */
class ApiAssistServer(
    private val api: suspend () -> com.echoledger.mobile.net.ApiClient?,
    private val relayPins: suspend () -> List<String>
) : AssistServer {
    override suspend fun token(sessionId: String): LiveGrant? = runCatching {
        val answer = (api() ?: return null).assistToken(sessionId)
        val seconds = answer.plan?.secondsLeft
        when {
            answer.transport == "http" -> answer.http?.let { HttpGrant.of(it, sessionId, seconds) }
            else -> answer.relay?.let { RelayGrant(it.url, it.token, it.expiresAt, relayPins(), seconds) }
        }
    }.getOrNull()

    override suspend fun liveCall(url: String, json: String): okhttp3.Call? = runCatching { api()?.assistLive(url, json) }.getOrNull()

    override suspend fun turns(sessionId: String, rows: List<TurnRow>): Boolean = runCatching {
        (api() ?: return false).assistTurns(sessionId, rows)
        true
    }.getOrElse { e ->
        // A session that ended takes no more turns: nothing to send again.
        (e as? com.echoledger.mobile.net.ApiException)?.code == "session_ended"
    }

    override suspend fun action(sessionId: String, suggestionId: String, action: String, renderMs: Long?) {
        runCatching { api()?.assistAction(sessionId, suggestionId, action, renderMs) }
    }

    override suspend fun end(sessionId: String) {
        runCatching { api()?.endAssist(sessionId) }
    }
}
