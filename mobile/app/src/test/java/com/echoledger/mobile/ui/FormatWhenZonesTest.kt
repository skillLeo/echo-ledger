package com.echoledger.mobile.ui

import java.time.ZoneId
import java.util.Locale
import java.util.TimeZone
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** Library timestamps across time zones, daylight-saving changes, midnight and locales. */
class FormatWhenZonesTest {
    private val us = Locale.US

    private fun at(iso: String, zone: String) = formatWhen(iso, ZoneId.of(zone), us)

    @Test
    fun `the same instant reads differently in zones from UTC minus 11 to UTC plus 14`() {
        val iso = "2026-10-24T09:00:00+00:00"
        assertEquals("Oct 24 · 9:00 AM", at(iso, "UTC"))
        assertEquals("Oct 23 · 10:00 PM", at(iso, "Pacific/Pago_Pago"))
        assertEquals("Oct 24 · 11:00 PM", at(iso, "Pacific/Kiritimati"))
        assertEquals("Oct 24 · 2:30 PM", at(iso, "Asia/Kolkata"))
        assertEquals("Oct 24 · 2:00 PM", at(iso, "Asia/Karachi"))
    }

    @Test
    fun `zones with unusual offsets keep their minutes`() {
        assertEquals("Oct 24 · 10:45 PM", at("2026-10-24T09:00:00Z", "Pacific/Chatham")) // UTC+13:45 in southern summer
        assertEquals("Oct 24 · 2:45 PM", at("2026-10-24T09:00:00Z", "Asia/Kathmandu")) // UTC+5:45
        assertEquals("Oct 24 · 5:45 PM", at("2026-10-24T09:00:00Z", "Australia/Eucla")) // UTC+8:45
    }

    @Test
    fun `an offset in the input is honoured rather than read as the local wall clock`() {
        assertEquals("Oct 24 · 9:00 AM", at("2026-10-24T14:00:00+05:00", "UTC"))
        assertEquals("Oct 24 · 9:00 AM", at("2026-10-24T04:00:00-05:00", "UTC"))
        assertEquals("Oct 24 · 9:00 AM", at("2026-10-24T09:00:00Z", "UTC"))
        assertEquals("Oct 24 · 9:00 AM", at("2026-10-24T09:00:00.123456Z", "UTC"))
    }

    @Test
    fun `the US daylight-saving start skips the missing hour`() {
        assertEquals("Mar 8 · 1:59 AM", at("2026-03-08T06:59:59Z", "America/New_York"))
        assertEquals("Mar 8 · 3:00 AM", at("2026-03-08T07:00:00Z", "America/New_York"))
    }

    @Test
    fun `the repeated hour at the end of daylight saving still shows both instants`() {
        assertEquals("Nov 1 · 1:30 AM", at("2026-11-01T05:30:00Z", "America/New_York")) // 01:30 EDT
        assertEquals("Nov 1 · 1:30 AM", at("2026-11-01T06:30:00Z", "America/New_York")) // 01:30 EST
        assertEquals("Oct 25 · 1:30 AM", at("2026-10-25T00:30:00Z", "Europe/London")) // 01:30 BST
        assertEquals("Oct 25 · 1:30 AM", at("2026-10-25T01:30:00Z", "Europe/London")) // 01:30 GMT
    }

    @Test
    fun `midnight and noon are 12 AM and 12 PM and the date rolls over with the zone`() {
        assertEquals("Jan 1 · 12:00 AM", at("2026-12-31T19:00:00Z", "Asia/Karachi"))
        assertEquals("Jan 1 · 4:30 AM", at("2026-12-31T23:30:00+00:00", "Asia/Karachi"))
        assertEquals("Oct 24 · 12:00 PM", at("2026-10-24T12:00:00Z", "UTC"))
        assertEquals("Oct 24 · 12:59 AM", at("2026-10-24T00:59:59Z", "UTC"))
        assertEquals("Oct 24 · 11:59 PM", at("2026-10-24T23:59:59Z", "UTC"))
    }

    @Test
    fun `leap day and year boundaries render correctly`() {
        assertEquals("Feb 29 · 12:00 PM", at("2028-02-29T12:00:00Z", "UTC"))
        assertEquals("Mar 1 · 12:00 AM", at("2028-02-29T19:00:00Z", "Asia/Karachi"))
        assertEquals("Dec 31 · 11:59 PM", at("2026-12-31T23:59:00Z", "UTC"))
    }

    @Test
    fun `the day of the month has no leading zero and months are abbreviated`() {
        assertEquals("Jan 5 · 9:05 AM", at("2026-01-05T09:05:00Z", "UTC"))
        assertEquals("Sep 30 · 9:05 AM", at("2026-09-30T09:05:00Z", "UTC"))
    }

    @Test
    fun `input without a usable offset yields a blank label instead of a guess`() {
        listOf(
            "2026-10-24", "2026-10-24T09:00:00", "2026-10-24 09:00:00Z", "2026-10-24T24:00:00Z", "2026-13-40T09:00:00Z",
            "", " ", "0", "yesterday"
        ).forEach {
            assertEquals("[$it]", "", formatWhen(it, ZoneId.of("UTC"), us))
        }
        assertEquals("", formatWhen(null, ZoneId.of("UTC"), us))
    }

    @Test
    fun `other locales produce a non-blank date and time with the separator`() {
        listOf(
            Locale.UK,
            Locale.GERMANY,
            Locale.FRANCE,
            Locale.JAPAN,
            Locale.forLanguageTag("ar-EG"),
            Locale.forLanguageTag("ur-PK"),
            Locale.forLanguageTag("hi-IN")
        ).forEach { locale ->
            val text = formatWhen("2026-10-24T09:32:00Z", ZoneId.of("Asia/Karachi"), locale)
            assertTrue("$locale -> [$text]", text.isNotBlank() && text.contains(" · "))
        }
    }

    @Test
    fun `the default zone and locale come from the phone settings`() {
        val savedZone = TimeZone.getDefault()
        val savedLocale = Locale.getDefault()
        try {
            TimeZone.setDefault(TimeZone.getTimeZone("Asia/Karachi"))
            Locale.setDefault(Locale.US)
            assertEquals("Oct 24 · 2:00 PM", formatWhen("2026-10-24T09:00:00+00:00"))
            TimeZone.setDefault(TimeZone.getTimeZone("America/Los_Angeles"))
            assertEquals("Oct 24 · 2:00 AM", formatWhen("2026-10-24T09:00:00+00:00"))
        } finally {
            TimeZone.setDefault(savedZone)
            Locale.setDefault(savedLocale)
        }
    }

    // ---- durations --------------------------------------------------------------------------------------------------

    @Test
    fun `durations are blank when unknown and formatted as minutes or hours otherwise`() {
        assertEquals("", formatDuration(null))
        assertEquals("", formatDuration(0))
        assertEquals("", formatDuration(-5))
        assertEquals("0:05", formatDuration(5_000))
        assertEquals("2:05", formatDuration(125_000))
        assertEquals("59:59", formatDuration(3_599_000))
        assertEquals("1:00:00", formatDuration(3_600_000))
        assertEquals("1:02:05", formatDuration(3_725_000))
        assertEquals("10:00:00", formatDuration(36_000_000))
    }

    @Test
    fun `durations keep ASCII digits whatever the phone language is`() {
        val saved = Locale.getDefault()
        try {
            listOf("ar-EG", "fa-IR").map(Locale::forLanguageTag).forEach {
                Locale.setDefault(it)
                assertEquals("in $it", "2:05", formatDuration(125_000))
            }
        } finally {
            Locale.setDefault(saved)
        }
    }

    @Test
    fun `a duration reads the same on the phone and on the desktop client`() {
        // desktop/src/lib/format.ts rounds to the nearest second (Math.round); the library must agree between devices.
        val samples = mapOf(999L to "0:01", 1_500L to "0:02", 59_600L to "1:00", 125_900L to "2:06", 3_599_600L to "1:00:00")
        samples.forEach { (ms, desktop) -> assertEquals("$ms ms", desktop, formatDuration(ms)) }
    }

    @Test
    fun `zone ids used in these tests exist on this JVM`() {
        assertFalse(ZoneId.getAvailableZoneIds().isEmpty())
        listOf("Pacific/Chatham", "Asia/Kathmandu", "Pacific/Kiritimati").forEach { assertTrue(it, it in ZoneId.getAvailableZoneIds()) }
    }
}
