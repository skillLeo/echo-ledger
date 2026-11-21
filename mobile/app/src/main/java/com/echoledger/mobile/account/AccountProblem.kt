package com.echoledger.mobile.account

import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.net.NotPairedException
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull

/** One code of a field's `error_codes` (`{"code": "too_short", "min": 12}`); the apps write their own words for it. */
data class FieldCode(val code: String, val min: Int? = null, val max: Int? = null, val reason: String? = null)

/**
 * What went wrong with an account or security change, from the server's answer (docs/API.md "Account and security from
 * the apps"): each one has its own plain words on the phone (AccountWords), in English and Urdu.
 */
sealed interface AccountProblem {
    data object WrongPassword : AccountProblem

    /** A wrong code, or ([missing]) none sent while two-step is on: the password alone is never enough then. */
    data class InvalidOtp(val missing: Boolean = false) : AccountProblem

    /** A wrong email code (proof, or the new address's code). */
    data object InvalidCode : AccountProblem

    /** Five wrong proofs in 15 minutes: wait [seconds] before trying again. */
    data class TooManyAttempts(val seconds: Long?) : AccountProblem

    /** Too many changes of this kind in an hour. */
    data class RateLimited(val seconds: Long?) : AccountProblem

    /** No proof was sent: [accepts] says what this account can give. */
    data class ProofRequired(val accepts: List<String>) : AccountProblem

    /** `422 validation_failed`: the codes of each field. */
    data class Fields(val fields: Map<String, List<FieldCode>>) : AccountProblem {
        fun of(field: String): List<FieldCode> = fields[field].orEmpty()
    }

    data object EmailTaken : AccountProblem

    /** `409 email_change_locked`: the address can't change again before [until] (an ISO date-time). */
    data class EmailLocked(val until: String?) : AccountProblem

    /** `410`: the code or ceremony is too old. */
    data object Expired : AccountProblem

    data object AlreadyEnabled : AccountProblem
    data object NotEnabled : AccountProblem
    data object RequiredForStaff : AccountProblem
    data object LastSignInMethod : AccountProblem
    data object PasskeysUnavailable : AccountProblem
    data object TooManyPasskeys : AccountProblem
    data object InvalidPasskey : AccountProblem

    /** `422 invalid_image`: [reason] is `format`, `broken`, `too_small`, `ratio` or `too_many_pixels`. */
    data class InvalidImage(val reason: String?) : AccountProblem
    data object TooLarge : AccountProblem
    data class ServerBusy(val seconds: Long?) : AccountProblem
    data object CurrentDevice : AccountProblem
    data object NotFound : AccountProblem
    data object ProofCodeNotNeeded : AccountProblem

    /** The token is gone (401): the phone goes back to sign-in. */
    data object SignedOut : AccountProblem
    data object Offline : AccountProblem
    data object Server : AccountProblem

    /** Anything else: the server's own words when it sent some. */
    data class Other(val message: String?) : AccountProblem

    companion object {
        fun of(e: Throwable): AccountProblem = when (e) {
            is NotPairedException -> SignedOut
            is ApiException -> fromApi(e)
            is java.io.IOException -> Offline
            else -> Other(null)
        }

        private fun fromApi(e: ApiException): AccountProblem {
            val body = e.body
            val fields = fieldCodes(body)
            return when (e.code) {
                "wrong_password" -> WrongPassword

                "invalid_otp" -> InvalidOtp(missing = accepts(body) == listOf("otp"))

                "invalid_code" -> InvalidCode

                "too_many_attempts" -> TooManyAttempts(e.retryAfterSeconds)

                "rate_limited" -> RateLimited(e.retryAfterSeconds)

                "proof_required" -> ProofRequired(accepts(body))

                "email_taken" -> EmailTaken

                "email_change_locked" -> EmailLocked(text(body, "until"))

                "expired" -> Expired

                "already_enabled" -> AlreadyEnabled

                "not_enabled" -> NotEnabled

                "required_for_staff" -> RequiredForStaff

                "last_sign_in_method" -> LastSignInMethod

                "passkeys_unavailable" -> PasskeysUnavailable

                "too_many_passkeys" -> TooManyPasskeys

                "invalid_passkey" -> InvalidPasskey

                "invalid_image" -> InvalidImage(text(body, "reason") ?: fields["image"]?.firstOrNull()?.reason)

                "too_large" -> TooLarge

                "server_busy" -> ServerBusy(e.retryAfterSeconds)

                "current_device" -> CurrentDevice

                "proof_code_not_needed" -> ProofCodeNotNeeded

                "device_not_found", "browser_not_found", "passkey_not_found" -> NotFound

                else -> when {
                    // An address already in use can come as a field code too.
                    fields.values.flatten().any { it.code == "email_taken" } -> EmailTaken

                    fields.values.flatten().any { it.code == "invalid_image" } -> InvalidImage(
                        fields.values.flatten().first {
                            it.code ==
                                "invalid_image"
                        }.reason
                    )

                    fields.isNotEmpty() -> Fields(fields)

                    e.status == 410 -> Expired

                    e.status == 413 -> TooLarge

                    e.status == 429 -> RateLimited(e.retryAfterSeconds)

                    e.status == 404 -> NotFound

                    e.status >= 500 -> Server

                    else -> Other(e.message?.takeUnless { it.startsWith("Request failed (") })
                }
            }
        }

        private fun text(body: JsonObject?, key: String): String? = (body?.get(key) as? JsonPrimitive)?.contentOrNull

        fun accepts(body: JsonObject?): List<String> =
            (body?.get("accepts") as? JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.contentOrNull }.orEmpty()

        /** `error_codes` (`{"new": [{"code": "too_short", "min": 12}]}`); a code may also come as plain text. */
        fun fieldCodes(body: JsonObject?): Map<String, List<FieldCode>> {
            val codes = body?.get("error_codes") as? JsonObject ?: return emptyMap()
            return codes.mapValues { (_, list) ->
                (list as? JsonArray).orEmpty().mapNotNull { item ->
                    when (item) {
                        is JsonObject -> text(item, "code")?.let { code ->
                            FieldCode(
                                code,
                                min = (item["min"] as? JsonPrimitive)?.intOrNull,
                                max = (item["max"] as? JsonPrimitive)?.intOrNull,
                                reason = text(item, "reason")
                            )
                        }

                        is JsonPrimitive -> item.contentOrNull?.let { FieldCode(it) }

                        else -> null
                    }
                }
            }.filterValues { it.isNotEmpty() }
        }
    }
}

/** An account call's outcome: its answer, or what went wrong. */
sealed interface AccountResult<out T> {
    data class Done<T>(val value: T) : AccountResult<T>
    data class Failed(val problem: AccountProblem) : AccountResult<Nothing>

    val valueOrNull: T? get() = (this as? Done<T>)?.value
    val problemOrNull: AccountProblem? get() = (this as? Failed)?.problem
}
