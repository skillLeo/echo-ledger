package com.echoledger.mobile.util

import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId

class AppClockTest {
    @After fun reset() = AppClock.reset()

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
