package com.echoledger.mobile.account

import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.data.PasswordRulesDto
import com.echoledger.mobile.data.Proof
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

/**
 * What the proof sheet asks for (docs/API.md "Proof"): with two-step on, always a code (an authenticator or recovery
 * code; the password too when the account has one, as the console asks); without two-step, the password; with neither
 * a password nor two-step (an account made with Google), a code emailed to the current address.
 */
data class ProofNeed(val password: Boolean, val code: Boolean, val emailCode: Boolean) {
    /** Whether what was typed is enough to send. */
    fun ready(password: String, code: String, emailCode: String): Boolean = (!this.password || password.isNotEmpty()) &&
        (!this.code || ProofNeeds.codeLooksRight(code)) &&
        (!this.emailCode || ProofNeeds.emailCodeLooksRight(emailCode))

    fun proof(password: String, code: String, emailCode: String): Proof = Proof(
        password = password.takeIf { this.password },
        otp = code.takeIf { this.code },
        emailCode = emailCode.takeIf { this.emailCode }
    )
}

object ProofNeeds {
    /**
     * [accepts] is the server's `accepts` of a `proof_required` (or of `invalid_otp`): when given, the sheet asks only
     * for what it names. [codeOnly]: the step takes an authenticator or recovery code and nothing else (turning
     * two-step off, new recovery codes).
     */
    fun of(hasPassword: Boolean, twoFactor: Boolean, accepts: List<String> = emptyList(), codeOnly: Boolean = false): ProofNeed {
        if (codeOnly) return ProofNeed(password = false, code = true, emailCode = false)
        if (accepts.isNotEmpty()) {
            val code = "otp" in accepts && (twoFactor || "password" !in accepts)
            val password = "password" in accepts && hasPassword && (!code || twoFactor)
            val email = "email_code" in accepts && !password && !code
            if (password || code || email) return ProofNeed(password, code, email)
        }
        return when {
            twoFactor -> ProofNeed(password = hasPassword, code = true, emailCode = false)
            hasPassword -> ProofNeed(password = true, code = false, emailCode = false)
            else -> ProofNeed(password = false, code = false, emailCode = true)
        }
    }

    /**
     * What to ask next after the server's [problem]: a `proof_required` asks for what its `accepts` names; a right
     * password without the two-step code (`invalid_otp`, accepts `["otp"]`) adds the code. Anything else asks the same.
     */
    fun after(need: ProofNeed, problem: AccountProblem?, hasPassword: Boolean, twoFactor: Boolean): ProofNeed = when (problem) {
        is AccountProblem.ProofRequired -> of(hasPassword, twoFactor || problem.accepts == listOf("otp"), problem.accepts)
        is AccountProblem.InvalidOtp -> if (problem.missing) need.copy(code = true, emailCode = false) else need
        else -> need
    }

    /** The field a proof problem belongs under ("password", "code", "email_code"); null for a banner. */
    fun fieldOf(problem: AccountProblem?): String? = when (problem) {
        AccountProblem.WrongPassword -> "password"
        is AccountProblem.InvalidOtp -> "code"
        AccountProblem.InvalidCode, AccountProblem.Expired -> "email_code"
        else -> null
    }

    /** A 6-digit authenticator code, or a recovery code (letters, digits and dashes, at least 8). */
    fun codeLooksRight(code: String): Boolean {
        val c = code.trim().replace(" ", "")
        return (c.length == 6 && c.all(Char::isDigit)) || (c.length >= 8 && c.all { it.isLetterOrDigit() || it == '-' })
    }

    fun emailCodeLooksRight(code: String): Boolean = code.trim().replace(" ", "").let { it.length == 6 && it.all(Char::isDigit) }
}

/** A new password against the server's rules, before it is sent; the codes are the server's own (`too_short`...). */
object PasswordCheck {
    val DEFAULT = PasswordRulesDto()

    fun problems(new: String, rules: PasswordRulesDto?, current: String? = null): List<FieldCode> {
        val r = rules ?: DEFAULT
        val out = mutableListOf<FieldCode>()
        if (new.isEmpty()) return listOf(FieldCode("required"))
        if (new.length < r.min) out += FieldCode("too_short", min = r.min)
        if (r.letters && new.none(Char::isLetter)) out += FieldCode("needs_letters")
        if (r.numbers && new.none(Char::isDigit)) out += FieldCode("needs_numbers")
        if (!current.isNullOrEmpty() && current == new) out += FieldCode("same_as_current")
        return out
    }
}

/** The email change's "Check your new address" screen: when it is over. */
object EmailChange {
    const val POLL_MS = 10_000L

    /** The change is over (confirmed by code or link, cancelled, blocked or expired) once nothing waits. */
    fun finished(account: AccountDto?): Boolean = account != null && account.pendingEmail == null

    /** A plausible address, before anything is sent (the server still checks). */
    fun looksLikeEmail(text: String): Boolean = Regex("""^[^\s@]+@[^\s@]+\.[^\s@]+$""").matches(text.trim())

    fun sameAsNow(text: String, now: String?): Boolean = now != null && text.trim().equals(now.trim(), ignoreCase = true)
}

/** The account screens' formatting, for people and for TalkBack. */
object AccountFormat {
    /** The authenticator's manual key in groups of four ("JBSW Y3DP EHPK 3PXP"). */
    fun grouped(secret: String): String = secret.replace(" ", "").chunked(4).joinToString(" ")

    /** A code or key read one character at a time ("A B 1 2"), so TalkBack never reads it as a word or a number. */
    fun spoken(code: String): String = code.replace(" ", "").toList().joinToString(" ")

    /** The 6 digits of a code, spaced for reading ("123 456"). */
    fun digits(code: String): String = code.filter(Char::isDigit).take(6)

    /** The recovery codes as a text file (Copy, Share, Save). */
    fun recoveryText(heading: String, codes: List<String>): String = (listOf(heading, "") + codes).joinToString("\n") + "\n"

    /** A day for people ("21 Nov 2026"), in [locale]; the text as it came when it isn't a date. */
    fun day(iso: String?, locale: Locale = Locale.getDefault()): String? {
        if (iso.isNullOrBlank()) return null
        return runCatching {
            OffsetDateTime.parse(iso).atZoneSameInstant(java.time.ZoneId.systemDefault())
                .format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(locale))
        }.getOrElse { iso.take(10) }
    }

    /** A day and time for people ("21 Nov 2026, 17:00"), in [locale]. */
    fun dayAndTime(iso: String?, locale: Locale = Locale.getDefault()): String? {
        if (iso.isNullOrBlank()) return null
        return runCatching {
            OffsetDateTime.parse(iso).atZoneSameInstant(java.time.ZoneId.systemDefault())
                .format(DateTimeFormatter.ofLocalizedDateTime(FormatStyle.MEDIUM, FormatStyle.SHORT).withLocale(locale))
        }.getOrElse { iso.take(16).replace('T', ' ') }
    }

    /** A wait in whole minutes, at least 1 ("try again in 3 minutes"). */
    fun minutes(seconds: Long?): Int = (((seconds ?: 60L) + 59) / 60).toInt().coerceAtLeast(1)

    /** Left-to-right text (an address, a code) kept whole inside a right-to-left sentence. */
    fun ltr(text: String): String = "⁦$text⁩"

    /** People's own words (a name) isolated in either direction. */
    fun isolate(text: String): String = "⁨$text⁩"
}
