package com.echoledger.mobile.core

import com.echoledger.mobile.core.SignInFlow.Action
import com.echoledger.mobile.core.SignInFlow.State
import com.echoledger.mobile.core.SignInFlow.Step
import com.echoledger.mobile.data.AuthStartDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** v1.6 P1: the email first, then what fits it. */
class SignInFlowTest {
    private val typed = State(email = " ana@example.com ")

    @Test
    fun `an account with a password goes to the password, one without gets a code`() {
        val (withPassword, none) = SignInFlow.afterStart(typed, AuthStartDto("existing", listOf("password", "code"), true))
        assertEquals(Step.PASSWORD, withPassword.step)
        assertEquals("ana@example.com", withPassword.email)
        assertEquals(Action.NONE, none)
        assertTrue(withPassword.canUseCode)

        val (codeOnly, send) = SignInFlow.afterStart(typed, AuthStartDto("existing", listOf("code", "google"), true))
        assertEquals(Step.CODE, codeOnly.step)
        assertEquals(Action.SEND_SIGN_IN_CODE, send)
    }

    @Test
    fun `a new email gets a sign-up code, or the invite-only message`() {
        val (open, send) = SignInFlow.afterStart(typed, AuthStartDto("new", listOf("code"), true))
        assertEquals(Step.CODE, open.step)
        assertTrue(open.newAccount)
        assertEquals(Action.SEND_SIGN_UP_CODE, send)

        val (closed, none) = SignInFlow.afterStart(typed, AuthStartDto("new", emptyList(), false))
        assertEquals(Step.EMAIL, closed.step)
        assertEquals(SignInFlow.SIGN_UP_CLOSED, closed.error)
        assertEquals(Action.NONE, none)
    }

    @Test
    fun `back goes one step towards the email and forgets the sign-up token`() {
        val profile = State(step = Step.PROFILE, email = "a@b.co", signUpToken = "t", newAccount = true)
        assertEquals(Step.CODE, SignInFlow.back(profile).step)
        assertNull(SignInFlow.back(profile).signUpToken)
        assertEquals(Step.EMAIL, SignInFlow.back(State(step = Step.PASSWORD)).step)
        assertEquals(Step.PASSWORD, SignInFlow.back(State(step = Step.OTP, otpAfter = Step.PASSWORD)).step)
        assertEquals(Step.EMAIL, SignInFlow.back(State()).step)
    }

    @Test
    fun `an older server, the resend countdown, emails and plain words`() {
        assertEquals(Step.PASSWORD, SignInFlow.olderServer(typed).step)
        assertEquals(60, SignInFlow.resendIn(State(resendAtMs = 61_000), 1_000))
        assertEquals(0, SignInFlow.resendIn(State(resendAtMs = 1_000), 5_000))
        assertTrue(SignInFlow.validEmail("ana@example.com"))
        assertFalse(SignInFlow.validEmail("ana@example"))
        assertFalse(SignInFlow.validEmail("ana example.com"))
        assertEquals("That code has expired. Tap Send again for a new one.", SignInFlow.message("code_expired", null))
        assertEquals("Wait 4 tries", SignInFlow.message("invalid_code", "Wait 4 tries"))
    }

    @Test
    fun `an install id is a uuid`() {
        assertTrue(InstallId.valid("2f1c3a1e-8a77-4b5e-9d2a-1f0c8e7b6a5d"))
        assertFalse(InstallId.valid("not-an-id"))
    }
}
