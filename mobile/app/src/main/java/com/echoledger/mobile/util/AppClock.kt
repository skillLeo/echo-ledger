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
    /** Set by tests only. Otherwise the system's clock in the system's zone, read on every call (a trip or a new
     * time zone shows at once, without a restart). */
    @Volatile
    private var fixed: Clock? = null

    private val clock: Clock get() = fixed ?: Clock.system(ZoneId.systemDefault())

    fun now(): Instant = clock.instant()

    fun millis(): Long = clock.millis()

    fun zone(): ZoneId = clock.zone

    fun today(zone: ZoneId = zone()): LocalDate = LocalDate.now(clock.withZone(zone))

    /** Tests only: a fixed instant in a fixed zone, until [reset]. */
    fun fix(instant: Instant, zone: ZoneId) {
        fixed = Clock.fixed(instant, zone)
    }

    fun reset() {
        fixed = null
    }
}
