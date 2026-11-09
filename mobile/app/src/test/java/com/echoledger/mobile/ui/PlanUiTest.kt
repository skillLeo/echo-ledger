package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
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
