package com.echoledger.mobile.util

import java.time.Clock
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId

/**
 * The time and time zone the screens show dates and times against: the system's by default. Screenshot tests pin it
 * (FixedClockRule) so a picture doesn't change with the day it is taken (F2).
 */
object AppClock {
    @Volatile
    private var clock: Clock = Clock.systemDefaultZone()

    fun now(): Instant = clock.instant()

    fun millis(): Long = clock.millis()

    fun zone(): ZoneId = clock.zone

    fun today(zone: ZoneId = zone()): LocalDate = LocalDate.now(clock.withZone(zone))

    /** Tests only: a fixed instant in a fixed zone, until [reset]. */
    fun fix(instant: Instant, zone: ZoneId) {
        clock = Clock.fixed(instant, zone)
    }

    fun reset() {
        clock = Clock.systemDefaultZone()
    }
}
