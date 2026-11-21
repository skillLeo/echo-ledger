package com.echoledger.mobile.ui.v2

import android.content.ClipboardManager
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.FeatureDto
import com.echoledger.mobile.data.PaymentAccountDto
import com.echoledger.mobile.data.PaymentDto
import com.echoledger.mobile.data.PlanOfferDto
import com.echoledger.mobile.data.ProofDto
import com.echoledger.mobile.data.TimelineStepDto
import com.echoledger.mobile.data.UpgradeRequestDto
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.bodyText
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.FlowStep
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.plan.V2UpgradeFlow
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Upgrading in the new design does what classic's flow does (parity.csv PH-OTH-041): the plans above yours with their
 * prices and lines, a promo code (and one for another plan), the request; payment details each copyable; the receipt
 * with its transaction ID; the status with its timeline; Cancel request after asking; a 100 % code; and Urdu.
 */
class V2UpgradeParityTest : ScreenTest() {
    private val free = PlanState(
        name = "Free",
        key = "free",
        features = mapOf(
            "views" to FeatureDto(false, "Roman Urdu and English views", cost = "paid", lowestPlan = "pro"),
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
                limits = mapOf("monthly_minutes" to 900, "seats" to 10),
                pricePkr = 12499,
                priceText = "Rs 12,499 a month"
            )
        )
    )

    private fun request(stage: String, proofs: List<ProofDto> = emptyList(), paidAt: String? = null) = UpgradeRequestDto(
        id = "01JREQ",
        plan = "pro",
        status = if (stage == "approved") "approved" else "pending",
        amountDue = "Rs 2,999",
        amountDuePkr = 2999,
        referenceCode = "EL-7K2Q9M",
        createdAt = "2026-11-09T10:10:00Z",
        payment = PaymentDto(
            stage = stage,
            accounts = listOf(
                PaymentAccountDto("Example Bank", "Main Branch (0001)", "EXAMPLE PRIVATE LIMITED", "0123456789", "PK00XXXX0000000123456789")
            ),
            reviewHours = "We check receipts every day.",
            paidAt = paidAt,
            reference = paidAt?.let { "FT26313ABC7781" },
            proofs = proofs
        ),
        timeline = listOfNotNull(TimelineStepDto("requested", "2026-11-09T10:10:00Z"), paidAt?.let { TimelineStepDto("receipt_sent", it) })
    )

    private fun json(r: UpgradeRequestDto) = ApiJson.encodeToString(UpgradeRequestDto.serializer(), r)

    private var dismissed = 0

    private fun open(latest: UpgradeRequestDto? = null, startAt: FlowStep? = null, language: EchoLanguage = EchoLanguage.English) {
        runBlocking { app.pairLocally(server) }
        server.on("*", ".*", FakeServer.json(404, """{"message":"Not found","code":"not_found"}"""))
        server.on(
            "GET",
            "/api/upgrade-requests/latest",
            latest?.let { FakeServer.json(200, json(it)) } ?: FakeServer.json(404, """{"code":"not_found"}""")
        )
        vm.updateSettings { it.copy(plan = free.copy(upgrade = latest)) }
        pumpUntil("the plan") { runBlocking { app.graph.settings.current().plan.key == "free" } }
        show {
            EchoV2Theme(darkTheme = false, language = language) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    androidx.compose.foundation.layout.Box {
                        V2UpgradeFlow(vm, "pro", "Roman Urdu and English views", startAt, given = free) { dismissed++ }
                        EchoToastHost(toasts)
                    }
                }
            }
        }
    }

    private fun seen(words: String) = compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()

    private fun await(words: String) = pumpUntil("\"$words\"") { seen(words) }

    private fun press(words: String) {
        val node = compose.onAllNodes((hasText(words) or hasContentDescription(words)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    private fun clipboard() = app.getSystemService(ClipboardManager::class.java).primaryClip?.getItemAt(0)?.text?.toString()

    @Test
    fun thePlansAboveYoursAPromoCodeAndTheRequestGoToTheServer() {
        open()
        await("Roman Urdu and English views is in Pro. You're on Free.")
        assertTrue(seen("Rs 2,999 a month") && seen("Rs 12,499 a month"))
        assertTrue(seen("Everything in Pro, plus:"))
        assertTrue(seen("900 transcription minutes a month") && seen("A team of up to 10 people") && seen("Live Translate"))
        server.on(
            "POST",
            "/api/promo-codes/check",
            FakeServer.json(
                200,
                """{"code":"SAVE50","plan":"pro","plan_name":"Pro","percent":50,"final_price":"Rs 1,500 / month","free":false,"months":1}"""
            )
        )
        server.on("POST", "/api/upgrade-requests", FakeServer.json(201, json(request("awaiting_payment"))))
        press("Promo code")
        compose.onAllNodes(hasText("Promo code") and hasSetTextAction()).onFirst().performTextInput("save50")
        press("Apply")
        await("SAVE50: 50% off Pro for 1 month")
        assertTrue(seen("Rs 1,500"))
        press("Request Pro")
        pumpUntil("requested") { server.requestsTo("POST", "/api/upgrade-requests").isNotEmpty() }
        val body = Json.parseToJsonElement(server.requestsTo("POST", "/api/upgrade-requests").last().bodyText()).jsonObject
        assertEquals("pro", body["plan"]!!.jsonPrimitive.content)
        assertEquals("SAVE50", body["promo_code"]!!.jsonPrimitive.content)
        // The next step is paying.
        await("EXAMPLE PRIVATE LIMITED")
    }

    @Test
    fun aCodeForAnotherPlanMovesTheChoiceToThatPlan() {
        open()
        server.on("POST", "/api/promo-codes/check") { req ->
            if (req.bodyText().contains("\"plan\":\"business\"")) {
                FakeServer.json(
                    200,
                    """{"code":"TEAM20","plan":"business","plan_name":"Ultra","percent":20,"final_price":"Rs 9,999 / month","months":3}"""
                )
            } else {
                FakeServer.json(422, """{"message":"This code is for Ultra.","code":"promo_other_plan","plan":"business"}""")
            }
        }
        press("Promo code")
        compose.onAllNodes(hasText("Promo code") and hasSetTextAction()).onFirst().performTextInput("TEAM20")
        press("Apply")
        await("TEAM20: 20% off Ultra for 3 months")
        assertTrue(seen("Request Ultra"))
    }

    @Test
    fun aHundredPercentCodeSwitchesThePlanOnAtOnce() {
        open()
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
        press("Promo code")
        compose.onAllNodes(hasText("Promo code") and hasSetTextAction()).onFirst().performTextInput("GIFT100")
        press("Apply")
        await("Switch on Pro")
        press("Switch on Pro")
        await("You're on Pro")
        press("Done")
        assertEquals(1, dismissed)
    }

    @Test
    fun everyPaymentDetailCopiesAndTheNextStepIsTheReceipt() {
        open(request("awaiting_payment"), FlowStep.Pay)
        await("EXAMPLE PRIVATE LIMITED")
        assertTrue(seen("PK00 XXXX 0000 0001 2345 6789"))
        press("Copy IBAN")
        assertEquals("PK00XXXX0000000123456789", clipboard())
        await("IBAN copied")
        press("Copy Amount")
        assertEquals("2999", clipboard())
        press("Copy Reference")
        assertEquals("EL-7K2Q9M", clipboard())
        press("Add payment receipt")
        await("A screenshot or PDF of your receipt.")
    }

    @Test
    fun theReceiptGoesWithItsTransactionId() {
        val r = request("awaiting_payment", proofs = listOf(ProofDto("01JP1", "image", "image/webp", "Screenshot", 182_000)))
        open(r, FlowStep.Receipt)
        server.on(
            "POST",
            "/api/upgrade-requests/01JREQ/paid",
            FakeServer.json(200, json(request("in_review", paidAt = "2026-11-09T11:42:00Z")))
        )
        await("Transaction ID (optional)")
        compose.onAllNodes(hasText("Transaction ID (optional)") and hasSetTextAction()).onFirst().performTextInput("FT26313ABC7781")
        assertTrue(compose.onAllNodes(hasContentDescription("Remove receipt 1") and hasClickAction()).fetchSemanticsNodes().isNotEmpty())
        compose.onAllNodes(hasText("Send receipt") and hasClickAction()).onFirst().performClick()
        pumpUntil("sent") { server.requestsTo("POST", "/api/upgrade-requests/01JREQ/paid").isNotEmpty() }
        assertTrue(server.requestsTo("POST", "/api/upgrade-requests/01JREQ/paid").first().bodyText().contains("FT26313ABC7781"))
        await("Checking your payment")
    }

    @Test
    fun theStatusShowsItsTimelineAndCancelAsksFirst() {
        val r = request("in_review", proofs = listOf(ProofDto("01JP1", "image", locked = true)), paidAt = "2026-11-09T11:42:00Z")
        open(r, FlowStep.Status)
        server.on(
            "POST",
            "/api/upgrade-requests/01JREQ/cancel",
            FakeServer.json(200, json(r.copy(status = "cancelled", payment = r.payment!!.copy(stage = "cancelled"))))
        )
        await("Checking your payment")
        assertTrue(seen("In review") && seen("We check receipts every day."))
        assertTrue(compose.onAllNodes(hasContentDescription("Receipt sent, done", substring = true)).fetchSemanticsNodes().isNotEmpty())
        assertTrue(compose.onAllNodes(hasContentDescription("Checked by our team, current")).fetchSemanticsNodes().isNotEmpty())
        assertTrue(seen("FT26313ABC7781"))
        press("Cancel request")
        await("Cancel this request?")
        assertTrue(server.requestsTo("POST", "/api/upgrade-requests/01JREQ/cancel").isEmpty())
        compose.onAllNodes(hasText("Cancel request") and hasClickAction())[1].performClick()
        pumpUntil("cancelled") { server.requestsTo("POST", "/api/upgrade-requests/01JREQ/cancel").isNotEmpty() }
        await("Request cancelled")
        assertTrue(seen("Request again"))
    }

    @Test
    fun inUrduTheFlowIsInUrdu() {
        open(request("awaiting_payment"), FlowStep.Status, EchoLanguage.Urdu)
        await("ادائیگی کا انتظار")
        assertTrue(seen("درخواست منسوخ کریں"))
        assertTrue(seen("ادائیگی کی تفصیلات"))
    }
}
