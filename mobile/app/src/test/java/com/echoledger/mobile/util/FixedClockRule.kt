package com.echoledger.mobile.util

import org.junit.rules.TestWatcher
import org.junit.runner.Description
import java.time.Instant
import java.time.ZoneId

/**
 * Pins [AppClock] for a test, so dates and "today" in a screenshot are the same on every day it runs:
 *   @get:Rule val clock = FixedClockRule()                         // 2026-11-17 06:42 UTC, as AppTest sets the zone
 *   @get:Rule val clock = FixedClockRule(Instant.parse("…"), ZoneId.of("…"))
 * Test data should be built from [now] / [AppClock.millis], not System.currentTimeMillis().
 */
class FixedClockRule(
    val instant: Instant = Instant.parse("2026-11-17T06:42:00Z"),
    val zone: ZoneId = ZoneId.of("UTC"),
) : TestWatcher() {
    val now: Instant get() = instant

    override fun starting(description: Description) = AppClock.fix(instant, zone)

    override fun finished(description: Description) = AppClock.reset()
}
