package com.echoledger.mobile.assist

/**
 * [AssistServer] on the phone's own API client (docs/API_ASSIST.md "Sessions"). The relay's pins come from the last
 * config (`relay_pins`, never the server's own pins); a call that fails is null or false, never an exception.
 */
class ApiAssistServer(
    private val api: suspend () -> com.echoledger.mobile.net.ApiClient?,
    private val relayPins: suspend () -> List<String>
) : AssistServer {
    override suspend fun token(sessionId: String): RelayGrant? = runCatching {
        val answer = (api() ?: return null).assistToken(sessionId)
        RelayGrant(answer.relay.url, answer.relay.token, answer.relay.expiresAt, relayPins(), answer.plan?.secondsLeft)
    }.getOrNull()

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
