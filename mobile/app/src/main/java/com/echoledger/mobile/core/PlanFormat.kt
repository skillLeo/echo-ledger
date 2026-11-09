package com.echoledger.mobile.core

import java.time.Instant
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * The words and numbers of Settings and Plan & usage (since v1.7, docs/DESIGN_SYSTEM.md "Settings"): one formatter for
 * sizes, minutes, money and dates, so every screen says "120 MB of 1 GB", "230 of 300 min", "Rs 2,999 a month" and
 * "9 Dec 2026" the same way. Pure; unit-tested.
 */
object PlanFormat {
    private val day = DateTimeFormatter.ofPattern("d MMM", Locale.ENGLISH)
    private val dayYear = DateTimeFormatter.ofPattern("d MMM yyyy", Locale.ENGLISH)
    private val long = DateTimeFormatter.ofPattern("d MMMM yyyy", Locale.ENGLISH)

    /** "640 KB", "120 MB", "1 GB", "1.4 GB". */
    fun fileSize(bytes: Long): String {
        val kb = 1024.0
        val mb = kb * 1024
        val gb = mb * 1024
        return when {
            bytes < mb -> "${maxOf(1, Math.round(bytes / kb))} KB"

            bytes < gb -> "${Math.round(bytes / mb)} MB"

            else -> {
                val v = Math.round(bytes / gb * 10) / 10.0
                if (v % 1.0 == 0.0) "${v.toLong()} GB" else "$v GB"
            }
        }
    }

    /** "Rs 2,999". */
    fun rupees(amount: Long): String = "Rs " + "%,d".format(Locale.ENGLISH, amount)

    /** A plan's price line: the server's text, else "Rs 2,999 a month" from the number, else its older price string. */
    fun price(text: String?, pkr: Long?, legacy: String? = null): String? = text?.takeIf { it.isNotBlank() }
        ?: pkr?.takeIf { it > 0 }?.let { "${rupees(it)} a month" }
        ?: legacy?.takeIf {
            it.isNotBlank() && !it.contains("ask", ignoreCase = true)
        }?.replace(" / month", " a month")?.replace("/month", " a month")

    /** A limit's value text: "230 of 300 min", "1 of 2", "120 MB of 1 GB", "1 year"; no limit: "No limit". */
    fun limit(key: String, used: Long?, limit: Long?): String {
        fun value(v: Long) = when (key) {
            "storage_bytes" -> fileSize(v)
            "monthly_minutes" -> "%,d".format(Locale.ENGLISH, v) + " min"
            "retention_days" -> days(v)
            "max_file_minutes" -> hours(v)
            else -> "%,d".format(Locale.ENGLISH, v)
        }
        return when {
            limit == null -> "No limit"
            used == null -> value(limit)
            key == "storage_bytes" -> "${fileSize(used)} of ${fileSize(limit)}"
            key == "monthly_minutes" -> "%,d".format(Locale.ENGLISH, used) + " of " + value(limit)
            else -> "${value(used)} of ${value(limit)}"
        }
    }

    /** How full a limit is, 0..1, or null when it has no limit or nothing is counted. */
    fun fraction(used: Long?, limit: Long?): Float? {
        if (used == null || limit == null || limit <= 0) return null
        return (used.toFloat() / limit).coerceIn(0f, 1f)
    }

    /** 365 → "1 year", 730 → "2 years", 30 → "30 days". */
    fun days(d: Long): String = when {
        d >= 365 && d % 365 == 0L -> if (d == 365L) "1 year" else "${d / 365} years"
        d == 1L -> "1 day"
        else -> "$d days"
    }

    /** 180 → "3 hours", 90 → "90 minutes". */
    fun hours(minutes: Long): String = when {
        minutes >= 60 && minutes % 60 == 0L -> if (minutes == 60L) "1 hour" else "${minutes / 60} hours"
        else -> "$minutes minutes"
    }

    fun parse(iso: String?): Instant? = iso?.takeIf { it.isNotBlank() }?.let {
        runCatching { OffsetDateTime.parse(it).toInstant() }.getOrNull()
            ?: runCatching { Instant.parse(it) }.getOrNull()
            ?: runCatching { LocalDate.parse(it.take(10)).atStartOfDay(ZoneId.systemDefault()).toInstant() }.getOrNull()
    }

    /**
     * The calendar day of [iso]: a time with an offset ("2026-12-09T00:00:00+05:00", a plan's end or the minutes reset)
     * keeps the day the server meant; a UTC instant ("…Z") is shown in the phone's time zone.
     */
    fun calendarDay(iso: String?, zone: ZoneId = ZoneId.systemDefault()): LocalDate? {
        val text = iso?.takeIf { it.isNotBlank() } ?: return null
        if (!text.endsWith("Z") && text.length > 10) {
            runCatching { return OffsetDateTime.parse(text).toLocalDate() }
        }
        return parse(text)?.atZone(zone)?.toLocalDate()
    }

    /** A row's date: "9 Dec" this year, else "9 Dec 2026". */
    fun shortDate(iso: String?, zone: ZoneId = ZoneId.systemDefault(), today: LocalDate = LocalDate.now(zone)): String? {
        val d = calendarDay(iso, zone) ?: return null
        return if (d.year == today.year) d.format(day) else d.format(dayYear)
    }

    /** A row's date with the year always: "9 Dec 2026". */
    fun dateWithYear(iso: String?, zone: ZoneId = ZoneId.systemDefault()): String? = calendarDay(iso, zone)?.format(dayYear)

    /** A sentence's date: "9 December 2026". */
    fun longDate(iso: String?, zone: ZoneId = ZoneId.systemDefault()): String? = calendarDay(iso, zone)?.format(long)

    /** A time of day in the phone's clock format: "3:42 pm" or "15:42". */
    fun time(iso: String?, is24: Boolean, zone: ZoneId = ZoneId.systemDefault()): String? {
        val t = parse(iso)?.atZone(zone) ?: return null
        return if (is24) {
            t.format(
                DateTimeFormatter.ofPattern("HH:mm", Locale.ENGLISH)
            )
        } else {
            t.format(DateTimeFormatter.ofPattern("h:mm a", Locale.ENGLISH)).lowercase(Locale.ENGLISH)
        }
    }

    /** Whole days from now until [iso] (0 today, negative once past), or null. */
    fun daysUntil(iso: String?, zone: ZoneId = ZoneId.systemDefault(), today: LocalDate = LocalDate.now(zone)): Long? {
        val d = calendarDay(iso, zone) ?: return null
        return java.time.temporal.ChronoUnit.DAYS.between(today, d)
    }

    /** The 1st of next month, the day minutes reset when the server does not say (Asia/Karachi). */
    fun nextReset(today: LocalDate = LocalDate.now(ZoneId.of("Asia/Karachi"))): String = today.withDayOfMonth(1).plusMonths(1).format(day)

    /** An IBAN in groups of four: "PK36 SCBL 0000 0011 2345 6702". */
    fun groupIban(iban: String): String = iban.replace(" ", "").chunked(4).joinToString(" ")
}
