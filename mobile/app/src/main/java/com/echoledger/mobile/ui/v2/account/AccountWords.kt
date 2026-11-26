package com.echoledger.mobile.ui.v2.account

import android.content.res.Resources
import com.echoledger.mobile.R
import com.echoledger.mobile.account.AccountFormat
import com.echoledger.mobile.account.AccountProblem
import com.echoledger.mobile.account.FieldCode
import java.util.Locale

/**
 * The account screens' problems in plain words, in the screen's language (strings_v2_account.xml): the server's codes,
 * never its English sentences, so Urdu reads as Urdu.
 */
object AccountWords {
    private fun locale(res: Resources): Locale = res.configuration.locales[0] ?: Locale.getDefault()

    fun field(res: Resources, code: FieldCode): String = when (code.code) {
        "required" -> res.getString(R.string.v2_acct_err_required)
        "too_short" -> res.getString(R.string.v2_acct_err_too_short, code.min ?: 1)
        "too_long" -> res.getString(R.string.v2_acct_err_too_long, code.max ?: 120)
        "needs_letters" -> res.getString(R.string.v2_acct_err_needs_letters)
        "needs_numbers" -> res.getString(R.string.v2_acct_err_needs_numbers)
        "same_as_current" -> res.getString(R.string.v2_acct_err_same_as_current)
        "invalid_characters" -> res.getString(R.string.v2_acct_err_invalid_characters)
        "invalid_email" -> res.getString(R.string.v2_acct_err_invalid_email)
        "same_as_now" -> res.getString(R.string.v2_acct_err_same_as_now)
        "email_taken" -> res.getString(R.string.v2_acct_err_email_taken)
        "invalid_image" -> image(res, code.reason)
        else -> res.getString(R.string.v2_acct_err_other)
    }

    /** The first word for each of a field's codes, joined (a password too short and without numbers says both). */
    fun fields(res: Resources, codes: List<FieldCode>): String? = codes.takeIf { it.isNotEmpty() }?.joinToString(" ") { field(res, it) }

    fun image(res: Resources, reason: String?): String = res.getString(
        when (reason) {
            "broken" -> R.string.v2_acct_err_image_broken
            "too_small" -> R.string.v2_acct_err_image_too_small
            "ratio" -> R.string.v2_acct_err_image_ratio
            "too_many_pixels" -> R.string.v2_acct_err_image_too_big
            else -> R.string.v2_acct_err_image_format
        }
    )

    fun problem(res: Resources, p: AccountProblem): String = when (p) {
        AccountProblem.WrongPassword -> res.getString(R.string.v2_acct_err_wrong_password)

        is AccountProblem.InvalidOtp -> res.getString(if (p.missing) R.string.v2_acct_err_otp_missing else R.string.v2_acct_err_invalid_otp)

        AccountProblem.InvalidCode -> res.getString(R.string.v2_acct_err_invalid_code)

        is AccountProblem.TooManyAttempts -> AccountFormat.minutes(p.seconds).let {
            res.getQuantityString(R.plurals.v2_acct_err_too_many_attempts, it, it)
        }

        is AccountProblem.RateLimited -> AccountFormat.minutes(p.seconds).let {
            res.getQuantityString(R.plurals.v2_acct_err_rate_limited, it, it)
        }

        is AccountProblem.ProofRequired -> res.getString(R.string.v2_acct_err_proof_required)

        is AccountProblem.Fields -> fields(res, p.fields.values.flatten()) ?: res.getString(R.string.v2_acct_err_other)

        AccountProblem.EmailTaken -> res.getString(R.string.v2_acct_err_email_taken)

        is AccountProblem.EmailLocked -> AccountFormat.day(p.until, locale(res))?.let {
            res.getString(R.string.v2_acct_err_email_locked, AccountFormat.ltr(it))
        } ?: res.getString(R.string.v2_acct_err_email_locked_plain)

        AccountProblem.Expired -> res.getString(R.string.v2_acct_err_expired)

        AccountProblem.AlreadyEnabled -> res.getString(R.string.v2_acct_err_already_enabled)

        AccountProblem.NotEnabled -> res.getString(R.string.v2_acct_err_not_enabled)

        AccountProblem.RequiredForStaff -> res.getString(R.string.v2_acct_err_required_for_staff)

        AccountProblem.LastSignInMethod -> res.getString(R.string.v2_acct_err_last_method)

        AccountProblem.PasskeysUnavailable -> res.getString(R.string.v2_acct_err_passkeys_unavailable)

        AccountProblem.TooManyPasskeys -> res.getString(R.string.v2_acct_err_too_many_passkeys)

        AccountProblem.InvalidPasskey -> res.getString(R.string.v2_acct_err_invalid_passkey)

        is AccountProblem.InvalidImage -> image(res, p.reason)

        AccountProblem.TooLarge -> res.getString(R.string.v2_acct_err_image_too_big)

        is AccountProblem.ServerBusy -> res.getString(R.string.v2_acct_err_server_busy)

        AccountProblem.CurrentDevice -> res.getString(R.string.v2_acct_err_current_device)

        AccountProblem.NotFound -> res.getString(R.string.v2_acct_err_not_found)

        AccountProblem.ProofCodeNotNeeded -> res.getString(R.string.v2_acct_err_code_not_needed)

        AccountProblem.SignedOut -> res.getString(R.string.v2_acct_err_signed_out)

        AccountProblem.Offline -> res.getString(R.string.v2_acct_err_offline)

        AccountProblem.Server -> res.getString(R.string.v2_acct_err_server)

        is AccountProblem.Other -> res.getString(R.string.v2_acct_err_other)
    }

    /** Problems that belong to one field of a form ("new", "name", "email"), shown under it; null for the others. */
    fun forField(res: Resources, p: AccountProblem?, field: String): String? = when (p) {
        is AccountProblem.Fields -> fields(res, p.of(field))
        AccountProblem.EmailTaken -> if (field == "email") problem(res, p) else null
        else -> null
    }
}
