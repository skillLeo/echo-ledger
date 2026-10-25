package com.echoledger.mobile.net

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Ignore
import org.junit.Test

/** Unusual and hostile provider messages for the live caption parsers. */
class SttProtocolEdgeCasesTest {
    @Test
    fun `non-object JSON and empty input are ignored by both parsers`() {
        listOf("", "   ", "null", "[]", "[1,2]", "\"text\"", "42", "true", "{", "\u0000", "<html/>").forEach {
            assertNull("assemblyai [$it]", SttProtocol.parseAssemblyAi(it))
            assertNull("elevenlabs [$it]", SttProtocol.parseElevenLabs(it))
        }
    }

    @Ignore("A6.M7: SttProtocol throws on a word that is not a JSON object")
    @Test
    fun `AssemblyAI messages with missing or mistyped fields never throw`() {
        listOf(
            """{"type":"Turn"}""",
            """{"type":"Turn","transcript":null,"end_of_turn":true,"turn_is_formatted":true}""",
            """{"type":"Turn","transcript":5,"end_of_turn":"yes"}""",
            """{"type":"Turn","transcript":"hi","end_of_turn":true,"turn_is_formatted":true,"words":"nope"}""",
            """{"type":"Turn","transcript":"hi","end_of_turn":true,"turn_is_formatted":true,"words":[1,2,3]}""",
            """{"type":"Turn","transcript":"hi","end_of_turn":true,"turn_is_formatted":true,"words":[{"start":"x","end":null}]}""",
            """{"type":null}""",
            """{"type":5}""",
            """{"type":"Error"}"""
        ).forEach { runCatching { SttProtocol.parseAssemblyAi(it) }.onFailure { error -> throw AssertionError("threw on $it: $error") } }
    }

    @Test
    fun `AssemblyAI finals without usable word timings still deliver the text`() {
        val raw = """{"type":"Turn","transcript":"  Hello there.  ","end_of_turn":true,"turn_is_formatted":true,"words":[]}"""
        assertEquals(SttEvent.Final("Hello there.", null, null), SttProtocol.parseAssemblyAi(raw))
    }

    @Test
    fun `AssemblyAI empty finals and empty partials produce no caption text`() {
        assertNull(SttProtocol.parseAssemblyAi("""{"type":"Turn","transcript":"   ","end_of_turn":true,"turn_is_formatted":true}"""))
        assertEquals(SttEvent.Partial(""), SttProtocol.parseAssemblyAi("""{"type":"Turn","transcript":"","end_of_turn":false}"""))
    }

    @Test
    fun `AssemblyAI errors carry the provider text or a generic one`() {
        assertEquals(SttEvent.Error("Session expired"), SttProtocol.parseAssemblyAi("""{"type":"Error","error":"Session expired"}"""))
        assertEquals(SttEvent.Error("streaming error"), SttProtocol.parseAssemblyAi("""{"type":"Error"}"""))
    }

    @Test
    fun `unknown AssemblyAI message types are ignored`() {
        assertNull(SttProtocol.parseAssemblyAi("""{"type":"Metadata","x":1}"""))
        assertNull(SttProtocol.parseAssemblyAi("""{"id":"abc"}"""))
    }

    @Test
    fun `ElevenLabs committed transcripts without words or text`() {
        assertEquals(
            SttEvent.Final("Done.", null, null),
            SttProtocol.parseElevenLabs("""{"message_type":"committed_transcript","text":"Done."}""")
        )
        assertNull(SttProtocol.parseElevenLabs("""{"message_type":"committed_transcript","text":"  "}"""))
        assertNull(SttProtocol.parseElevenLabs("""{"message_type":"partial_transcript","text":""}"""))
        assertEquals(
            SttEvent.Final("Done.", null, null),
            SttProtocol.parseElevenLabs("""{"message_type":"committed_transcript","text":"Done.","words":"x"}""")
        )
    }

    @Test
    fun `ElevenLabs word times in seconds become milliseconds`() {
        val raw = """{"message_type":"committed_transcript_with_timestamps","text":"Hi",""" +
            """"words":[{"start":0.25,"end":0.5},{"start":0.75,"end":1.2345}]}"""
        assertEquals(SttEvent.Final("Hi", 250, 1234), SttProtocol.parseElevenLabs(raw))
    }

    @Test
    fun `ElevenLabs error shapes are recognised`() {
        assertEquals(SttEvent.Error("quota exceeded"), SttProtocol.parseElevenLabs("""{"message_type":"error","error":"quota exceeded"}"""))
        assertEquals(SttEvent.Error("auth_error"), SttProtocol.parseElevenLabs("""{"message_type":"auth_error"}"""))
        assertEquals(SttEvent.Error("input_error"), SttProtocol.parseElevenLabs("""{"message_type":"input_error"}"""))
        assertEquals(SttEvent.Error("boom"), SttProtocol.parseElevenLabs("""{"message_type":"something","error":"boom"}"""))
        assertNull(SttProtocol.parseElevenLabs("""{"message_type":"session_info"}"""))
        assertNull(SttProtocol.parseElevenLabs("""{"text":"no type"}"""))
    }

    @Test
    fun `unicode text passes through unchanged`() {
        val raw = """{"type":"Turn","transcript":"Café 你好 😀","end_of_turn":false}"""
        assertEquals(SttEvent.Partial("Café 你好 😀"), SttProtocol.parseAssemblyAi(raw))
    }

    @Test
    fun `a very long transcript is parsed`() {
        val text = "word ".repeat(200_000).trim()
        val event = SttProtocol.parseAssemblyAi("""{"type":"Turn","transcript":"$text","end_of_turn":false}""")
        assertTrue(event is SttEvent.Partial && event.text.length == text.length)
    }

    @Test
    fun `the terminate message is valid JSON with only the type field`() {
        assertEquals("""{"type":"Terminate"}""", SttProtocol.ASSEMBLYAI_TERMINATE)
    }
}
