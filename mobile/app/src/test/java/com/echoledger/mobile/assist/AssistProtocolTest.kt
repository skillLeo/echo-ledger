package com.echoledger.mobile.assist

import com.echoledger.mobile.ui.v2.assist.AssistMessage
import com.echoledger.mobile.ui.v2.assist.EndStatus
import com.echoledger.mobile.ui.v2.assist.LiveMode
import com.echoledger.mobile.ui.v2.assist.SendKind
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** The relay's messages both ways (docs/API_ASSIST.md 1.9.0-o), the outbox while down, and the connection's rules. */
class AssistProtocolTest {
    private fun json(text: String): JsonObject = Json.parseToJsonElement(text).jsonObject

    private fun JsonObject.s(key: String) = this[key]?.jsonPrimitive?.content

    @Test
    fun `each message carries its type and the connection's seq`() {
        val partial = json(ClientMessage.TurnPartial(Turn("o-3", true, "What is the", silenceMs = 400)).frame(7))
        assertEquals("turn_partial", partial.s("type"))
        assertEquals(7, partial["seq"]!!.jsonPrimitive.int)
        assertEquals("o-3", partial.s("turn_id"))
        assertEquals("other", partial.s("channel"))
        assertEquals("400", partial.s("silence_ms"))

        val final = json(ClientMessage.TurnFinal(Turn("m-1", false, "Thanks", 1000, 2500, language = "en")).frame(1))
        assertEquals("me", final.s("channel"))
        assertEquals("1000", final.s("start_ms"))
        assertEquals("2500", final.s("end_ms"))
        assertEquals("en", final.s("language"))

        val translate = json(ClientMessage.Command(SendKind.Translate, language = "urdu").frame(2))
        assertEquals(SendKind.Translate.wire, translate.s("type"))
        assertEquals("urdu", translate.s("language"))

        val context = json(ClientMessage.ContextUpdate(buildJsonObject { put("brief", "Sales call") }).frame(3))
        assertEquals("context_update", context.s("type"))
        assertEquals("Sales call", context.s("brief"))

        assertEquals("token_refresh", json(ClientMessage.TokenRefresh("t2").frame(4)).s("type"))
    }

    @Test
    fun `the relay's pins come with the config, apart from the server's own`() {
        val sample = java.io.File("../../docs/api-samples/config.json").readText()
        val config = com.echoledger.mobile.data.ApiJson.decodeFromString(com.echoledger.mobile.data.ServerConfig.serializer(), sample)
        assertEquals(2, com.echoledger.mobile.net.Pins.normalize(config.relayPins).size)
        // An older server says nothing of a relay: no pins, so Live Assist never connects.
        val withoutPins = buildJsonObject { json(sample).filterKeys { it != "relay_pins" }.forEach { (k, v) -> put(k, v) } }.toString()
        val older = com.echoledger.mobile.data.ApiJson.decodeFromString(com.echoledger.mobile.data.ServerConfig.serializer(), withoutPins)
        assertTrue(older.relayPins.isEmpty())
    }

    @Test
    fun `words and tokens never reach a log line`() {
        val turn = Turn("o-1", true, "my card number is 4111")
        assertFalse(turn.toString().contains("4111"))
        assertFalse(ClientMessage.TurnFinal(turn).toString().contains("4111"))
        assertFalse(ClientMessage.TokenRefresh("secret-token").toString().contains("secret-token"))
        assertFalse(RelayGrant("wss://r/v1/assist", "secret-token", null, emptyList()).toString().contains("secret-token"))
        assertFalse(TurnRow("o-1", true, 0, 1, "4111", null, false).toString().contains("4111"))
    }

    @Test
    fun `ready gives the session, its modes and the relay's limits`() {
        val ready = RelayDecoder.decode(
            """{"type":"ready","seq":1,"session_id":"s1","mode":"balanced","allowed_modes":["fast","balanced"],"resumed":true,
               "expires_at":"2026-11-17T10:15:00Z","limits":{"messages_per_second":25,"max_message_bytes":65536}}""",
            0,
            null
        ) as RelayEvent.Ready
        assertEquals("s1", ready.sessionId)
        assertEquals(LiveMode.from("balanced"), ready.mode)
        assertEquals(2, ready.allowedModes.size)
        assertTrue(ready.resumed)
        assertEquals(25, ready.messagesPerSecond)
        assertEquals(65536, ready.maxMessageBytes)

        val bare = RelayDecoder.decode("""{"type":"ready","session_id":"s1"}""", 0, null) as RelayEvent.Ready
        assertEquals(AssistOutbox.DEFAULT_RATE, bare.messagesPerSecond)
        assertFalse(bare.resumed)
    }

    @Test
    fun `an error keeps all six fields`() {
        val error = RelayDecoder.decode(
            """{"type":"error","seq":4,"code":"budget_exhausted","message":"Out of time","retry":false,"fatal":true,
               "suggestion_id":"g1","in_reply_to":9}""",
            0,
            null
        ) as RelayEvent.Error
        assertEquals(
            RelayEvent.Error("budget_exhausted", "Out of time", retry = false, fatal = true, suggestionId = "g1", inReplyTo = 9),
            error
        )
    }

    @Test
    fun `the card's messages are read with the moment they arrived`() {
        val start = RelayDecoder.decode(
            """{"type":"suggestion_start","suggestion_id":"g1","turn_id":"o-2","kind":"answer","speculative":true,"mode":"fast"}""",
            12_345,
            null
        ) as RelayEvent.Card
        val message = start.message as AssistMessage.SuggestionStart
        assertEquals("g1", message.suggestionId)
        assertEquals("o-2", message.turnId)
        assertTrue(message.speculative)
        assertEquals(12_345, message.receivedAtMs)

        val end = RelayDecoder.decode(
            """{"type":"suggestion_end","suggestion_id":"g1","status":"superseded"}""",
            0,
            null
        ) as RelayEvent.Card
        assertEquals(AssistMessage.SuggestionEnd("g1", EndStatus.Superseded), end.message)

        val token = RelayDecoder.decode("""{"type":"token","suggestion_id":"g1","text":"Yes"}""", 0, null) as RelayEvent.Card
        assertEquals(AssistMessage.Token("g1", "Yes"), token.message)
    }

    @Test
    fun `an unknown message or bad JSON is skipped`() {
        assertNull(RelayDecoder.decode("""{"type":"something_new","seq":3}""", 0, null))
        assertNull(RelayDecoder.decode("not json", 0, null))
        assertNull(RelayDecoder.decode("[1,2]", 0, null))
    }

    @Test
    fun `while down only the latest partial and context wait, every final and command is kept`() {
        val box = AssistOutbox()
        box.push(ClientMessage.TurnPartial(Turn("o-1", true, "What")), down = true)
        box.push(ClientMessage.TurnPartial(Turn("o-1", true, "What is")), down = true)
        box.push(ClientMessage.TurnFinal(Turn("o-1", true, "What is it?")), down = true)
        box.push(ClientMessage.Command(SendKind.HelpNow), down = true)
        box.push(ClientMessage.Command(SendKind.HelpNow), down = true)
        box.push(ClientMessage.TurnPartial(Turn("o-2", true, "And")), down = true)
        box.push(ClientMessage.TurnPartial(Turn("o-2", true, "And the price")), down = true)
        val left = generateSequence { box.take() }.toList()
        assertEquals(listOf("turn_final", SendKind.HelpNow.wire, SendKind.HelpNow.wire, "turn_partial"), left.map { it.type })
        assertEquals("And the price", (left.last() as ClientMessage.TurnPartial).turn.text)
    }

    @Test
    fun `while up every partial goes`() {
        val box = AssistOutbox()
        box.push(ClientMessage.TurnPartial(Turn("o-1", true, "What")), down = false)
        box.push(ClientMessage.TurnPartial(Turn("o-1", true, "What is")), down = false)
        assertEquals(2, box.size)
    }

    @Test
    fun `the outbox is bounded and drops the oldest`() {
        val box = AssistOutbox(maxMessages = 3)
        repeat(5) { box.push(ClientMessage.TurnFinal(Turn("o-$it", true, "line $it")), down = true) }
        assertEquals(3, box.size)
        assertEquals(2, box.dropped)
        assertEquals("o-2", (box.take() as ClientMessage.TurnFinal).turn.turnId)

        val small = AssistOutbox(maxBytes = 300)
        repeat(5) { small.push(ClientMessage.TurnFinal(Turn("o-$it", true, "x".repeat(100))), down = true) }
        assertTrue(small.size < 5)
    }

    @Test
    fun `a failed send goes back to the front, and a lost session's context goes first, once`() {
        val box = AssistOutbox()
        box.push(ClientMessage.TurnFinal(Turn("o-1", true, "a")), down = true)
        box.push(ClientMessage.ContextUpdate(buildJsonObject { put("brief", "old") }), down = true)
        val first = box.take()!!
        box.putBack(first)
        assertEquals(first, box.take())
        box.putBack(first)
        val whole = ClientMessage.ContextUpdate(buildJsonObject { put("brief", "b") })
        box.contextFirst(whole)
        val order = generateSequence { box.take() }.toList()
        assertEquals(listOf(whole, first), order)
    }

    @Test
    fun `the context is kept whole, each field replaced`() {
        val context = AssistContext()
        assertTrue(context.isEmpty)
        context.update(buildJsonObject { put("brief", "Sales call") })
        context.update(buildJsonObject { put("documents", "Price list") })
        context.update(buildJsonObject { put("brief", "") })
        val whole = context.whole().fields
        assertEquals(JsonPrimitive(""), whole["brief"])
        assertEquals(JsonPrimitive("Price list"), whole["documents"])
    }

    @Test
    fun `close codes decide what the connection does next`() {
        assertEquals(RelayPolicy.After.End(RelayEnd.Closed), RelayPolicy.afterClose(1000))
        assertEquals(RelayPolicy.After.End(RelayEnd.Replaced), RelayPolicy.afterClose(4409))
        assertEquals(RelayPolicy.After.End(RelayEnd.WrongSession), RelayPolicy.afterClose(4403))
        assertEquals(RelayPolicy.After.End(RelayEnd.NotTrusted), RelayPolicy.afterClose(RelayPolicy.PINS_REFUSED))
        assertEquals(RelayPolicy.After.End(RelayEnd.Refused), RelayPolicy.afterClose(RelayPolicy.BAD_REQUEST))
        assertEquals(RelayPolicy.After.RefreshThenReconnect, RelayPolicy.afterClose(4401))
        for (code in listOf(null, 1006, 1012, 4408, 1009)) assertEquals(RelayPolicy.After.Reconnect, RelayPolicy.afterClose(code))
    }

    @Test
    fun `a token is refreshed two minutes before it expires`() {
        val policy = RelayPolicy()
        val now = java.time.Instant.parse("2026-11-17T10:00:00Z").toEpochMilli()
        assertEquals(13 * 60_000L, policy.refreshIn("2026-11-17T10:15:00Z", now))
        assertEquals(0L, policy.refreshIn("2026-11-17T10:01:00Z", now))
        assertNull(policy.refreshIn(null, now))
        assertNull(policy.refreshIn("soon", now))
        assertEquals(listOf(250L, 500L, 1_000L, 2_000L, 4_000L, 8_000L, 8_000L), (1..7).map(policy::pause))
    }

    @Test
    fun `turns share an id per channel, the phone's own partials stay home`() {
        var now = 0L
        val feeder = TurnFeeder { now }
        val (relay1, line1) = feeder.partial(other = true, "What")
        assertEquals("o-1", (relay1 as ClientMessage.TurnPartial).turn.turnId)
        assertEquals(0L, relay1.turn.silenceMs)
        now = 700
        val (relay2, _) = feeder.partial(other = true, "What")
        assertEquals(700L, (relay2 as ClientMessage.TurnPartial).turn.silenceMs)
        assertEquals("o-1", line1.turnId)
        val (final, _, row) = feeder.final(other = true, "What is the price?", 1000, 2500)
        assertEquals("o-1", (final as ClientMessage.TurnFinal).turn.turnId)
        assertEquals("o-1", row.clientRef)
        assertTrue(row.isQuestion)
        assertEquals("o-2", (feeder.partial(other = true, "Next").first as ClientMessage.TurnPartial).turn.turnId)

        val (mine, mineLine) = feeder.partial(other = false, "Sure")
        assertNull(mine)
        assertEquals("m-1", mineLine.turnId)
        val (_, _, mineRow) = feeder.final(other = false, "Sure, it is ten?", 3000, 4000)
        assertEquals("m-1", mineRow.clientRef)
        assertFalse(mineRow.isQuestion)
    }

    @Test
    fun `final turns are batched every ten, and a failed batch is sent again first`() {
        val batch = TurnBatch()
        val rows = (1..10).map { TurnRow("o-$it", true, 0, 1, "t", null, false) }
        rows.dropLast(1).forEach { assertFalse(batch.add(it)) }
        assertTrue(batch.add(rows.last()))
        val taken = batch.take()
        assertEquals(10, taken.size)
        assertTrue(batch.isEmpty())
        batch.add(TurnRow("o-11", true, 0, 1, "t", null, false))
        batch.failed(taken)
        assertEquals(listOf("o-1", "o-11"), batch.take().let { listOf(it.first().clientRef, it.last().clientRef) })
    }
}
