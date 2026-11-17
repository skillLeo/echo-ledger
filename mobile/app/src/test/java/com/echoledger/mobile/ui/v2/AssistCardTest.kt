package com.echoledger.mobile.ui.v2

import com.echoledger.mobile.ui.v2.assist.AssistCard
import com.echoledger.mobile.ui.v2.assist.AssistMessage
import com.echoledger.mobile.ui.v2.assist.AssistMessage.Error
import com.echoledger.mobile.ui.v2.assist.AssistMessage.Ready
import com.echoledger.mobile.ui.v2.assist.AssistMessage.Skip
import com.echoledger.mobile.ui.v2.assist.AssistMessage.SuggestionEnd
import com.echoledger.mobile.ui.v2.assist.AssistMessage.SuggestionStart
import com.echoledger.mobile.ui.v2.assist.AssistMessage.Token
import com.echoledger.mobile.ui.v2.assist.AssistProblem
import com.echoledger.mobile.ui.v2.assist.CardState
import com.echoledger.mobile.ui.v2.assist.EndStatus
import com.echoledger.mobile.ui.v2.assist.LiveMode
import com.echoledger.mobile.ui.v2.assist.MeetingType
import com.echoledger.mobile.ui.v2.assist.ModeReason
import com.echoledger.mobile.ui.v2.assist.SuggestionKind
import com.echoledger.mobile.ui.v2.assist.SuggestionStatus
import com.echoledger.mobile.ui.v2.assist.SuggestionText
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** The phone's Live Assist card against contract 1.9.0-n, message by message. */
class AssistCardTest {
    private val ready =
        Ready("01JSESSION", LiveMode.Balanced, listOf(LiveMode.Balanced, LiveMode.Fast), resumed = false, meetingType = MeetingType.Boss)

    private fun start(id: String, kind: SuggestionKind = SuggestionKind.Answer, replaces: String? = null, turn: String? = "t-1") =
        SuggestionStart(id, turn, kind, speculative = false, replaces = replaces, mode = LiveMode.Balanced, draft = false)

    private fun play(vararg m: AssistMessage, from: CardState = CardState()) = AssistCard.reduce(m.toList(), from)

    @Test
    fun aSuggestionStreamsTokenByTokenWithItsKind() {
        val s = play(ready, start("s-1", SuggestionKind.HelpNow), Token("s-1", "Yes, **4,200** "), Token("s-1", "holds."))
        assertEquals("Yes, **4,200** holds.", s.shown?.text)
        assertEquals(SuggestionKind.HelpNow, s.shown?.kind)
        assertEquals(SuggestionStatus.Streaming, s.shown?.status)
        assertTrue(s.ready)
    }

    @Test
    fun supersededIsClearedAtOnceBeforeItsReplacementsFirstToken() {
        val s = play(ready, start("s-1"), Token("s-1", "I promise delivery tomorrow"), SuggestionEnd("s-1", EndStatus.Superseded))
        assertEquals("", s.suggestions.single().text)
        assertFalse(s.ready)
        val next = play(start("s-2", replaces = "s-1"), from = s)
        assertEquals("s-2", next.shownId)
        assertEquals("", next.shown?.text)
    }

    @Test
    fun aReplacedSuggestionStaysUntilItsReplacementsFirstToken() {
        val s =
            play(
                ready,
                start("s-1"),
                Token("s-1", "Draft"),
                SuggestionEnd("s-1", EndStatus.Done),
                start("s-2", SuggestionKind.Upgrade, replaces = "s-1")
            )
        // Never blank in between: the draft is still on the card.
        assertEquals("s-1", s.shownId)
        assertEquals("Draft", s.shown?.text)
        assertTrue(s.ready)
        // An upgrade comes as one token with the whole text; at that token the draft leaves for good.
        val first = play(Token("s-2", "Yes, it holds for sixty."), from = s)
        assertEquals("s-2", first.shownId)
        assertEquals(SuggestionStatus.Superseded, first.suggestions.first { it.id == "s-1" }.status)
        assertEquals("", first.suggestions.first { it.id == "s-1" }.text)
        val upgraded = play(SuggestionEnd("s-2", EndStatus.Done), from = first)
        assertEquals("Yes, it holds for sixty.", upgraded.shown?.text)
        // If the upgrade were then cancelled, the replaced draft does not come back.
        val cancelled = play(start("s-3", turn = "t-2"), SuggestionEnd("s-3", EndStatus.Cancelled), from = upgraded)
        assertEquals("s-2", cancelled.shownId)
    }

    @Test
    fun aReplacedSuggestionsOwnSupersededEndTakesItOffFirst() {
        val s =
            play(
                ready,
                start("s-1"),
                Token("s-1", "I promise delivery tomorrow"),
                start("s-2", replaces = "s-1"),
                SuggestionEnd("s-1", EndStatus.Superseded)
            )
        // Its end came before the replacement's first token: cleared at once, and the replacement takes the card.
        assertEquals("s-2", s.shownId)
        assertEquals("", s.suggestions.first { it.id == "s-1" }.text)
        assertEquals(SuggestionStatus.Streaming, s.shown?.status)
        assertEquals("Delivery by Thursday", play(Token("s-2", "Delivery by Thursday"), from = s).shown?.text)
    }

    @Test
    fun aReplacementThatFailsBeforeItsFirstTokenLeavesTheReplacedOneOnTheCard() {
        val s =
            play(
                ready,
                start("s-1"),
                Token("s-1", "Draft"),
                SuggestionEnd("s-1", EndStatus.Done),
                start("s-2", SuggestionKind.Upgrade, replaces = "s-1"),
                AssistMessage.Error("provider_timeout", "", retry = true, suggestionId = "s-2", fatal = false)
            )
        assertEquals("s-1", s.shownId)
        assertEquals("Draft", s.shown?.text)
    }

    @Test
    fun cancelledPutsBackWhatTheCardShowedBefore() {
        val s =
            play(
                ready,
                start("s-1"),
                Token("s-1", "First answer"),
                SuggestionEnd("s-1", EndStatus.Done),
                start("s-2", turn = "t-2"),
                SuggestionEnd("s-2", EndStatus.Cancelled)
            )
        assertEquals("s-1", s.shownId)
        assertEquals("First answer", s.shown?.text)
    }

    @Test
    fun skipEndsTheSuggestionItNames() {
        val s = play(ready, start("s-1"), Skip("s-1", "t-1"))
        assertEquals(SuggestionStatus.Skipped, s.shown?.status)
        assertTrue(s.skipped)
        assertFalse(s.ready)
    }

    @Test
    fun aResumedConnectionClearsTheCard() {
        val before =
            play(
                ready,
                start("s-1"),
                Token("s-1", "Done one"),
                SuggestionEnd("s-1", EndStatus.Done),
                start("s-2", turn = "t-2"),
                Token("s-2", "Half an ans")
            )
        val s = play(ready.copy(resumed = true), from = before)
        assertEquals("", s.suggestions.first { it.id == "s-2" }.text)
        assertEquals(SuggestionStatus.Cancelled, s.suggestions.first { it.id == "s-2" }.status)
        // The card is clear until the next suggestion; the earlier one does not come back.
        assertNull(s.shownId)
        assertFalse(s.ready)
        assertEquals("s-3", play(start("s-3", turn = "t-3"), from = s).shownId)
        // Not resumed: nothing is cleared.
        assertEquals("Half an ans", play(ready, from = before).shown?.text)
    }

    @Test
    fun aFailedSuggestionIsClearedAndEachErrorCodeHasItsSentence() {
        val failed = play(ready, start("s-1"), Token("s-1", "Partly"), Error("provider_timeout", "x", true, "s-1", false))
        assertEquals(SuggestionStatus.Failed, failed.shown?.status)
        assertEquals("", failed.shown?.text)
        assertEquals(AssistProblem.Slow, failed.problem)
        val codes = mapOf(
            "provider_unavailable" to AssistProblem.Slow,
            "model_declined" to AssistProblem.Declined,
            "nothing_to_rewrite" to AssistProblem.NothingToRewrite,
            "documents_too_large" to AssistProblem.DocumentsTooLarge,
            "rate_limited" to AssistProblem.TooFast,
            "bad_message" to AssistProblem.BadMessage,
            "relay_busy" to AssistProblem.Busy,
            "practice_busy" to AssistProblem.Busy,
            "wrong_session" to AssistProblem.Session,
            "token_invalid" to AssistProblem.Session
        )
        for ((code, problem) in codes) assertEquals(code, problem, AssistCard.problemOf(code, retry = false, fatal = false))
        // token_expired mid-session waits for a reconnect; a newer ready clears the note.
        val expired = play(ready, Error("token_expired", "x", retry = true, suggestionId = null, fatal = true))
        assertEquals(AssistProblem.Reconnecting, expired.problem)
        assertNull(play(ready.copy(resumed = true), from = expired).problem)
    }

    @Test
    fun providerNotConfiguredShowsTheRelaysFixedMessage() {
        val s = play(ready, Error("provider_not_configured", "Live Assist isn't available yet on this server.", false, null, false))
        assertEquals(AssistProblem.NotSetUp, s.problem)
        assertEquals("Live Assist isn't available yet on this server.", s.problemText)
    }

    @Test
    fun budgetExhaustedHasTwoSentencesAndAHeldQuestionNone() {
        assertEquals(
            AssistProblem.BudgetOff,
            play(ready, Error("budget_exhausted", "x", retry = false, suggestionId = null, fatal = false)).problem
        )
        assertEquals(
            AssistProblem.BudgetWait,
            play(ready, Error("budget_exhausted", "x", retry = true, suggestionId = null, fatal = false)).problem
        )
        // A question the gate holds sends nothing: the card has no problem and nothing streaming.
        val held = play(ready, AssistMessage.Turn("t-1", other = true, text = "What about the second invoice?", final = true))
        assertNull(held.problem)
        assertNull(held.shown)
    }

    @Test
    fun modeChangesKeepTheirReasonUnlessAskedFor() {
        val s = play(ready, AssistMessage.ModeChanged(LiveMode.Fast, ModeReason.Budget))
        assertEquals(LiveMode.Fast to ModeReason.Budget, s.modeChange)
        assertNull(play(AssistMessage.ModeChanged(LiveMode.Deep, ModeReason.Requested), from = s).modeChange)
        assertTrue(play(AssistMessage.ModeChanged(LiveMode.CaptionsOnly, ModeReason.Budget), from = s).captionsOnly)
    }

    @Test
    fun practiceShowsItsQuestionNumberAndItsEnd() {
        val s = play(ready, AssistMessage.PracticeQuestion("pq-3", "q-3", 3, 8, "Tell me about a deadline you missed."))
        assertEquals(3 to 8, s.practice)
        assertEquals("Tell me about a deadline you missed.", s.turns["q-3"])
        assertEquals(8, play(AssistMessage.PracticeDone(8), from = s).practiceDone)
        // A question that can't be written fails twice with retry, then the next practice_next gets practice_done.
        val failing =
            play(
                Error("practice_busy", "x", true, null, false),
                Error("practice_busy", "x", true, null, false),
                AssistMessage.PracticeDone(3),
                from = s
            )
        assertEquals(3, failing.practiceDone)
    }

    @Test
    fun theOutputContractsParts() {
        val p = SuggestionText.parse(
            "Yes, **4,200** holds.\n- Delivery **Thursday**\n- PO number\n- Third\n- Fourth\nSay next: Shall I send the quote?\nUrdu: وہ ریٹ پوچھ رہے ہیں"
        )
        assertEquals("Yes, **4,200** holds.", p.first)
        assertEquals(3, p.bullets.size)
        assertEquals("Shall I send the quote?", p.sayNext)
        assertEquals("Urdu" to "وہ ریٹ پوچھ رہے ہیں", p.language)
        assertEquals(listOf("Yes, " to false, "4,200" to true, " holds." to false), SuggestionText.boldParts(p.first))
    }
}
