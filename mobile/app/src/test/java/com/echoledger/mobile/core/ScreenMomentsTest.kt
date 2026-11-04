package com.echoledger.mobile.core

import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.data.SegmentDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ScreenMomentsTest {
    private val segments = listOf(
        SegmentDto(1, "Me", 0, 4_000, "Can you open the invoice?"),
        SegmentDto(2, "Client", 5_000, 9_000, "Yes, this page here."),
        SegmentDto(1, "Me", 12_000, 15_000, "Click Pay.")
    )

    private val screens = listOf(
        ScreenDto("b", index = 2, atMs = 13_000, description = "The Pay button"),
        ScreenDto("a", index = 1, atMs = 6_500, description = "The client's invoice page"),
        ScreenDto("early", index = 0, atMs = 0)
    )

    @Test
    fun `each screen follows the last line that started before it, an early one goes with the first line`() {
        val byLine = ScreenMoments.byLine(segments, screens)
        assertEquals(listOf("early"), byLine[0]?.map { it.id })
        assertEquals(listOf("a"), byLine[1]?.map { it.id })
        assertEquals(listOf("b"), byLine[2]?.map { it.id })
        assertTrue(ScreenMoments.byLine(emptyList(), screens).isEmpty())
        assertEquals("Screen 1 · 0:06", ScreenMoments.label(screens[1]))
        assertEquals("[Screen 2 at 0:13: The Pay button]", ScreenMoments.marker(screens[0]))
    }

    @Test
    fun `Copy for AI puts the screens after their lines, plain Copy does not`() {
        val forAi = TranscriptText.copied(segments, { null }, false, "brackets", 0.5, true, forAi = true, screens = screens.take(2))
        val lines = forAi.lines()
        val invoice = lines.indexOf("Client: Yes, this page here.")
        assertEquals("[Screen 1 at 0:06: The client's invoice page]", lines[invoice + 1])
        assertEquals("[Screen 2 at 0:13: The Pay button]", lines.last())
        val plain = TranscriptText.copied(segments, { null }, false, "brackets", 0.5, false, forAi = false, screens = screens)
        assertTrue(plain.lines().none { it.startsWith("[Screen") })
    }
}
