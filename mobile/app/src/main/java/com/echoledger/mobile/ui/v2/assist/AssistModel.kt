package com.echoledger.mobile.ui.v2.assist

/** The meeting a Live Assist session is for (docs/API_ASSIST.md "Preps"). */
enum class MeetingType { Interview, Boss, ExistingClient, NewClient, General }

/** A suggestion mode; [CaptionsOnly] is where the budget leaves it, never one the person picks. */
enum class LiveMode(val wire: String) {
    Fast("fast"),
    Balanced("balanced"),
    Deep("deep"),
    Max("max"),
    CaptionsOnly("captions_only");

    companion object {
        fun from(wire: String?): LiveMode = entries.firstOrNull { it.wire == wire } ?: Balanced
    }
}

/** What asked for a suggestion (`suggestion_start.kind`). */
enum class SuggestionKind { Answer, HelpNow, Regenerate, Shorter, Formal, Translate, Upgrade }

/** What the phone sends from the card (`help_now`, `regenerate`, `shorter`, `translate`, `cancel`). */
enum class SendKind(val wire: String) {
    HelpNow("help_now"),
    Regenerate("regenerate"),
    Shorter("shorter"),
    Translate("translate"),
    Cancel("cancel")
}

/** How a suggestion ended (`suggestion_end.status`). */
enum class EndStatus { Done, Cancelled, Superseded }

/** Why the mode changed (`mode_changed.reason`). */
enum class ModeReason { Budget, BudgetRestored, Plan, AssistTime, Requested }

/**
 * What the card receives (contract 1.9.0-n): the relay's messages it needs, as the relay sends them (`seq` dropped),
 * plus three of the core's own: a caption line ([Turn]), the month's assist time ([Budget]) and the end ([Ended]).
 */
sealed interface AssistMessage {
    data class Ready(
        val sessionId: String,
        val mode: LiveMode,
        val allowedModes: List<LiveMode>,
        val resumed: Boolean,
        val meetingType: MeetingType?
    ) : AssistMessage

    data class SuggestionStart(
        val suggestionId: String,
        val turnId: String?,
        val kind: SuggestionKind,
        val speculative: Boolean,
        val replaces: String?,
        val mode: LiveMode,
        val draft: Boolean,
        /** When the core received it (elapsed realtime), for the "shown" report's render_ms. */
        val receivedAtMs: Long = 0
    ) : AssistMessage

    data class Token(val suggestionId: String, val text: String) : AssistMessage
    data class SuggestionEnd(val suggestionId: String, val status: EndStatus) : AssistMessage

    /** No reply needed: ends the suggestion it names (no tokens came). */
    data class Skip(val suggestionId: String?, val turnId: String?) : AssistMessage
    data class ModeChanged(val mode: LiveMode, val reason: ModeReason) : AssistMessage
    data class Error(val code: String, val message: String, val retry: Boolean, val suggestionId: String?, val fatal: Boolean) :
        AssistMessage
    data class Turn(val turnId: String, val other: Boolean, val text: String, val final: Boolean) : AssistMessage
    data class Budget(val secondsLeft: Long?, val secondsTotal: Long?) : AssistMessage
    data object Ended : AssistMessage

    /** The core's own: automatic suggestions are paused for this session (a mono recording); Help me now still works. */
    data object AutoPaused : AssistMessage

    /** Practice: the relay plays Them; the question counts as their turn. */
    data class PracticeQuestion(val questionId: String, val turnId: String, val index: Int, val total: Int, val text: String) :
        AssistMessage

    /** Practice is over after [asked] questions (the last one, or one that couldn't be written). */
    data class PracticeDone(val asked: Int) : AssistMessage
}

enum class SuggestionStatus { Streaming, Done, Cancelled, Superseded, Failed, Skipped }

data class Suggestion(
    val id: String,
    val turnId: String?,
    val kind: SuggestionKind,
    val replaces: String?,
    val draft: Boolean,
    val text: String,
    val status: SuggestionStatus,
    val receivedAtMs: Long = 0
)

/**
 * Why the card shows an error, as a kind the screen puts in its own plain sentence (never the relay's code). The relay's
 * own text is kept only for [NotSetUp], whose fixed message names no vendor.
 */
enum class AssistProblem {
    Reconnecting,
    Session,
    Busy,
    NotSetUp,
    Slow,
    Declined,
    NothingToRewrite,
    DocumentsTooLarge,
    TooFast,
    BadMessage,
    BudgetOff,
    BudgetWait,
    Other
}

/** The card's state, built only by [AssistCard.reduce]. */
data class CardState(
    val sessionId: String? = null,
    val meetingType: MeetingType? = null,
    val mode: LiveMode = LiveMode.Balanced,
    val allowedModes: List<LiveMode> = emptyList(),
    val suggestions: List<Suggestion> = emptyList(),
    /** The suggestion on the card: the latest one that took it. */
    val shownId: String? = null,
    /** The other side's turns by id, for the question line. */
    val turns: Map<String, String> = emptyMap(),
    /** Why the mode changed, until the next change; null when it was asked for. */
    val modeChange: Pair<LiveMode, ModeReason>? = null,
    val problem: AssistProblem? = null,
    /** The relay's fixed message for [AssistProblem.NotSetUp]. */
    val problemText: String? = null,
    /** Practice: the question on the card and how many there are; [practiceDone] once it is over. */
    val practice: Pair<Int, Int>? = null,
    val practiceDone: Int? = null,
    val skipped: Boolean = false,
    val secondsLeft: Long? = null,
    val secondsTotal: Long? = null,
    val ended: Boolean = false,
    /** Automatic suggestions are paused (a mono recording): only Help me now asks for one. */
    val autoPaused: Boolean = false
) {
    val shown: Suggestion? get() = suggestions.firstOrNull { it.id == shownId }
    val captionsOnly: Boolean get() = mode == LiveMode.CaptionsOnly

    /** Collapsed, the card is a dot that lights when a suggestion has words. */
    val ready: Boolean get() = shown?.let {
        it.text.isNotBlank() && it.status != SuggestionStatus.Failed &&
            it.status != SuggestionStatus.Superseded
    } ==
        true
}

/**
 * The Live Assist card as a pure reducer (contract 1.9.0-n, the same rules as the desktop card):
 * - a suggestion streams token by token onto the card; `replaces` names the one it takes the place of;
 * - one that ends `superseded` (a refusal after words were shown, a draft replaced by its upgrade, or the next question
 *   taking the card) has its text cleared at that moment, before its replacement's first token;
 * - `cancelled` (they kept talking) puts back what the card showed before it started;
 * - a failure ends with an `error` naming the suggestion, never a `suggestion_end`; its text is cleared;
 * - `skip` ends the suggestion it names, which never had words ("No reply needed");
 * - `ready` with `resumed: true` clears a suggestion still streaming (it gets no end);
 * - a start's `replaces` takes the replaced suggestion off the card for good, even one already done;
 * - each error code has its own sentence; `budget_exhausted` says "suggestions are off" (retry false) or "try again
 *   in a moment" (retry true); a question the budget gate holds sends nothing, so the card says nothing;
 * - practice shows the question number and its end;
 * - `mode_changed` keeps why the mode changed; captions never stop.
 */
object AssistCard {
    private const val KEEP = 20

    private fun CardState.patch(id: String, change: (Suggestion) -> Suggestion) =
        copy(suggestions = suggestions.map { if (it.id == id) change(it) else it })

    /** What the card falls back to when [id] goes without words: the one shown before it, if it still has text. */
    private fun CardState.previousShown(id: String): String? {
        val i = suggestions.indexOfFirst { it.id == id }
        for (j in i - 1 downTo 0) {
            val s = suggestions[j]
            if (s.text.isNotEmpty() && (s.status == SuggestionStatus.Done || s.status == SuggestionStatus.Streaming)) return s.id
        }
        return null
    }

    /** The card's sentence for an `error` (1.9.0-n "What the apps must handle"), by its code and `retry`. */
    fun problemOf(code: String, retry: Boolean, fatal: Boolean): AssistProblem = when (code) {
        "token_expired" -> if (retry) AssistProblem.Reconnecting else AssistProblem.Session

        "token_missing", "token_invalid", "wrong_session", "not_practice" -> AssistProblem.Session

        "relay_busy", "practice_busy" -> AssistProblem.Busy

        "provider_not_configured" -> AssistProblem.NotSetUp

        "provider_unavailable", "provider_timeout" -> AssistProblem.Slow

        "model_declined" -> AssistProblem.Declined

        "nothing_to_rewrite" -> AssistProblem.NothingToRewrite

        "documents_too_large" -> AssistProblem.DocumentsTooLarge

        "rate_limited" -> AssistProblem.TooFast

        "bad_message" -> AssistProblem.BadMessage

        // retry false: help now or a rewrite in captions_only; retry true: the budget gate refused it for now.
        "budget_exhausted" -> if (retry) AssistProblem.BudgetWait else AssistProblem.BudgetOff

        else -> if (fatal) AssistProblem.Session else AssistProblem.Other
    }

    fun reduce(state: CardState, m: AssistMessage): CardState = when (m) {
        is AssistMessage.Ready -> {
            // Resumed: a suggestion running when the connection dropped gets no end, so it is cleared now.
            val streaming = if (m.resumed) {
                state.suggestions.filter {
                    it.status == SuggestionStatus.Streaming
                }.map { it.id }.toSet()
            } else {
                emptySet()
            }
            val cleared = state.copy(
                suggestions = state.suggestions.map {
                    if (it.id in
                        streaming
                    ) {
                        it.copy(text = "", status = SuggestionStatus.Cancelled)
                    } else {
                        it
                    }
                }
            )
            cleared.copy(
                sessionId = m.sessionId,
                mode = m.mode,
                allowedModes = m.allowedModes,
                meetingType = m.meetingType ?: state.meetingType,
                ended = false,
                shownId = if (state.shownId in streaming) cleared.previousShown(state.shownId!!) else state.shownId,
                problem = state.problem.takeIf { it != AssistProblem.Reconnecting && it != AssistProblem.Busy }
            )
        }

        is AssistMessage.SuggestionStart -> {
            val s = Suggestion(m.suggestionId, m.turnId, m.kind, m.replaces, m.draft, "", SuggestionStatus.Streaming, m.receivedAtMs)
            // What it replaces (a draft already done, or an answer whose question changed) leaves the card for good.
            val replaced = m.replaces?.let { id -> state.patch(id) { it.copy(text = "", status = SuggestionStatus.Superseded) } } ?: state
            replaced.copy(
                suggestions = replaced.suggestions.takeLast(KEEP - 1) + s,
                shownId = m.suggestionId,
                skipped = false,
                problem = null,
                problemText = null
            )
        }

        is AssistMessage.Token -> {
            val s = state.suggestions.firstOrNull { it.id == m.suggestionId }
            if (s == null ||
                s.status != SuggestionStatus.Streaming
            ) {
                state
            } else {
                state.patch(m.suggestionId) { it.copy(text = it.text + m.text) }
            }
        }

        is AssistMessage.SuggestionEnd -> when (m.status) {
            EndStatus.Superseded -> state.patch(m.suggestionId) { it.copy(text = "", status = SuggestionStatus.Superseded) }

            EndStatus.Cancelled -> {
                val next = state.patch(m.suggestionId) { it.copy(status = SuggestionStatus.Cancelled) }
                if (state.shownId == m.suggestionId) next.copy(shownId = state.previousShown(m.suggestionId)) else next
            }

            EndStatus.Done -> state.patch(m.suggestionId) { it.copy(status = SuggestionStatus.Done) }
        }

        is AssistMessage.Skip -> {
            val ended = m.suggestionId?.let { id -> state.patch(id) { it.copy(text = "", status = SuggestionStatus.Skipped) } } ?: state
            ended.copy(skipped = true)
        }

        is AssistMessage.ModeChanged -> state.copy(
            mode = m.mode,
            modeChange = (m.mode to m.reason).takeIf {
                m.reason !=
                    ModeReason.Requested
            }
        )

        is AssistMessage.Error -> {
            val next = m.suggestionId?.let { id -> state.patch(id) { it.copy(text = "", status = SuggestionStatus.Failed) } } ?: state
            val problem = problemOf(m.code, m.retry, m.fatal)
            next.copy(problem = problem, problemText = m.message.takeIf { problem == AssistProblem.NotSetUp })
        }

        is AssistMessage.Turn -> if (m.other) state.copy(turns = state.turns + (m.turnId to m.text)) else state

        is AssistMessage.Budget -> state.copy(secondsLeft = m.secondsLeft, secondsTotal = m.secondsTotal)

        AssistMessage.Ended -> state.copy(ended = true)

        AssistMessage.AutoPaused -> state.copy(autoPaused = true)

        is AssistMessage.PracticeQuestion -> state.copy(turns = state.turns + (m.turnId to m.text), practice = m.index to m.total)

        is AssistMessage.PracticeDone -> state.copy(practiceDone = m.asked)
    }

    fun reduce(messages: List<AssistMessage>, from: CardState = CardState()): CardState = messages.fold(from, ::reduce)
}

/** The output contract's parts: the first line, up to three bullets, "Say next", and the language line. */
data class ParsedSuggestion(val first: String, val bullets: List<String>, val sayNext: String?, val language: Pair<String, String>?)

object SuggestionText {
    private val LANGUAGE = Regex("""^(Urdu|Roman Urdu|Arabic):\s*(.*)$""")
    private val BULLET = Regex("""^[-•*]\s+""")
    private val SAY_NEXT = Regex("""^say next:\s*""", RegexOption.IGNORE_CASE)

    fun parse(text: String): ParsedSuggestion {
        var first = ""
        val bullets = mutableListOf<String>()
        var sayNext: String? = null
        var language: Pair<String, String>? = null
        for (line in text.lines().map { it.trim() }.filter { it.isNotEmpty() }) {
            val lang = LANGUAGE.matchEntire(line)
            when {
                lang != null -> language = lang.groupValues[1] to lang.groupValues[2]
                SAY_NEXT.containsMatchIn(line) -> sayNext = line.replace(SAY_NEXT, "")
                BULLET.containsMatchIn(line) -> if (bullets.size < 3) bullets += line.replace(BULLET, "")
                first.isEmpty() -> first = line
            }
        }
        return ParsedSuggestion(first, bullets, sayNext, language)
    }

    /** "**key** rest" as parts, the bold ones marked. */
    fun boldParts(text: String): List<Pair<String, Boolean>> {
        val out = mutableListOf<Pair<String, Boolean>>()
        var last = 0
        for (m in Regex("""\*\*([^*]+)\*\*""").findAll(text)) {
            if (m.range.first > last) out += text.substring(last, m.range.first) to false
            out += m.groupValues[1] to true
            last = m.range.last + 1
        }
        if (last < text.length) out += text.substring(last) to false
        return out
    }
}
