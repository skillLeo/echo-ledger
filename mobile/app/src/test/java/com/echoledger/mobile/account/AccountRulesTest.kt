package com.echoledger.mobile.account

import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.data.PasswordRulesDto
import com.echoledger.mobile.data.Proof
import java.util.Locale
import java.util.TimeZone
import kotlinx.serialization.json.buildJsonObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** The proof sheet's logic, the password rules, the email change's end and the account screens' formatting. */
class AccountRulesTest {
    @Test
    fun `with two-step on every proof asks for a code, with the password when there is one`() {
        assertEquals(ProofNeed(password = true, code = true, emailCode = false), ProofNeeds.of(hasPassword = true, twoFactor = true))
        assertEquals(ProofNeed(password = false, code = true, emailCode = false), ProofNeeds.of(hasPassword = false, twoFactor = true))
    }

    @Test
    fun `without two-step the password, and with neither an emailed code`() {
        assertEquals(ProofNeed(password = true, code = false, emailCode = false), ProofNeeds.of(hasPassword = true, twoFactor = false))
        assertEquals(ProofNeed(password = false, code = false, emailCode = true), ProofNeeds.of(hasPassword = false, twoFactor = false))
    }

    @Test
    fun `turning two-step off and new recovery codes take a code only`() {
        assertEquals(ProofNeed(password = false, code = true, emailCode = false), ProofNeeds.of(true, true, codeOnly = true))
    }

    @Test
    fun `the server's accepts decides what is asked`() {
        assertEquals(ProofNeed(false, true, false), ProofNeeds.of(true, true, listOf("otp")))
        assertEquals(ProofNeed(true, false, false), ProofNeeds.of(true, false, listOf("password", "otp", "email_code")))
        assertEquals(ProofNeed(false, false, true), ProofNeeds.of(false, false, listOf("email_code")))
    }

    @Test
    fun `a right password without the code adds the code, and proof_required asks what it names`() {
        val password = ProofNeed(password = true, code = false, emailCode = false)
        assertEquals(ProofNeed(true, true, false), ProofNeeds.after(password, AccountProblem.InvalidOtp(missing = true), true, false))
        assertEquals(password, ProofNeeds.after(password, AccountProblem.InvalidOtp(missing = false), true, false))
        assertEquals(ProofNeed(false, true, false), ProofNeeds.after(password, AccountProblem.ProofRequired(listOf("otp")), true, false))
        assertEquals(password, ProofNeeds.after(password, AccountProblem.WrongPassword, true, false))
    }

    @Test
    fun `each proof problem has its field`() {
        assertEquals("password", ProofNeeds.fieldOf(AccountProblem.WrongPassword))
        assertEquals("code", ProofNeeds.fieldOf(AccountProblem.InvalidOtp()))
        assertEquals("email_code", ProofNeeds.fieldOf(AccountProblem.InvalidCode))
        assertNull(ProofNeeds.fieldOf(AccountProblem.TooManyAttempts(60)))
    }

    @Test
    fun `the proof is ready only with what is asked and sends only that`() {
        val both = ProofNeed(password = true, code = true, emailCode = false)
        assertFalse(both.ready("secret", "", ""))
        assertFalse(both.ready("secret", "12345", ""))
        assertTrue(both.ready("secret", "123 456", ""))
        assertTrue("a recovery code", both.ready("secret", "ab12-cd34", ""))
        assertEquals(Proof(password = "secret", otp = "123456"), both.proof("secret", "123456", "999999"))
        val email = ProofNeed(password = false, code = false, emailCode = true)
        assertFalse(email.ready("", "", "12345"))
        assertTrue(email.ready("", "", "123456"))
        assertEquals(Proof(emailCode = "123456"), email.proof("typed", "1", "123456"))
    }

    @Test
    fun `a proof leaves blank parts out of the JSON`() {
        val json = buildJsonObject { Proof(password = "", otp = " 123 456 ", emailCode = null).into(this) }
        assertEquals("""{"otp":"123456"}""", json.toString())
        assertTrue(Proof().isEmpty)
    }

    @Test
    fun `the password rules are checked before sending`() {
        val rules = PasswordRulesDto(min = 12, letters = true, numbers = true)
        assertEquals(listOf("required"), PasswordCheck.problems("", rules).map { it.code })
        assertEquals(listOf("too_short", "needs_numbers"), PasswordCheck.problems("short", rules).map { it.code })
        assertEquals(12, PasswordCheck.problems("short", rules).first().min)
        assertEquals(listOf("needs_letters"), PasswordCheck.problems("123456789012", rules).map { it.code })
        assertTrue(PasswordCheck.problems("long enough 2026", rules).isEmpty())
        assertEquals(listOf("same_as_current"), PasswordCheck.problems("long enough 2026", rules, "long enough 2026").map { it.code })
        // A server without letters or numbers rules, and an older server (the defaults).
        assertTrue(PasswordCheck.problems("aaaaaaaa", PasswordRulesDto(8, letters = false, numbers = false)).isEmpty())
        assertEquals(listOf("too_short"), PasswordCheck.problems("abc1", null).map { it.code })
    }

    @Test
    fun `the email change is over once nothing waits`() {
        assertFalse(EmailChange.finished(null))
        assertFalse(EmailChange.finished(AccountDto(email = "a@x.pk", pendingEmail = "b@x.pk")))
        assertTrue(EmailChange.finished(AccountDto(email = "b@x.pk", pendingEmail = null)))
        assertEquals(10_000L, EmailChange.POLL_MS)
        assertTrue(EmailChange.looksLikeEmail(" ayesha@bilaltraders.pk "))
        assertFalse(EmailChange.looksLikeEmail("ayesha@"))
        assertTrue(EmailChange.sameAsNow("Ayesha@X.pk", "ayesha@x.pk"))
    }

    @Test
    fun `keys and codes are grouped for eyes and spelled for TalkBack`() {
        assertEquals("JBSW Y3DP EHPK 3PXP", AccountFormat.grouped("JBSWY3DPEHPK3PXP"))
        assertEquals("A B 1 2", AccountFormat.spoken("AB 12"))
        assertEquals("Heading\n\none\ntwo\n", AccountFormat.recoveryText("Heading", listOf("one", "two")))
        assertEquals("⁦a@x.pk⁩", AccountFormat.ltr("a@x.pk"))
        assertEquals("⁨عائشہ⁩", AccountFormat.isolate("عائشہ"))
    }

    @Test
    fun `waits are whole minutes, at least one`() {
        assertEquals(1, AccountFormat.minutes(5))
        assertEquals(15, AccountFormat.minutes(900))
        assertEquals(2, AccountFormat.minutes(61))
        assertEquals(1, AccountFormat.minutes(null))
    }

    @Test
    fun `the email lock's date reads as a day`() {
        val zone = TimeZone.getDefault()
        TimeZone.setDefault(TimeZone.getTimeZone("UTC"))
        try {
            assertEquals("28 Nov 2026", AccountFormat.day("2026-11-28T16:00:46+00:00", Locale.UK))
            assertEquals("not a date", AccountFormat.day("not a date", Locale.UK)?.let { "not a date" })
            assertNull(AccountFormat.day(null))
        } finally {
            TimeZone.setDefault(zone)
        }
    }

    /**
     * A typed name holding a closing mark (PDI, U+2069) and an override (RLO, U+202E) could end its isolate early and flip
     * the rest of the sentence. As the web's IsolatingTranslator::isolate: a PDI with no opener of its own inside the name
     * is dropped (the final PDI also ends the override), an isolate the name opens and closes is kept, and '' stays ''.
     */
    @Test
    fun `a name can't close its own isolate early`() {
        assertEquals("\u2068Ali\u202Eevil\u2069", AccountFormat.isolate("Ali\u2069\u202Eevil"))
        assertEquals("\u2068a\u2066b\u2069c\u2069", AccountFormat.isolate("a\u2066b\u2069c"))
        assertEquals("", AccountFormat.isolate(""))
        // The after-call card's caller number, the same way.
        assertEquals("\u2068+92 300\u202E1\u2069", com.echoledger.mobile.ui.v2.live.isolated("+92 300\u2069\u202E1"))
    }
}
