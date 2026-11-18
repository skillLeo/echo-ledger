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

        /** The server has no Live Assist, or it uses the relay and there are no relay pins yet (`relay_pins`). */
        data object NotSetUp : Start

        /** A session runs already (`409 session_active`), here or on another device. */
        data object Busy : Start

        /** Refused for this person (plan, budget, consent): the server's words. */
        data class Refused(val words: String) : Start

        /** Offline, the server, the certificate: words for people. */
        data class Failed(val words: String) : Start

        /** `426 app_update_required` (contract 1.9.0-q): this phone's app is too old for the server's transport. */
        data object UpdateApp : Start

        /** `503 relay_unavailable` (contract 1.9.0-q): the relay is down right now. */
        data object CantConnect : Start
    }

    /** Both sides of the recording under way are recorded apart (a mono recording pauses automatic suggestions). */
    @Volatile private var stereo = true

    /** The recording's server session is known (captions started), or the recording ended (null; the session ends too). */
    fun recordingSession(id: String?, bothSides: Boolean = true) {
        stereo = bothSides
        recording.value = id
        if (id == null) current.value?.end()
    }

    /** A caption line of the recording under way; nothing happens without a session. */
    fun caption(other: Boolean, text: String, final: Boolean, startMs: Long = 0, endMs: Long = 0) {
        current.value?.caption(other, text, final, startMs, endMs)
    }

    /**
     * Starts a session on the recording under way, on the transport the server picks (contract 1.9.0-q). The phone
     * always offers both, relay first: the relay is the normal path, and HTTP mode is only the server's emergency
     * fallback, never the phone's own choice. [relayPins]: the last config's `relay_pins`; a session the server puts on
     * the relay while there are none can't be trusted, so it is ended and [Start.NotSetUp]. [endOther]: a `409` first
     * ends the running session (the person said so), then starts again.
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
        build: (AssistServer, LiveGrant, String, Long?) -> PhoneAssist = { server, grant, id, seconds ->
            PhoneAssist(id, grant, meetingType, server, scope, translateTo, seconds, autoSuggestions = stereo)
        }
    ): Start {
        current.value?.let { return Start.Started(it) }
        val recordingId = recording.value ?: return Start.NoRecording
        val transports = listOf("relay", "http")
        val started = try {
            api.startAssist(prepId, mode, recordingId, practice = false, transports = transports)
        } catch (e: ApiException) {
            when {
                e.status == 409 && endOther && e.sessionId != null -> {
                    // The person chose to end the running session (its id comes with the 409), then start again.
                    runCatching { api.endAssist(e.sessionId) }
                    return start(api, relayPins, scope, prepId, mode, meetingType, translateTo, endOther = false, build = build)
                }

                e.status == 409 -> return Start.Busy

                e.status == 426 || e.code == "app_update_required" -> return Start.UpdateApp

                e.status == 503 && e.code == "relay_unavailable" -> return Start.CantConnect

                // An older server without the route, or Live Assist not configured there.
                e.status == 404 || e.code == "provider_not_configured" -> return Start.NotSetUp

                e.status in 400..499 -> return Start.Refused(Problems.text(e, NOT_STARTED))

                else -> return Start.Failed(Problems.text(e, NOT_STARTED))
            }
        } catch (e: Exception) {
            return Start.Failed(Problems.text(e, NOT_STARTED))
        }
        val server = ApiAssistServer({ api }, { relayPins })
        val relay = started.relay
        val http = started.http
        val grant: LiveGrant = when {
            started.transport == "http" && http != null -> HttpGrant.of(http, started.session.id, started.plan?.secondsLeft)

            started.transport != "http" && relay != null && relayPins.isNotEmpty() ->
                RelayGrant(relay.url, relay.token, relay.expiresAt, relayPins)

            else -> {
                // Nothing this phone can connect to: the session it started is ended at once.
                runCatching { api.endAssist(started.session.id) }
                return Start.NotSetUp
            }
        }
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

    /** `426 app_update_required` (contract 1.9.0-q). */
    const val UPDATE_APP = "Live Assist needs the latest version of the app."

    /** `503 relay_unavailable` (contract 1.9.0-q). */
    const val RELAY_DOWN = "Live Assist can't connect right now. Try again in a few minutes."
}
