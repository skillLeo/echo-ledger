package com.echoledger.mobile.ui.v2.assist

import kotlinx.coroutines.flow.Flow

/**
 * The typed seam between the phone's Live Assist card and the core (v1.9 L5). The core owns the relay connection
 * (docs/API_ASSIST.md 1.9.0-n: the token, its refresh before `expires_at`, resume after `1012`, `relay_pins` pinning,
 * the Android 14 foreground-service type, battery-safe reconnect) and the REST calls; the card only reads [messages]
 * and calls the commands below. Until the core has them, a fixture adapter in the debug build plays a session.
 *
 * What the core must provide (to be routed to the phone core):
 * - Only this phone's own sessions: one relay connection per session and no viewer role (1.9.0-n), so the phone never
 *   mirrors a session another device started; [messages] is empty unless this phone started the session (a call on
 *   this phone, or Start from Live).
 * - [messages]: every relay message the card needs, parsed into [AssistMessage] (`seq` dropped), as the relay sends
 *   them: `ready` (with `resumed`), `suggestion_start` (with `turn_id`, `kind`, `speculative`, `replaces`, `mode`,
 *   `draft`, and the elapsed-realtime moment it arrived), `token`, `suggestion_end` (`status` done / cancelled /
 *   superseded), `skip` (with its `suggestion_id`), `mode_changed` (`reason`), `error` (all six fields),
 *   `practice_question` and `practice_done` (`asked`); plus the core's own `Turn` (each caption line, channel me /
 *   other, partial or final), `Budget` (this month's assist seconds left and total, after start and on every
 *   `token_refreshed`) and `Ended`. A newly opened card replays [snapshot] first, then follows [messages]. On close
 *   `4401` after `token_expired` the core reconnects with a new token within 120 s; on `1006` (a slow reader dropped)
 *   it reconnects and resumes; on `4409` it does not reconnect.
 * - [send]: `help_now`, `regenerate`, `shorter`, `translate`, `cancel` on the relay, each with the next client `seq`.
 * - [feedback]: `POST /api/assist/sessions/{id}/suggestions/{suggestion_id}/action` (used / dismissed).
 * - [reportShown]: the same route with `{"action": "shown", "render_ms": n}`, once per suggestion, when its first text
 *   is drawn (n: from the core receiving `suggestion_start` to that frame).
 * - [end]: `POST /api/assist/sessions/{id}/end`, closing the relay connection; the recording goes on.
 * - [meetingType]: the prep's meeting type (null without a prep), for the interview reminder and the consent line.
 * - Text only, no Accessibility API, and the card follows the private-window rules of the S5 overlays (hidden in a
 *   screen share).
 */
interface AssistAdapter {
    val messages: Flow<AssistMessage>
    val meetingType: MeetingType?

    /** The messages so far, for a card opened in the middle of a session. */
    suspend fun snapshot(): List<AssistMessage>

    fun send(kind: SendKind)

    fun feedback(suggestionId: String, used: Boolean)

    fun reportShown(suggestionId: String, renderMs: Long)

    fun end()
}
