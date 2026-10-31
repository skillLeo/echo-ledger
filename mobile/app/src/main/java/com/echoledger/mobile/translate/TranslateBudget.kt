package com.echoledger.mobile.translate

import android.content.Context
import java.time.YearMonth
import java.time.ZoneId

/**
 * What Live Translate costs (plan B1/B7). Soniox bills real-time audio by the time the stream is open, so the box
 * closes the stream while nothing is heard for long (see docs/research/soniox-live-translate.md). The rate is an
 * estimate kept in one place; Usage in the console shows what was actually reported.
 */
object TranslateCost {
    /** US dollars per hour of open stream, transcription and translation together. */
    const val USD_PER_HOUR = 0.18

    /** Upload while someone speaks: 16 kHz mono 16-bit PCM is 32 KB a second, about 115 MB an hour. */
    const val MB_PER_HOUR_SPEAKING = 115

    fun usd(ms: Long): Double = ms / 3_600_000.0 * USD_PER_HOUR

    /** "About $0.18 an hour and up to 115 MB of data while someone speaks." */
    fun estimate(): String = "About $${"%.2f".format(java.util.Locale.US, USD_PER_HOUR)} an hour, and up to $MB_PER_HOUR_SPEAKING MB of " +
        "mobile data an hour while someone speaks (nothing while it is quiet)."
}

/** Where this month's spending stands against the monthly budget. */
enum class BudgetState { OK, WARN, PAUSED, OVERRIDDEN }

object TranslateBudgetGuard {
    const val WARN_AT = 0.8

    fun state(spentUsd: Double, budgetUsd: Double, overrideMonth: String?, month: String): BudgetState {
        if (budgetUsd <= 0.0) return BudgetState.OK
        val used = spentUsd / budgetUsd
        return when {
            used >= 1.0 && overrideMonth == month -> BudgetState.OVERRIDDEN
            used >= 1.0 -> BudgetState.PAUSED
            used >= WARN_AT -> BudgetState.WARN
            else -> BudgetState.OK
        }
    }

    /** What the box or the panel says; null while all is well. */
    fun message(state: BudgetState, spentUsd: Double, budgetUsd: Double): String? = when (state) {
        BudgetState.OK, BudgetState.OVERRIDDEN -> null

        BudgetState.WARN -> "Live Translate has used ${money(spentUsd)} of its ${money(budgetUsd)} this month."

        BudgetState.PAUSED -> "Live Translate is paused: this month's ${money(budgetUsd)} budget is used up. " +
            "Raise the budget or continue anyway in Game translate settings."
    }

    fun money(usd: Double): String = "$" + "%.2f".format(java.util.Locale.US, usd)
}

/**
 * Minutes of Live Translate per month and mode, on this phone only (plan B1: "track minutes per mode"). Small private
 * preferences; nothing about what was heard. The same minutes are reported to the server for Usage.
 */
class TranslateUsage(context: Context, private val zone: ZoneId = ZoneId.systemDefault()) {
    private val prefs = context.applicationContext.getSharedPreferences("live_translate_usage", Context.MODE_PRIVATE)

    fun month(): String = YearMonth.now(zone).toString()

    @Synchronized
    fun add(mode: TranslateMode, ms: Long) {
        if (ms <= 0) return
        val key = "${month()}|${mode.key}"
        prefs.edit().putLong(key, prefs.getLong(key, 0L) + ms).apply()
    }

    fun ms(mode: TranslateMode, month: String = month()): Long = prefs.getLong("$month|${mode.key}", 0L)

    fun spentUsd(month: String = month()): Double = TranslateMode.entries.sumOf { TranslateCost.usd(ms(it, month)) }
}

/**
 * Why Live Translate cannot run right now, from Soniox's or the server's last refusal (since 1.3.2): the Record sheet
 * and the Game and Watch pages say "Needs Soniox credit" instead of letting the person start it to find out. Cleared
 * as soon as a session goes live.
 */
object TranslateStatus {
    private const val KEY = "blocked"

    fun blocked(context: Context): String? =
        context.applicationContext.getSharedPreferences("live_translate_usage", Context.MODE_PRIVATE).getString(KEY, null)

    /** Remembers the short reason for [notice] (null clears it). */
    fun remember(context: Context, notice: String?) {
        val short = shortReason(notice)
        context.applicationContext.getSharedPreferences("live_translate_usage", Context.MODE_PRIVATE).edit().apply {
            if (short == null) remove(KEY) else putString(KEY, short)
        }.apply()
    }

    /** "Needs Soniox credit", "Needs a Soniox key", "Budget used up", or null for anything passing. */
    fun shortReason(notice: String?): String? {
        val text = notice?.lowercase() ?: return null
        return when {
            "credit" in text -> "Needs Soniox credit"
            "soniox key" in text || "did not accept the key" in text -> "Needs a Soniox key"
            "budget" in text -> "Budget used up"
            else -> null
        }
    }
}
