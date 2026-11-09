package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
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
import org.junit.Test

/** v1.6 P1.4: the upgrade sheet asks for the plan and shows the owner's payment instructions; the Free plan's own key. */
class PlanUiTest : ScreenTest() {
    private val plan = PlanState(
        name = "Free",
        key = "free",
        features = mapOf(
            "views" to FeatureDto(false, "Roman Urdu and English views", "Read any call in Roman Urdu or English.", "paid", "pro"),
            "platform_transcription" to FeatureDto(false, "Transcription on EchoLedger", cost = "paid", lowestPlan = "pro")
        ),
        offers = listOf(
            PlanOfferDto("free", "Free", "FREE"),
            PlanOfferDto("pro", "Pro", "PRO", "#7c3aed", "Rs 2,000 a month", features = listOf("views"))
        )
    )

    @Test
    fun `All features lists every feature, a check for what the plan has and the badge for the rest (v1_6_2)`() {
        val mixed = plan.copy(features = plan.features + ("record" to FeatureDto(true, "Record and upload")))
        show { AllFeatures(vm, mixed) }

        compose.onNodeWithText("Your plan has 1 of 3 features.").assertExists()
        compose.onNodeWithText("Record and upload").assertExists()
        compose.onNodeWithContentDescription("Included").assertExists()
        compose.onNodeWithText("Roman Urdu and English views").assertExists()
        compose.onAllNodesWithText(PlanState.badgeText("PRO")).assertCountEquals(2)

        compose.onAllNodesWithText(PlanState.badgeText("PRO"))[0].performClick()
        compose.onNodeWithText("Request upgrade").assertExists()
    }

    private fun openPromo() {
        show { UpgradeSheet(vm, plan, plan.lockFor("views")) {} }
        compose.onNodeWithText("Have a promo code?").performClick()
        compose.onNodeWithText("Promo code").performTextInput("save50")
        compose.onNodeWithText("Apply").performClick()
    }

    @Test
    fun `a promo code shows the new price, and Request upgrade sends it (v1_6_2)`() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "POST",
            "/api/promo-codes/check",
            FakeServer.json(
                200,
                """{"code":"SAVE50","plan":"pro","plan_name":"Pro","percent":50,"price":"Rs 2,999 / month","final_price":"Rs 1,500 / month","free":false,"months":1}"""
            )
        )
        server.on(
            "POST",
            "/api/upgrade-requests",
            FakeServer.json(
                201,
                """{"id":"01JF","plan":"pro","status":"pending","promo_code":"SAVE50","percent":50,"amount_due":"Rs 1,500 / month","payment_instructions":"Bank transfer…","created_at":"2026-11-09T10:00:00Z"}"""
            )
        )
        openPromo()

        pumpUntil("the price") {
            compose.onAllNodesWithText("Pro: Rs 2,999 / month → Rs 1,500 / month with SAVE50").fetchSemanticsNodes().isNotEmpty()
        }
        assertEquals(
            "SAVE50",
            Json.parseToJsonElement(
                server.requestsTo("POST", "/api/promo-codes/check").single().bodyText()
            ).jsonObject["code"]!!.jsonPrimitive.content
        )
        compose.onNodeWithText("Request upgrade").performClick()

        pumpUntil("the amount to pay") {
            compose.onAllNodesWithText("To pay: Rs 1,500 / month (with SAVE50)").fetchSemanticsNodes().isNotEmpty()
        }
        assertEquals(
            "SAVE50",
            Json.parseToJsonElement(
                server.requestsTo("POST", "/api/upgrade-requests").single().bodyText()
            ).jsonObject["promo_code"]!!.jsonPrimitive.content
        )
    }

    @Test
    fun `a code for another plan says so and offers that plan`() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "POST",
            "/api/promo-codes/check",
            FakeServer.json(422, """{"message":"This code is for Ultra.","code":"promo_other_plan","plan":"business"}""")
        )
        val withUltra = plan.copy(offers = plan.offers + PlanOfferDto("business", "Ultra", "ULTRA"))
        show { UpgradeSheet(vm, withUltra, withUltra.lockFor("views")) {} }
        compose.onNodeWithText("Have a promo code?").performClick()
        compose.onNodeWithText("Promo code").performTextInput("TEAM20")
        compose.onNodeWithText("Apply").performClick()

        pumpUntil("the other plan") { compose.onAllNodesWithText("This code is for Ultra.").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Use it for Ultra").assertExists()
    }

    @Test
    fun `a 100 percent code switches the plan on at once, with nothing to pay`() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "POST",
            "/api/promo-codes/check",
            FakeServer.json(
                200,
                """{"code":"FREEPRO","plan":"pro","plan_name":"Pro","percent":100,"price":"Rs 2,999 / month","final_price":"Rs 0","free":true,"months":2}"""
            )
        )
        server.on(
            "POST",
            "/api/upgrade-requests",
            FakeServer.json(
                201,
                """{"id":"01JG","plan":"pro","status":"approved","approved_plan":"pro","approved_until":"2027-01-09T10:00:00Z","promo_code":"FREEPRO","percent":100,"amount_due":"Rs 0","payment_instructions":null,"created_at":"2026-11-09T10:00:00Z"}"""
            )
        )
        openPromo()

        pumpUntil("free") { compose.onAllNodesWithText("Pro free for 2 months with FREEPRO").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Switch on").performClick()

        pumpUntil("the plan on") { compose.onAllNodesWithText("Your Pro plan is on until 2027-01-09.").fetchSemanticsNodes().isNotEmpty() }
    }

    @Test
    fun `an older server without promo codes hides the field`() {
        runBlocking { app.pairLocally(server) }
        server.on("POST", "/api/promo-codes/check", FakeServer.json(404, """{"message":"Not found"}"""))
        openPromo()

        pumpUntil("the field to go") { compose.onAllNodesWithText("Promo code").fetchSemanticsNodes().isEmpty() }
        compose.onAllNodesWithText("Have a promo code?").assertCountEquals(0)
    }

    @Test
    fun `Request upgrade sends the plan and the message, then shows the payment instructions`() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "POST",
            "/api/upgrade-requests",
            FakeServer.json(
                201,
                """{"id":"01JF","plan":"pro","status":"pending","message":"Please","payment_instructions":"Send Rs 2,000 to JazzCash 0300…","created_at":"2026-11-09T10:00:00Z"}"""
            )
        )
        show { UpgradeSheet(vm, plan, plan.lockFor("views")) {} }

        // The title, and again in Pro's list of what it includes.
        compose.onAllNodes(androidx.compose.ui.test.hasText("Roman Urdu and English views")).assertCountEquals(2)
        compose.onNodeWithText("Rs 2,000 a month").assertIsDisplayed()
        compose.onNodeWithText("Message (optional)").performTextInput("Please")
        compose.onNodeWithText("Request upgrade").performClick()
        pumpUntil("the instructions") {
            compose.waitForIdle()
            compose.onAllNodes(androidx.compose.ui.test.hasText("Send Rs 2,000 to JazzCash 0300…")).fetchSemanticsNodes().isNotEmpty()
        }
        compose.onNodeWithText("We'll activate it once confirmed.").assertIsDisplayed()
        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/upgrade-requests").single().bodyText()).jsonObject
        assertEquals("pro", body["plan"]!!.jsonPrimitive.content)
        assertEquals("Please", body["message"]!!.jsonPrimitive.content)
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
