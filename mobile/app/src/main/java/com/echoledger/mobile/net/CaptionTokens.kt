package com.echoledger.mobile.net

import com.echoledger.mobile.data.OpenSessionRequest
import com.echoledger.mobile.data.SttTokenResponse
import java.io.IOException

/**
 * Gets a live-caption token for a running recording (A5.1). A v1.1 server hands out caption tokens only for an open
 * session of the recording, so that session is opened first; a v1 server gets the old token request. Captions never
 * stop the recording: every refusal comes back as a message for the caption bubble.
 */
class CaptionTokens(private val api: ApiClient) {
    sealed interface Outcome {
        /** The server session of the recording (when one was opened), to finish on Keep or delete on Discard. */
        val sessionId: String?

        data class Token(val token: SttTokenResponse, override val sessionId: String?) : Outcome

        data class Refused(val message: String, override val sessionId: String?) : Outcome
    }

    /**
     * [existingSession] is a session already opened for this recording; [onClientGone] runs when the server no longer
     * knows the selected client (the caller clears it in the settings) before the request is repeated without it.
     */
    suspend fun fetch(
        serverIsV1_1: Boolean,
        open: OpenSessionRequest,
        channels: List<String>,
        existingSession: String?,
        onClientGone: suspend () -> Unit
    ): Outcome {
        var clientId = open.clientId
        var sessionId = existingSession
        var retried = false
        while (true) {
            try {
                if (sessionId == null && serverIsV1_1) sessionId = api.openSession(open.copy(clientId = clientId)).session.id
                return Outcome.Token(api.sttToken("live_captions", channels, clientId, sessionId, open.language), sessionId)
            } catch (e: ApiException) {
                when {
                    e.code == "budget_exceeded" -> return Outcome.Refused(BUDGET, sessionId)

                    e.code == "session_not_recording" -> return Outcome.Refused(SESSION_ENDED, sessionId)

                    e.code == "client_not_found" && !retried -> {
                        onClientGone()
                        clientId = null
                        retried = true
                    }

                    // The server was upgraded since the last config: open the session now and ask again once.
                    e.code == "session_required" && sessionId == null && !retried -> {
                        sessionId = api.openSession(open.copy(clientId = clientId)).session.id
                        retried = true
                    }

                    else -> return Outcome.Refused(UNAVAILABLE, sessionId)
                }
            } catch (e: NotPairedException) {
                return Outcome.Refused(UNAVAILABLE, sessionId)
            } catch (e: IOException) {
                return Outcome.Refused(UNAVAILABLE, sessionId)
            }
        }
    }

    companion object {
        const val BUDGET = "Monthly limit reached — raise it in Settings"
        const val SESSION_ENDED = "Live captions stopped. Recording continues."
        const val UNAVAILABLE = "Live captions are unavailable right now. Recording continues."
    }
}
