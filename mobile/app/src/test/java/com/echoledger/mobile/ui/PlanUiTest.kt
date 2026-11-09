package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.FeatureDto
import com.echoledger.mobile.data.PlanOfferDto
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** The upgrade flow (since v1.7; v1.6 P1.4 before): plans above yours, promo codes, the request; the Free plan's own key. */
class PlanUiTest : ScreenTest() {
    private val plan = PlanState(
        name = "Free",
        key = "free",
        features = mapOf(
            "views" to FeatureDto(false, "Roman Urdu and English views", "Read any call in Roman Urdu or English.", "paid", "pro"),
            "platform_transcription" to FeatureDto(false, "Transcription on EchoLedger", cost = "paid", lowestPlan = "pro"),
            "live_translate" to FeatureDto(false, "Live Translate", cost = "paid", lowestPlan = "business")
        ),
        offers = listOf(
            PlanOfferDto("free", "Free"),
            PlanOfferDto(
                "pro",
                "Pro",
                features = listOf("views"),
                limits = mapOf("monthly_minutes" to 300),
                pricePkr = 2999,
                priceText = "Rs 2,999 a month"
            ),
            PlanOfferDto(
                "business",
                "Ultra",
                features = listOf("views", "live_translate"),
                limits = mapOf("monthly_minutes" to 900),
                pricePkr = 12499,
                priceText = "Rs 12,499 a month"
            )
        )
    )

    private val requested =
        """{"id":"01JF","plan":"pro","status":"pending","promo_code":"SAVE50","percent":50,""" +
            """"amount_due":"Rs 1,500","amount_due_pkr":1500,""" +
            """"reference_code":"EL-7K2Q9M","payment_instructions":"Bank transfer…","created_at":"2026-11-09T10:00:00Z",""" +
            """"payment":{"stage":"awaiting_payment","accounts":[{"bank":"Example Bank",""" +
            """"title":"EXAMPLE PRIVATE LIMITED","iban":"PK00XXXX0000000123456789"}]}}"""

    private fun waitFor(condition: () -> Boolean) = pumpUntil {
        compose.waitForIdle()
        condition()
    }

    private fun openFlow(startPlan: String? = "pro") {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/upgrade-requests/latest", FakeServer.json(404, """{"code":"not_found"}"""))
        show { UpgradeFlow(vm, startPlan, "Roman Urdu and English views", null, given = plan, inDialog = false) {} }
    }

    private fun applyPromo(code: String) {
        compose.onNodeWithText("Promo code").performClick()
        compose.onNodeWithText("Promo code").performTextInput(code)
        compose.onNodeWithText("Apply").performClick()
    }

    @Test
    fun `the flow offers only the plans above Free, with the feature that opened it (v1_7)`() {
        openFlow()
        compose.onNodeWithText("Roman Urdu and English views is in Pro. You're on Free.").assertIsDisplayed()
        compose.onNodeWithText("Rs 2,999 a month").assertExists()
        compose.onNodeWithText("Rs 12,499 a month").assertExists()
        assertTrue(compose.onAllNodesWithText("PRO ✦").fetchSemanticsNodes().isEmpty())
        compose.onNodeWithText("Request Pro").assertIsDisplayed()
    }

    @Test
    fun `a promo code shows the new price, and Request sends it (v1_6_2, v1_7)`() {
        openFlow()
        server.on(
            "POST",
            "/api/promo-codes/check",
            FakeServer.json(
                200,
                """{"code":"SAVE50","plan":"pro","plan_name":"Pro","percent":50,"price":"Rs 2,999 / month","final_price":"Rs 1,500 / month","free":false,"months":1}"""
            )
        )
        server.on("POST", "/api/upgrade-requests", FakeServer.json(201, requested))
        applyPromo("save50")
        waitFor { compose.onAllNodesWithText("SAVE50: 50% off Pro for 1 month").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Rs 1,500").assertExists()
        compose.onNodeWithText("Request Pro").performClick()
        waitFor { compose.onAllNodesWithText("EXAMPLE PRIVATE LIMITED").fetchSemanticsNodes().isNotEmpty() }
        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/upgrade-requests").last().bodyText()).jsonObject
        assertEquals("pro", body["plan"]!!.jsonPrimitive.content)
        assertEquals("SAVE50", body["promo_code"]!!.jsonPrimitive.content)
        compose.onNodeWithText("EL-7K2Q9M").assertExists()
    }

    @Test
    fun `a code for another plan moves the choice to that plan (v1_7)`() {
        openFlow()
        server.on("POST", "/api/promo-codes/check") { req ->
            if (req.bodyText().contains("\"plan\":\"business\"")) {
                FakeServer.json(
                    200,
                    """{"code":"TEAM20","plan":"business","plan_name":"Ultra","percent":20,"final_price":"Rs 9,999 / month","months":1}"""
                )
            } else {
                FakeServer.json(422, """{"message":"This code is for Ultra.","code":"promo_other_plan","plan":"business"}""")
            }
        }
        applyPromo("TEAM20")
        waitFor { compose.onAllNodesWithText("TEAM20: 20% off Ultra for 1 month").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Request Ultra").assertExists()
    }

    @Test
    fun `a 100 percent code switches the plan on at once, with nothing to pay`() {
        openFlow()
        server.on(
            "POST",
            "/api/promo-codes/check",
            FakeServer.json(
                200,
                """{"code":"GIFT100","plan":"pro","plan_name":"Pro","percent":100,"final_price":"Rs 0 / month","free":true,"months":1}"""
            )
        )
        server.on(
            "POST",
            "/api/upgrade-requests",
            FakeServer.json(
                201,
                """{"id":"01JG","plan":"pro","status":"approved","approved_plan":"pro","approved_until":"2026-12-09T00:00:00Z","created_at":"2026-11-09T10:00:00Z"}"""
            )
        )
        server.on("GET", "/api/me/entitlements", FakeServer.json(404, "{}"))
        applyPromo("GIFT100")
        waitFor { compose.onAllNodesWithText("Switch on Pro").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Switch on Pro").performClick()
        waitFor { compose.onAllNodesWithText("You're on Pro").fetchSemanticsNodes().isNotEmpty() }
    }

    @Test
    fun `an older server without promo codes says so in the dialog`() {
        openFlow()
        server.on("POST", "/api/promo-codes/check", FakeServer.json(404, """{"code":"not_found"}"""))
        applyPromo("SAVE50")
        waitFor { compose.onAllNodesWithText("Promo codes aren't available right now.").fetchSemanticsNodes().isNotEmpty() }
    }

    @Test
    fun `the Free plan's own Groq key is checked by the server and never shown again`() {
        runBlocking { app.pairLocally(server) }
        server.on("PUT", "/api/me/transcription-key", FakeServer.json(200, """{"provider":"groq","set":true,"last4":"3f9a"}"""))
        show { TranscriptionKeySection(vm, plan) }

        compose.onNodeWithText("Open console.groq.com").assertIsDisplayed()
        compose.onNodeWithText("Groq API key").performTextInput("gsk_abcdefghijklmnopqrstuvwxyz3f9a")
        compose.onNodeWithText("Save").performClick()
        pumpUntil("saved") { runBlocking { app.graph.settings.current().plan.transcriptionKey?.set == true } }
        val body = Json.parseToJsonElement(server.requestsTo("PUT", "/api/me/transcription-key").single().bodyText()).jsonObject
        assertEquals("groq", body["provider"]!!.jsonPrimitive.content)
        assertEquals("3f9a", runBlocking { app.graph.settings.current().plan.transcriptionKey?.last4 })
    }
}
