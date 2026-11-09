package com.echoledger.mobile.core

import com.echoledger.mobile.data.AuthStartDto

/**
 * The one sign-in flow (since v1.6, P1): the email first, then what fits it. An account with a password is asked for
 * it (or an emailed code); one without, and a new email, get a 6-digit code; a new email then gives a name and accepts
 * the Terms. The two-factor code comes after either. Pure; unit-tested.
 */
object SignInFlow {
    enum class Step { EMAIL, PASSWORD, CODE, PROFILE, OTP }

    /** What the screen does next besides showing [State.step]. */
    enum class Action { NONE, SEND_SIGN_IN_CODE, SEND_SIGN_UP_CODE }

    data class State(
        val step: Step = Step.EMAIL,
        val email: String = "",
        val newAccount: Boolean = false,
        val methods: List<String> = emptyList(),
        val signUpToken: String? = null,
        /** Which step asked for the two-factor code (it is sent again with it). */
        val otpAfter: Step? = null,
        /** When "Send again" works again (elapsed-realtime ms). */
        val resendAtMs: Long = 0L,
        val error: String? = null
    ) {
        val canUseCode: Boolean get() = newAccount || "code" in methods
        val hasPassword: Boolean get() = "password" in methods
    }

    const val SIGN_UP_CLOSED = "This server is invite only. Ask its owner for an invitation, then sign in with that email."

    fun validEmail(email: String): Boolean = Regex("""^[^@\s]+@[^@\s]+\.[^@\s]+$""").matches(email.trim())

    /** The answer to `POST /api/auth/start`. */
    fun afterStart(state: State, answer: AuthStartDto): Pair<State, Action> {
        val email = state.email.trim()
        return when {
            answer.account == "new" && !answer.signUp -> state.copy(error = SIGN_UP_CLOSED) to Action.NONE

            answer.account == "new" -> state.copy(
                email = email,
                newAccount = true,
                methods = answer.methods,
                step = Step.CODE,
                error = null
            ) to
                Action.SEND_SIGN_UP_CODE

            "password" in answer.methods -> state.copy(
                email = email,
                newAccount = false,
                methods = answer.methods,
                step = Step.PASSWORD,
                error = null
            ) to
                Action.NONE

            else -> state.copy(email = email, newAccount = false, methods = answer.methods, step = Step.CODE, error = null) to
                Action.SEND_SIGN_IN_CODE
        }
    }

    /** A server older than v1.6 has no `auth/start`: the password, as before. */
    fun olderServer(state: State): State =
        state.copy(email = state.email.trim(), methods = listOf("password"), step = Step.PASSWORD, error = null)

    /** "Email me a code" on the password step. */
    fun useCode(state: State): Pair<State, Action> = state.copy(step = Step.CODE, error = null) to Action.SEND_SIGN_IN_CODE

    /** Back: one step towards the email (the email keeps what was typed). */
    fun back(state: State): State = when (state.step) {
        Step.EMAIL -> state
        Step.PASSWORD, Step.CODE -> state.copy(step = Step.EMAIL, signUpToken = null, otpAfter = null, error = null)
        Step.PROFILE -> state.copy(step = Step.CODE, signUpToken = null, error = null)
        Step.OTP -> state.copy(step = state.otpAfter ?: Step.EMAIL, otpAfter = null, error = null)
    }

    /** Seconds until "Send again" works; 0 when it does. */
    fun resendIn(state: State, nowMs: Long): Int = ((state.resendAtMs - nowMs + 999) / 1000).toInt().coerceAtLeast(0)

    /** What a refused step says, in plain words (codes from docs/API.md). */
    fun message(code: String?, fallback: String?): String = when (code) {
        "invalid_code" -> fallback?.takeIf { it.isNotBlank() } ?: "That code is not right. Check the email and try again."
        "code_expired" -> "That code has expired. Tap Send again for a new one."
        "resend_too_soon" -> "A code was just sent. Wait a moment before asking for another."
        "too_many_attempts" -> "Too many tries. Wait a few minutes, then try again."
        "sign_up_limit" -> "Too many new accounts from this network today. Try again tomorrow."
        "sign_up_closed" -> SIGN_UP_CLOSED
        "email_not_allowed" -> "This email address can't be used here. Use another one."
        "invalid_otp" -> "That two-step code is not right."
        else -> SignIn.message(code, fallback)
    }
}
