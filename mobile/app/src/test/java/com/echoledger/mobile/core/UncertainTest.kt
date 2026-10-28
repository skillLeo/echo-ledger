package com.echoledger.mobile.core

import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.WordDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** What the engine was not sure of (v1.2): the threshold, the shown line and where a tap plays from. */
class UncertainTest {
    private val words = listOf(WordDto("Hello", 0, 400, 0.98), WordDto("Farah", 450, 900, 0.31), WordDto("Khan", 950, 1300))
    private val segment = SegmentDto(1, "Me", 0, 1300, "Hello Farah Khan", words)

    @Test
    fun `a word is uncertain only below the threshold`() {
        assertTrue(Uncertain.word(WordDto("a", confidence = 0.49)))
        assertFalse(Uncertain.word(WordDto("a", confidence = Uncertain.BELOW)))
        assertFalse(Uncertain.word(WordDto("a", confidence = 0.98)))
        assertFalse(Uncertain.word(WordDto("a")))
        assertTrue(Uncertain.hasWords(segment))
        assertFalse(Uncertain.hasWords(segment.copy(words = listOf(WordDto("Hello", confidence = 0.9)))))
    }

    @Test
    fun `the line is the words joined by spaces with each word's range`() {
        val (text, ranges) = Uncertain.layout(words)

        assertEquals("Hello Farah Khan", text)
        assertEquals(listOf(0..4, 6..10, 12..15), ranges)
    }

    @Test
    fun `a tap on an uncertain word plays from it and anywhere else from the line's start`() {
        val (_, ranges) = Uncertain.layout(words)

        assertEquals(450L, Uncertain.playFrom(segment, ranges, 8))
        assertEquals(0L, Uncertain.playFrom(segment, ranges, 2))
        assertEquals(0L, Uncertain.playFrom(segment, ranges, 13))
        assertEquals(0L, Uncertain.playFrom(segment, ranges, 5))
    }

    @Test
    fun `scores are read from the server and the phone's own lines never send them`() {
        val json = """{"channel":1,"speaker":"Me","start_ms":0,"end_ms":900,"text":"Farah","uncertain":true,
            "words":[{"text":"Farah","start_ms":0,"end_ms":900,"confidence":0.31}]}"""
        val read = ApiJson.decodeFromString(SegmentDto.serializer(), json)

        assertTrue(read.uncertain)
        assertEquals(0.31, read.words.single().confidence!!, 0.0001)
        val sent = ApiJson.encodeToString(SegmentDto.serializer(), SegmentDto(1, "Me", 0, 900, "Hi"))
        assertFalse(sent.contains("uncertain"))
        assertFalse(sent.contains("words"))
    }
}
