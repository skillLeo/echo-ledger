package com.echoledger.mobile.account

import com.echoledger.mobile.data.Proof
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.bodyText
import java.io.File
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import mockwebserver3.MockResponse
import mockwebserver3.RecordedRequest
import org.junit.After
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/**
 * The account and security calls of contract 2.0.0-l against a scripted server answering with the real samples
 * (docs/api-samples/account_*.json): what each one sends, and each error code as the screens see it.
 */
class AccountActionsTest {
    private lateinit var server: FakeServer
    private lateinit var actions: AccountActions

    private fun sample(name: String) = File("../../docs/api-samples/$name.json").readText()

    /** A field of a sample as the server wrote it, so a regenerated sample (BE-06) never breaks a test. */
    private fun sampled(name: String, field: String) = Json.parseToJsonElement(sample(name)).jsonObject[field]!!.jsonPrimitive

    @Before
    fun setUp() {
        server = FakeServer()
        val client = ApiClient(server.baseUrl, emptyList(), token = "7|eld_test")
        actions = AccountActions { client }
    }

    @After
    fun tearDown() = server.close()

    private fun only(method: String, path: String): RecordedRequest = server.requestsTo(method, path).single()

    private fun RecordedRequest.json(): JsonObject = Json.parseToJsonElement(bodyText()).jsonObject

    private fun problem(code: Int, body: String, retryAfter: String? = null): MockResponse {
        val b = MockResponse.Builder().code(code).addHeader("Content-Type", "application/json").body(body)
        retryAfter?.let { b.addHeader("Retry-After", it) }
        return b.build()
    }

    private fun <T> failed(r: AccountResult<T>): AccountProblem = (r as AccountResult.Failed).problem

    @Test
    fun `the account and its security read the real answers`() = runBlocking {
        server.on("GET", "/api/account", FakeServer.json(200, sample("account")))
        server.on("GET", "/api/account/security", FakeServer.json(200, sample("account_security")))
        val account = actions.account().valueOrNull!!
        assertTrue(account.hasPassword)
        assertTrue(account.passkeysAvailable)
        assertNull(account.pendingEmail)
        val security = actions.security().valueOrNull!!
        assertTrue(security.twoFactor.enabled)
        assertEquals(8, security.twoFactor.recoveryCodesLeft)
        assertEquals("Sample phone", security.passkeys.single().name)
        assertEquals(3, security.devices.size)
        assertTrue(security.devices.first().current)
        assertEquals("Personal", security.devices.first().workspace?.name)
        assertFalse(security.google.linked)
        assertEquals("passkey_added", security.events.first().event)
    }

    @Test
    fun `the name is patched and the account comes back`() = runBlocking {
        server.on("PATCH", "/api/account", FakeServer.json(200, sample("account_update")))
        val now = actions.rename("  Sample Owner ").valueOrNull!!
        assertEquals("Sample Owner", only("PATCH", "/api/account").json()["name"]!!.jsonPrimitive.content)
        assertEquals(sampled("account_update", "avatar_version").content.toLong(), now.avatarVersion)
    }

    @Test
    fun `a name with a bidi override is refused with its field code`() = runBlocking {
        server.on(
            "PATCH",
            "/api/account",
            problem(
                422,
                """{"message":"The name has characters that can't be used.","code":"validation_failed","errors":{"name":["x"]},"error_codes":{"name":[{"code":"invalid_characters"}]}}"""
            )
        )
        val p = failed(actions.rename("a‮b")) as AccountProblem.Fields
        assertEquals(listOf(FieldCode("invalid_characters")), p.of("name"))
    }

    @Test
    fun `the picture goes as multipart image and is removed with DELETE`() = runBlocking {
        server.on("POST", "/api/account/avatar", FakeServer.json(200, sample("account_avatar")))
        server.on("DELETE", "/api/account/avatar", FakeServer.empty(204))
        val jpeg = byteArrayOf(-1, -40, -1, -32, 1, 2, 3)
        val avatar = actions.uploadAvatar(jpeg).valueOrNull!!
        assertEquals(sampled("account_avatar", "avatar_version").content.toLong(), avatar.avatarVersion)
        val sent = only("POST", "/api/account/avatar")
        assertTrue(sent.headers["Content-Type"]!!.startsWith("multipart/form-data"))
        val body = sent.body!!.toByteArray()
        val text = String(body, Charsets.ISO_8859_1)
        assertTrue(text.contains("""name="image""""))
        assertTrue(text.contains("Content-Type: image/jpeg"))
        assertTrue(text.contains(String(jpeg, Charsets.ISO_8859_1)))
        assertTrue(actions.removeAvatar() is AccountResult.Done)
    }

    @Test
    fun `a picture the server refuses says why`() = runBlocking {
        server.on("POST", "/api/account/avatar", problem(422, """{"message":"x","code":"invalid_image","reason":"too_small"}"""))
        assertEquals(AccountProblem.InvalidImage("too_small"), failed(actions.uploadAvatar(byteArrayOf(1))))
        server.on("POST", "/api/account/avatar", problem(413, """{"message":"x","code":"too_large"}"""))
        assertEquals(AccountProblem.TooLarge, failed(actions.uploadAvatar(byteArrayOf(1))))
        server.on("POST", "/api/account/avatar", problem(503, """{"message":"x","code":"server_busy"}""", retryAfter = "5"))
        assertEquals(AccountProblem.ServerBusy(5), failed(actions.uploadAvatar(byteArrayOf(1))))
    }

    @Test
    fun `an avatar is fetched without the token, from this server only, and a stale address is nothing`() = runBlocking {
        server.on("GET", "/api/avatars/1/3", MockResponse.Builder().code(200).body("abc").build())
        server.on("GET", "/api/avatars/1/2", FakeServer.empty(404))
        assertArrayEquals("abc".toByteArray(), actions.avatarImage("${server.baseUrl}/api/avatars/1/3?signature=s"))
        assertNull(only("GET", "/api/avatars/1/3").headers["Authorization"])
        assertNull(actions.avatarImage("${server.baseUrl}/api/avatars/1/2?signature=s"))
        assertNull(actions.avatarImage("https://elsewhere.example/api/avatars/1/3"))
    }

    @Test
    fun `an email change sends the address with the proof, then the code, and can be cancelled`() = runBlocking {
        server.on("POST", "/api/account/email", FakeServer.json(202, sample("account_email")))
        server.on("POST", "/api/account/email/confirm", FakeServer.json(200, sample("account_email_confirm")))
        server.on("DELETE", "/api/account/email", FakeServer.empty(204))
        val pending = actions.startEmailChange("sample.new@example.test", Proof(password = "pw", otp = "123456")).valueOrNull!!
        assertEquals(sampled("account_email", "pending_email").content, pending.pendingEmail)
        val body = only("POST", "/api/account/email").json()
        assertEquals("sample.new@example.test", body["email"]!!.jsonPrimitive.content)
        assertEquals("pw", body["password"]!!.jsonPrimitive.content)
        assertEquals("123456", body["otp"]!!.jsonPrimitive.content)
        val now = actions.confirmEmailChange(" 654321 ").valueOrNull!!
        assertEquals("654321", only("POST", "/api/account/email/confirm").json()["code"]!!.jsonPrimitive.content)
        assertEquals(sampled("account_email_confirm", "email").content, now.email)
        assertTrue(EmailChange.finished(now))
        assertTrue(actions.cancelEmailChange() is AccountResult.Done)
    }

    @Test
    fun `each email change error has its own problem`() = runBlocking {
        fun answer(code: Int, body: String) = server.on("POST", "/api/account/email", problem(code, body))
        answer(422, """{"message":"x","code":"email_taken"}""")
        assertEquals(AccountProblem.EmailTaken, failed(actions.startEmailChange("a@x.pk", Proof(password = "p"))))
        answer(422, """{"message":"x","code":"validation_failed","error_codes":{"email":[{"code":"invalid_email"}]}}""")
        assertEquals(
            listOf(FieldCode("invalid_email")),
            (failed(actions.startEmailChange("a@", Proof(password = "p"))) as AccountProblem.Fields).of("email")
        )
        answer(422, """{"message":"x","code":"validation_failed","error_codes":{"email":[{"code":"same_as_now"}]}}""")
        assertEquals(
            "same_as_now",
            (failed(actions.startEmailChange("a@x.pk", Proof(password = "p"))) as AccountProblem.Fields).of("email").single().code
        )
        answer(409, """{"message":"x","code":"email_change_locked","until":"2026-11-28T16:00:46+00:00"}""")
        assertEquals(
            AccountProblem.EmailLocked("2026-11-28T16:00:46+00:00"),
            failed(actions.startEmailChange("a@x.pk", Proof(password = "p")))
        )
        server.on("POST", "/api/account/email/confirm", problem(422, """{"message":"x","code":"invalid_code"}"""))
        assertEquals(AccountProblem.InvalidCode, failed(actions.confirmEmailChange("000000")))
        server.on("POST", "/api/account/email/confirm", problem(410, """{"message":"The code has expired."}"""))
        assertEquals(AccountProblem.Expired, failed(actions.confirmEmailChange("000000")))
    }

    @Test
    fun `the proof errors are told apart`() = runBlocking {
        fun answer(code: Int, body: String, retry: String? = null) = server.on("POST", "/api/account/email", problem(code, body, retry))
        answer(422, """{"message":"x","code":"wrong_password"}""")
        assertEquals(AccountProblem.WrongPassword, failed(actions.startEmailChange("a@x.pk", Proof(password = "p"))))
        answer(422, """{"message":"x","code":"invalid_otp","accepts":["otp"]}""")
        assertEquals(AccountProblem.InvalidOtp(missing = true), failed(actions.startEmailChange("a@x.pk", Proof(password = "p"))))
        answer(422, """{"message":"x","code":"invalid_otp"}""")
        assertEquals(AccountProblem.InvalidOtp(missing = false), failed(actions.startEmailChange("a@x.pk", Proof(otp = "111111"))))
        answer(422, """{"message":"x","code":"invalid_code"}""")
        assertEquals(AccountProblem.InvalidCode, failed(actions.startEmailChange("a@x.pk", Proof(emailCode = "111111"))))
        answer(422, """{"message":"x","code":"proof_required","accepts":["password","otp","email_code"]}""")
        assertEquals(
            AccountProblem.ProofRequired(listOf("password", "otp", "email_code")),
            failed(actions.startEmailChange("a@x.pk", Proof()))
        )
        answer(429, """{"message":"x","code":"too_many_attempts"}""", retry = "840")
        assertEquals(AccountProblem.TooManyAttempts(840), failed(actions.startEmailChange("a@x.pk", Proof(password = "p"))))
        answer(429, """{"message":"x","code":"rate_limited"}""", retry = "1800")
        assertEquals(AccountProblem.RateLimited(1800), failed(actions.startEmailChange("a@x.pk", Proof(password = "p"))))
    }

    @Test
    fun `an emailed proof code is asked for, and refused when not needed`() = runBlocking {
        server.on("POST", "/api/account/proof-code", FakeServer.empty(202))
        assertTrue(actions.sendProofCode() is AccountResult.Done)
        server.on("POST", "/api/account/proof-code", problem(409, """{"message":"x","code":"proof_code_not_needed"}"""))
        assertEquals(AccountProblem.ProofCodeNotNeeded, failed(actions.sendProofCode()))
    }

    @Test
    fun `the password is changed with the current one and the code, signing out others by default`() = runBlocking {
        server.on("PUT", "/api/account/password", FakeServer.empty(204))
        assertTrue(
            actions.changePassword("old one 2026", "new one 2026!", Proof(otp = "123456"), signOutOthers = true) is AccountResult.Done
        )
        val body = only("PUT", "/api/account/password").json()
        assertEquals("old one 2026", body["current"]!!.jsonPrimitive.content)
        assertEquals("new one 2026!", body["new"]!!.jsonPrimitive.content)
        assertEquals("true", body["sign_out_others"]!!.jsonPrimitive.content)
        assertEquals("123456", body["otp"]!!.jsonPrimitive.content)
    }

    @Test
    fun `a first password goes with the emailed code and no current one`() = runBlocking {
        server.on("PUT", "/api/account/password", FakeServer.empty(204))
        actions.changePassword(null, "new one 2026!", Proof(password = "ignored", emailCode = "123456"), signOutOthers = false)
        val body = only("PUT", "/api/account/password").json()
        assertEquals(JsonNull, body["current"])
        assertEquals("123456", body["email_code"]!!.jsonPrimitive.content)
        assertNull("a proof's password is never sent as the proof here", body["password"])
        assertEquals("false", body["sign_out_others"]!!.jsonPrimitive.content)
    }

    @Test
    fun `each password field code comes through`() = runBlocking {
        server.on(
            "PUT",
            "/api/account/password",
            problem(
                422,
                """{"message":"x","code":"validation_failed","errors":{"new":["a","b"]},"error_codes":{"new":[{"code":"too_short","min":12},{"code":"needs_numbers"},{"code":"needs_letters"},{"code":"same_as_current"}],"current":["required"]}}"""
            )
        )
        val p = failed(actions.changePassword("x", "y", null, true)) as AccountProblem.Fields
        assertEquals(listOf("too_short", "needs_numbers", "needs_letters", "same_as_current"), p.of("new").map { it.code })
        assertEquals(12, p.of("new").first().min)
        assertEquals(listOf(FieldCode("required")), p.of("current"))
        server.on("PUT", "/api/account/password", problem(422, """{"message":"x","code":"wrong_password"}"""))
        assertEquals(AccountProblem.WrongPassword, failed(actions.changePassword("x", "y", null, true)))
    }

    @Test
    fun `two-step starts with the proof, is confirmed with a code, and answers the recovery codes`() = runBlocking {
        server.on("POST", "/api/account/two-factor", FakeServer.json(200, sample("account_two_factor")))
        server.on("POST", "/api/account/two-factor/confirm", FakeServer.json(200, sample("account_two_factor_confirm")))
        val setup = actions.startTwoStep(Proof(password = "pw")).valueOrNull!!
        assertTrue(setup.otpauthUri.startsWith("otpauth://totp/"))
        assertEquals(6, setup.digits)
        assertEquals("pw", only("POST", "/api/account/two-factor").json()["password"]!!.jsonPrimitive.content)
        val codes = actions.confirmTwoStep(setup.ceremony, "123 456").valueOrNull!!
        assertEquals(8, codes.recoveryCodes.size)
        val confirm = only("POST", "/api/account/two-factor/confirm").json()
        assertEquals(setup.ceremony, confirm["ceremony"]!!.jsonPrimitive.content)
        assertEquals("123456", confirm["code"]!!.jsonPrimitive.content)
    }

    @Test
    fun `two-step's own errors`() = runBlocking {
        server.on("POST", "/api/account/two-factor", problem(409, """{"message":"x","code":"already_enabled"}"""))
        assertEquals(AccountProblem.AlreadyEnabled, failed(actions.startTwoStep(Proof(password = "pw"))))
        server.on("POST", "/api/account/two-factor/confirm", problem(422, """{"message":"x","code":"invalid_otp"}"""))
        assertEquals(AccountProblem.InvalidOtp(), failed(actions.confirmTwoStep("c", "000000")))
        server.on("POST", "/api/account/two-factor/recovery-codes", problem(409, """{"message":"x","code":"not_enabled"}"""))
        assertEquals(AccountProblem.NotEnabled, failed(actions.newRecoveryCodes("123456")))
        server.on("DELETE", "/api/account/two-factor", problem(409, """{"message":"x","code":"required_for_staff"}"""))
        assertEquals(AccountProblem.RequiredForStaff, failed(actions.turnOffTwoStep("123456")))
    }

    @Test
    fun `new recovery codes and turning off take the code as otp`() = runBlocking {
        server.on("POST", "/api/account/two-factor/recovery-codes", FakeServer.json(200, sample("account_recovery_codes")))
        server.on("DELETE", "/api/account/two-factor", FakeServer.empty(204))
        assertEquals(8, actions.newRecoveryCodes("123456").valueOrNull!!.recoveryCodes.size)
        assertEquals("123456", only("POST", "/api/account/two-factor/recovery-codes").json()["otp"]!!.jsonPrimitive.content)
        assertTrue(actions.turnOffTwoStep("abcd-efgh").valueOrNull != null)
        assertEquals("abcd-efgh", only("DELETE", "/api/account/two-factor").json()["otp"]!!.jsonPrimitive.content)
    }

    @Test
    fun `a passkey is added with its ceremony, renamed and removed with the proof`() = runBlocking {
        server.on("POST", "/api/account/passkeys/options", FakeServer.json(200, sample("account_passkey_options")))
        server.on("POST", "/api/account/passkeys", FakeServer.json(201, sample("account_passkey")))
        server.on("PATCH", "/api/account/passkeys/p1", FakeServer.json(200, sample("account_passkey")))
        server.on("DELETE", "/api/account/passkeys/p1", FakeServer.empty(204))
        val options = actions.passkeyOptions(Proof(password = "pw")).valueOrNull!!
        assertEquals("echoledger.test", options.options["rp"]!!.jsonObject["id"]!!.jsonPrimitive.content)
        val credential = Json.parseToJsonElement("""{"id":"abc","type":"public-key","response":{}}""").jsonObject
        assertEquals("Sample phone", actions.savePasskey(options.ceremony, "Pixel 9", credential).valueOrNull!!.name)
        val saved = only("POST", "/api/account/passkeys").json()
        assertEquals(options.ceremony, saved["ceremony"]!!.jsonPrimitive.content)
        assertEquals("Pixel 9", saved["name"]!!.jsonPrimitive.content)
        assertEquals(credential, saved["credential"])
        assertTrue(actions.renamePasskey("p1", "Work phone") is AccountResult.Done)
        assertTrue(actions.removePasskey("p1", Proof(otp = "123456")) is AccountResult.Done)
        assertEquals("123456", only("DELETE", "/api/account/passkeys/p1").json()["otp"]!!.jsonPrimitive.content)
    }

    @Test
    fun `passkey and Google errors`() = runBlocking {
        server.on("POST", "/api/account/passkeys/options", problem(409, """{"message":"x","code":"passkeys_unavailable"}"""))
        assertEquals(AccountProblem.PasskeysUnavailable, failed(actions.passkeyOptions(Proof(password = "p"))))
        server.on("POST", "/api/account/passkeys/options", problem(409, """{"message":"x","code":"too_many_passkeys"}"""))
        assertEquals(AccountProblem.TooManyPasskeys, failed(actions.passkeyOptions(Proof(password = "p"))))
        server.on("POST", "/api/account/passkeys", problem(422, """{"message":"x","code":"invalid_passkey"}"""))
        assertEquals(AccountProblem.InvalidPasskey, failed(actions.savePasskey("c", "n", JsonObject(emptyMap()))))
        server.on("DELETE", "/api/account/passkeys/p1", problem(409, """{"message":"x","code":"last_sign_in_method"}"""))
        assertEquals(AccountProblem.LastSignInMethod, failed(actions.removePasskey("p1", Proof(password = "p"))))
        server.on("DELETE", "/api/account/google", problem(409, """{"message":"x","code":"last_sign_in_method"}"""))
        assertEquals(AccountProblem.LastSignInMethod, failed(actions.unlinkGoogle(Proof(emailCode = "123456"))))
        assertEquals("123456", only("DELETE", "/api/account/google").json()["email_code"]!!.jsonPrimitive.content)
    }

    @Test
    fun `devices of every workspace and browsers are renamed and signed out`() = runBlocking {
        server.on("PATCH", "/api/account/devices/d2", FakeServer.json(200, "{}"))
        server.on("DELETE", "/api/account/devices/d2", FakeServer.empty(204))
        server.on("DELETE", "/api/account/browsers/b1", FakeServer.empty(204))
        server.on("DELETE", "/api/account/devices/d1", problem(409, """{"message":"x","code":"current_device"}"""))
        server.on("DELETE", "/api/account/browsers/zz", problem(404, """{"message":"x","code":"browser_not_found"}"""))
        assertTrue(actions.renameDevice("d2", " Work laptop ") is AccountResult.Done)
        assertEquals("Work laptop", only("PATCH", "/api/account/devices/d2").json()["name"]!!.jsonPrimitive.content)
        assertTrue(actions.signOutDevice("d2") is AccountResult.Done)
        assertTrue(actions.signOutBrowser("b1") is AccountResult.Done)
        assertEquals(AccountProblem.CurrentDevice, failed(actions.signOutDevice("d1")))
        assertEquals(AccountProblem.NotFound, failed(actions.signOutBrowser("zz")))
    }

    @Test
    fun `an avatar address that redirects is not followed, even on this server`() = runBlocking {
        server.on(
            "GET",
            "/api/avatars/1/4",
            MockResponse.Builder().code(302).addHeader("Location", "${server.baseUrl}/api/avatars/1/3").build()
        )
        server.on("GET", "/api/avatars/1/3", MockResponse.Builder().code(200).body("abc").build())
        assertNull(actions.avatarImage("${server.baseUrl}/api/avatars/1/4?signature=s"))
        assertTrue(server.requestsTo("GET", "/api/avatars/1/3").isEmpty())
    }

    @Test
    fun `a device, browser or passkey id that is not one never reaches a request path`() = runBlocking {
        server.onAny(FakeServer.empty(204))
        val bad = listOf("../two-factor", "d1/../../account", "d1?x=1", "d1#x", "%2e%2e", "", "a".repeat(129), "d 1")
        for (id in bad) {
            assertTrue(id, actions.signOutDevice(id) is AccountResult.Failed)
            assertTrue(id, actions.renameDevice(id, "x") is AccountResult.Failed)
            assertTrue(id, actions.signOutBrowser(id) is AccountResult.Failed)
            assertTrue(id, actions.renamePasskey(id, "x") is AccountResult.Failed)
            assertTrue(id, actions.removePasskey(id, Proof(otp = "123456")) is AccountResult.Failed)
        }
        assertTrue(server.requests.isEmpty())
        assertTrue(actions.signOutDevice("01JA_device-2") is AccountResult.Done)
        assertEquals(1, server.requestsTo("DELETE", "/api/account/devices/01JA_device-2").size)
    }

    @Test
    fun `a signed-out phone and an unreachable server are their own problems`() = runBlocking {
        server.on("GET", "/api/account", problem(401, """{"message":"Unauthenticated."}"""))
        assertEquals(AccountProblem.SignedOut, failed(actions.account()))
        assertEquals(AccountProblem.SignedOut, failed(AccountActions { null }.account()))
        val nowhere = ApiClient("http://localhost:1", emptyList(), token = "t")
        assertEquals(AccountProblem.Offline, failed(AccountActions { nowhere }.account()))
    }

    @Test
    fun `the password rules come from the sign-in options`() = runBlocking {
        server.on("GET", "/api/auth/options", FakeServer.json(200, sample("auth_options")))
        val rules = actions.passwordRules()!!
        assertEquals(12, rules.min)
        assertTrue(rules.letters && rules.numbers)
    }
}
