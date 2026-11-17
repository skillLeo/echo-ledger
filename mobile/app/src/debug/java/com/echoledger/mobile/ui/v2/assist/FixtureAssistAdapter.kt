package com.echoledger.mobile.ui.v2.assist

import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.flow

/**
 * A Live Assist session played from a fixture, for development and tests only (debug build): a client call with
 * Bilal Traders. It streams a suggestion token by token, then answers the card's requests from [replies].
 */
class FixtureAssistAdapter(
    override val meetingType: MeetingType? = MeetingType.ExistingClient,
    private val script: List<AssistMessage> = AssistFixtures.clientCall,
    private val stepMs: Long = 120
) : AssistAdapter {
    val sent = mutableListOf<SendKind>()
    val shown = mutableListOf<Pair<String, Long>>()

    override val messages: Flow<AssistMessage> = flow {
        for (m in script) {
            delay(stepMs)
            emit(m)
        }
    }

    override suspend fun snapshot(): List<AssistMessage> = emptyList()

    override fun send(kind: SendKind) {
        sent += kind
    }

    override fun feedback(suggestionId: String, used: Boolean) = Unit

    override fun reportShown(suggestionId: String, renderMs: Long) {
        shown += suggestionId to renderMs
    }

    override fun end() = Unit
}

/** Real-looking relay streams (contract 1.9.0-n) for the card. */
object AssistFixtures {
    private fun ready(meeting: MeetingType? = MeetingType.ExistingClient, resumed: Boolean = false) =
        AssistMessage.Ready("01JSESSION0000000000000001", LiveMode.Balanced, listOf(LiveMode.Balanced, LiveMode.Fast), resumed, meeting)

    private fun start(id: String, turn: String?, kind: SuggestionKind = SuggestionKind.Answer, replaces: String? = null) =
        AssistMessage.SuggestionStart(id, turn, kind, speculative = false, replaces = replaces, mode = LiveMode.Balanced, draft = false)

    val question = "Can you hold the rate at 4,200 per carton if we order sixty instead of forty?"

    val answer = listOf(
        "Yes, **4,200** holds for **sixty cartons** if payment stays at **45 days**.\n",
        "- Delivery to the **Hyderabad** godown by Thursday\n",
        "- Ask for the **PO number** on the order\n",
        "Urdu: وہ پوچھ رہے ہیں کہ ساٹھ کارٹن پر بھی یہی ریٹ رہے گا؟"
    )

    val clientCall: List<AssistMessage> = buildList {
        add(ready())
        add(AssistMessage.Turn("t-1", other = true, text = question, final = true))
        add(start("s-1", "t-1"))
        answer.forEach { add(AssistMessage.Token("s-1", it)) }
        add(AssistMessage.SuggestionEnd("s-1", EndStatus.Done))
    }
}
