package com.echoledger.mobile.ui

import java.time.ZoneId
import java.util.Locale
import org.junit.Assert.assertEquals
import org.junit.Test

class FormatWhenTest {
    private val karachi = ZoneId.of("Asia/Karachi") // UTC+5, no daylight saving
    private val us = Locale.US

    @Test
    fun `converts a UTC timestamp to the viewer zone in 12-hour AM-PM format`() {
        assertEquals("Oct 24 · 9:32 AM", formatWhen("2026-10-24T04:32:00+00:00", karachi, us))
        assertEquals("Oct 24 · 6:25 PM", formatWhen("2026-10-24T13:25:00+00:00", karachi, us))
    }

    @Test
    fun `rolls the date over when the viewer zone is on the next day`() {
        assertEquals("Oct 25 · 1:30 AM", formatWhen("2026-10-24T20:30:00+00:00", karachi, us))
    }

    @Test
    fun `midnight and noon render as 12 AM and 12 PM`() {
        assertEquals("Oct 24 · 12:00 AM", formatWhen("2026-10-23T19:00:00+00:00", karachi, us))
        assertEquals("Oct 24 · 12:00 PM", formatWhen("2026-10-24T07:00:00+00:00", karachi, us))
    }

    @Test
    fun `missing or malformed input is blank rather than a crash`() {
        assertEquals("", formatWhen(null, karachi, us))
        assertEquals("", formatWhen("not a date", karachi, us))
    }
}
