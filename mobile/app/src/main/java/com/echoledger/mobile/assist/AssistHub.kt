package com.echoledger.mobile.assist

import com.echoledger.mobile.core.Problems
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.ui.v2.assist.MeetingType
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * This phone's Live Assist session, one at a time (docs/API_ASSIST.md: one active session per person; the phone never
 * follows a session another device started). The recording's captions reach it through [caption]; the card and the
 * start screen read [active].
 */
object AssistHub {
    private val current = MutableStateFlow<PhoneAssist?>(null)
    val active: StateFlow<PhoneAssist?> = current.asStateFlow()

    /** The server's session of the recording under way (set by the recording, null when none runs). */
    private val recording = MutableStateFlow<String?>(null)
    val recordingSessionId: StateFlow<String?> = recording.asStateFlow()

    /** Why a start did not happen, or the session that started. */
    sealed interface Start {
        data class Started(val assist: PhoneAssist) : Start

        /** Live Assist follows a recording's captions: nothing is being recorded with this server. */
        data object NoRecording : Start

        /** The server has no Live Assist, or no relay pins yet (`relay_pins`): it can't be trusted. */
        data object NotSetUp : Start

        /** A session runs already (`409 session_active`), here or on another device. */
        data object Busy : Start

        /** Refused for this person (plan, budget, consent): the server's words. */
        data class Refused(val words: String) : Start

        /** Offline, the server, the certificate: words for people. */
        data class Failed(val words: String) : Start
    }

    /** The recording's server session is known (captions started), or the recording ended (null; the session ends too). */
    fun recordingSession(id: String?) {
        recording.value = id
        if (id == null) current.value?.end()
    }

    /** A caption line of the recording under way; nothing happens without a session. */
    fun caption(other: Boolean, text: String, final: Boolean, startMs: Long = 0, endMs: Long = 0) {
        current.value?.caption(other, text, final, startMs, endMs)
    }

    /**
     * Starts a session on the recording under way. [relayPins]: the last config's `relay_pins` (none: [Start.NotSetUp],
     * nothing is started). [endOther]: a `409` first ends the running session (the person said so), then starts again.
     */
    suspend fun start(
        api: ApiClient,
        relayPins: List<String>,
        scope: CoroutineScope,
        prepId: String? = null,
        mode: String? = null,
        meetingType: MeetingType? = null,
        translateTo: String? = null,
        endOther: Boolean = false,
        build: (AssistServer, RelayGrant, String, Long?) -> PhoneAssist = { server, grant, id, seconds ->
            PhoneAssist(id, grant, meetingType, server, scope, translateTo, seconds)
        }
    ): Start {
        current.value?.let { return Start.Started(it) }
        val recordingId = recording.value ?: return Start.NoRecording
        if (relayPins.isEmpty()) return Start.NotSetUp
        val started = try {
            api.startAssist(prepId, mode, recordingId, practice = false)
        } catch (e: ApiException) {
            when {
                e.status == 409 && endOther && e.sessionId != null -> {
                    // The person chose to end the running session (its id comes with the 409), then start again.
                    runCatching { api.endAssist(e.sessionId) }
                    return start(api, relayPins, scope, prepId, mode, meetingType, translateTo, endOther = false, build = build)
                }

                e.status == 409 -> return Start.Busy

                // An older server without the route, or Live Assist not configured there.
                e.status == 404 || e.code == "provider_not_configured" -> return Start.NotSetUp

                e.status in 400..499 -> return Start.Refused(Problems.text(e, NOT_STARTED))

                else -> return Start.Failed(Problems.text(e, NOT_STARTED))
            }
        } catch (e: Exception) {
            return Start.Failed(Problems.text(e, NOT_STARTED))
        }
        val server = ApiAssistServer({ api }, { relayPins })
        val grant = RelayGrant(started.relay.url, started.relay.token, started.relay.expiresAt, relayPins)
        val assist = build(server, grant, started.session.id, started.plan?.secondsLeft)
        assist.whenFinished = { current.compareAndSet(assist, null) }
        current.value = assist
        assist.start()
        return Start.Started(assist)
    }

    /** Ends this phone's session, if one runs (the recording goes on). */
    fun end() {
        current.value?.end()
    }

    /** Tests: forgets the session and the recording. */
    internal fun reset() {
        current.value = null
        recording.value = null
    }

    const val NOT_STARTED = "Live Assist could not start. The recording goes on."
}
