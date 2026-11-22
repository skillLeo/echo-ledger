package com.echoledger.mobile.ui.v2

import android.app.Application
import com.echoledger.mobile.account.AccountProblem
import com.echoledger.mobile.account.FieldCode
import com.echoledger.mobile.ui.v2.account.AccountWords
import com.echoledger.mobile.ui.v2.account.passwordFieldOf
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import java.util.TimeZone
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** Every account problem and field code in plain English and Urdu words: never the server's English, never a code. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], application = Application::class)
class AccountWordsTest {
    private val en = V2Testing.resources(EchoLanguage.English)
    private val ur = V2Testing.resources(EchoLanguage.Urdu)
    private lateinit var zone: TimeZone

    @Before
    fun utc() {
        zone = TimeZone.getDefault()
        TimeZone.setDefault(TimeZone.getTimeZone("UTC"))
    }

    @After
    fun back() = TimeZone.setDefault(zone)

    private val every = listOf(
        AccountProblem.WrongPassword,
        AccountProblem.InvalidOtp(),
        AccountProblem.InvalidOtp(missing = true),
        AccountProblem.InvalidCode,
        AccountProblem.TooManyAttempts(300),
        AccountProblem.RateLimited(3600),
        AccountProblem.ProofRequired(listOf("otp")),
        AccountProblem.Fields(mapOf("new" to listOf(FieldCode("too_short", min = 12)))),
        AccountProblem.EmailTaken,
        AccountProblem.EmailLocked("2026-11-28T16:00:46+00:00"),
        AccountProblem.EmailLocked(null),
        AccountProblem.Expired,
        AccountProblem.AlreadyEnabled,
        AccountProblem.NotEnabled,
        AccountProblem.RequiredForStaff,
        AccountProblem.LastSignInMethod,
        AccountProblem.PasskeysUnavailable,
        AccountProblem.TooManyPasskeys,
        AccountProblem.InvalidPasskey,
        AccountProblem.InvalidImage("ratio"),
        AccountProblem.TooLarge,
        AccountProblem.ServerBusy(5),
        AccountProblem.CurrentDevice,
        AccountProblem.NotFound,
        AccountProblem.ProofCodeNotNeeded,
        AccountProblem.SignedOut,
        AccountProblem.Offline,
        AccountProblem.Server,
        AccountProblem.Other("Some English sentence from the server")
    )

    @Test
    fun everyProblemHasItsOwnWordsInBothLanguages() {
        val english = every.map { AccountWords.problem(en, it) }
        val urdu = every.map { AccountWords.problem(ur, it) }
        // Different problems say different things (the two locks and InvalidOtp's two kinds included).
        assertEquals(english.size, english.toSet().size)
        for ((e, u) in english.zip(urdu)) {
            assertTrue(e, e.isNotBlank() && u.isNotBlank())
            assertFalse("not translated: $e", e == u)
            assertFalse("a code leaked: $e", Regex("""\b[a-z]+_[a-z_]+\b""").containsMatchIn(e))
        }
        assertFalse(english.any { "Some English sentence" in it })
    }

    @Test
    fun theProofErrorsReadPlainly() {
        assertEquals("That password isn't right.", AccountWords.problem(en, AccountProblem.WrongPassword))
        assertEquals("That code isn't right. Check the app and try again.", AccountWords.problem(en, AccountProblem.InvalidOtp()))
        assertEquals(
            "Two-step sign-in is on: enter the code from your authenticator app too.",
            AccountWords.problem(en, AccountProblem.InvalidOtp(missing = true))
        )
        assertEquals("That code isn't right. Check the email and try again.", AccountWords.problem(en, AccountProblem.InvalidCode))
        assertEquals("Too many tries. Try again in 5 minutes.", AccountWords.problem(en, AccountProblem.TooManyAttempts(300)))
        assertEquals("Too many tries. Try again in 1 minute.", AccountWords.problem(en, AccountProblem.TooManyAttempts(20)))
        assertEquals("یہ پاس ورڈ درست نہیں۔", AccountWords.problem(ur, AccountProblem.WrongPassword))
    }

    @Test
    fun theEmailLockSaysTheDayInTheScreensLanguage() {
        val english = AccountWords.problem(en, AccountProblem.EmailLocked("2026-11-28T16:00:46+00:00"))
        assertEquals("Your email changed recently. You can change it again after ⁦28 Nov 2026⁩.", english)
        val urdu = AccountWords.problem(ur, AccountProblem.EmailLocked("2026-11-28T16:00:46+00:00"))
        assertTrue(urdu, urdu.contains("2026") && urdu.contains("⁦") && urdu.contains("بعد"))
    }

    @Test
    fun eachFieldCodeHasWords() {
        val codes = listOf(
            FieldCode("required"),
            FieldCode("too_short", min = 12),
            FieldCode("too_long", max = 120),
            FieldCode("needs_letters"),
            FieldCode("needs_numbers"),
            FieldCode("same_as_current"),
            FieldCode("invalid_characters"),
            FieldCode("invalid_email"),
            FieldCode("same_as_now"),
            FieldCode("email_taken")
        )
        val words = codes.map { AccountWords.field(en, it) }
        assertEquals(words.size, words.toSet().size)
        assertEquals("Use at least 12 characters.", AccountWords.field(en, FieldCode("too_short", min = 12)))
        assertEquals("Use at most 120 characters.", AccountWords.field(en, FieldCode("too_long", max = 120)))
        assertEquals("کم از کم 12 حروف لکھیں۔", AccountWords.field(ur, FieldCode("too_short", min = 12)))
        // A field's several codes are said together.
        assertEquals(
            "Use at least 12 characters. Add at least one number.",
            AccountWords.fields(en, listOf(FieldCode("too_short", min = 12), FieldCode("needs_numbers")))
        )
    }

    @Test
    fun eachPictureReasonHasWords() {
        val reasons = listOf("format", "broken", "too_small", "ratio", "too_many_pixels").map { AccountWords.image(en, it) }
        assertEquals(5, reasons.toSet().size)
    }

    @Test
    fun aProblemOfAFieldIsShownUnderIt() {
        val fields = AccountProblem.Fields(mapOf("new" to listOf(FieldCode("needs_letters")), "email" to listOf(FieldCode("same_as_now"))))
        assertEquals("Add at least one letter.", AccountWords.forField(en, fields, "new"))
        assertEquals("This is already your email.", AccountWords.forField(en, fields, "email"))
        assertEquals("Another account already uses this email.", AccountWords.forField(en, AccountProblem.EmailTaken, "email"))
        assertNull(AccountWords.forField(en, AccountProblem.WrongPassword, "new"))
        assertEquals("current", passwordFieldOf(AccountProblem.WrongPassword))
        assertEquals("otp", passwordFieldOf(AccountProblem.InvalidOtp()))
        assertEquals("new", passwordFieldOf(fields))
        assertNull(passwordFieldOf(AccountProblem.RateLimited(60)))
    }
}
