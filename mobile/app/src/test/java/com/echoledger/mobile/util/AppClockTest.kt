package com.echoledger.mobile.util

import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class AppClockTest {
    @After fun reset() = AppClock.reset()

    // Review: a clock made once kept the zone it started with, so a trip or a new time zone showed only after a restart.
    @Test fun `without a fixed clock the zone follows the system's, read each time`() {
        val saved = java.util.TimeZone.getDefault()
        try {
            java.util.TimeZone.setDefault(java.util.TimeZone.getTimeZone("Asia/Karachi"))
            assertEquals(ZoneId.of("Asia/Karachi"), AppClock.zone())
            java.util.TimeZone.setDefault(java.util.TimeZone.getTimeZone("America/Los_Angeles"))
            assertEquals(ZoneId.of("America/Los_Angeles"), AppClock.zone())
        } finally {
            java.util.TimeZone.setDefault(saved)
        }
    }

    @Test fun `a fixed clock gives the same now, zone and today until reset`() {
        AppClock.fix(Instant.parse("2026-11-17T20:30:00Z"), ZoneId.of("Asia/Karachi"))
        assertEquals(Instant.parse("2026-11-17T20:30:00Z"), AppClock.now())
        assertEquals(ZoneId.of("Asia/Karachi"), AppClock.zone())
        // 01:30 the next day in Karachi; still the 17th in UTC.
        assertEquals(LocalDate.parse("2026-11-18"), AppClock.today())
        assertEquals(LocalDate.parse("2026-11-17"), AppClock.today(ZoneId.of("UTC")))
        AppClock.reset()
        assertTrue(kotlin.math.abs(AppClock.millis() - System.currentTimeMillis()) < 5_000)
    }
}
