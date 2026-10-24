package com.echoledger.mobile.net

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class SttProtocolTest {
    @Test
    fun `assemblyai begin and termination map to lifecycle events`() {
        assertEquals(SttEvent.Started, SttProtocol.parseAssemblyAi("""{"type":"Begin","id":"s1"}"""))
        assertEquals(SttEvent.Terminated, SttProtocol.parseAssemblyAi("""{"type":"Termination"}"""))
    }

    @Test
    fun `assemblyai partial turns are partials and unformatted finals are dropped`() {
        val partial = SttProtocol.parseAssemblyAi("""{"type":"Turn","transcript":"hello wor","end_of_turn":false}""")
        assertEquals(SttEvent.Partial("hello wor"), partial)
        // end of turn but not yet formatted → wait for the formatted version
        assertNull(
            SttProtocol.parseAssemblyAi("""{"type":"Turn","transcript":"hello world","end_of_turn":true,"turn_is_formatted":false}""")
        )
    }

    @Test
    fun `assemblyai formatted final carries word timings`() {
        val raw = """{"type":"Turn","transcript":"Hello world.","end_of_turn":true,"turn_is_formatted":true,
            "words":[{"start":120,"end":480,"text":"Hello"},{"start":500,"end":900,"text":"world"}]}"""
        val event = SttProtocol.parseAssemblyAi(raw)
        assertEquals(SttEvent.Final("Hello world.", 120, 900), event)
    }

    @Test
    fun `elevenlabs partial and committed transcripts map to events`() {
        assertEquals(SttEvent.Started, SttProtocol.parseElevenLabs("""{"message_type":"session_started"}"""))
        assertEquals(
            SttEvent.Partial("partial text"),
            SttProtocol.parseElevenLabs("""{"message_type":"partial_transcript","text":"partial text"}""")
        )
        val raw = """{"message_type":"committed_transcript_with_timestamps","text":"Done.",
            "words":[{"start":1.0,"end":1.5},{"start":1.6,"end":2.0}]}"""
        assertEquals(SttEvent.Final("Done.", 1000, 2000), SttProtocol.parseElevenLabs(raw))
    }

    @Test
    fun `malformed json is ignored rather than throwing`() {
        assertNull(SttProtocol.parseAssemblyAi("not json"))
        assertNull(SttProtocol.parseElevenLabs("{"))
    }

    @Test
    fun `the terminate message is the documented shape`() {
        assertTrue(SttProtocol.ASSEMBLYAI_TERMINATE.contains("\"Terminate\""))
    }
}
