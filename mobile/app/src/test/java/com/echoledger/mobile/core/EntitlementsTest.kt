package com.echoledger.mobile.core

import com.echoledger.mobile.data.EntitlementPlanDto
import com.echoledger.mobile.data.EntitlementsDto
import com.echoledger.mobile.data.FeatureDto
import com.echoledger.mobile.data.LimitDto
import com.echoledger.mobile.data.PlanOfferDto
import com.echoledger.mobile.data.TranscriptionKeyDto
import com.echoledger.mobile.ui.limitText
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** v1.6 P1: the server's entitlements on the phone, one badge style, and older servers keeping everything on. */
class EntitlementsTest {
    private val free = EntitlementsDto(
        plan = EntitlementPlanDto("free", "Free", "FREE", "#64748b"),
        features = mapOf(
            "record" to FeatureDto(true, "Record", cost = "free"),
            "views" to FeatureDto(false, "Roman Urdu and English views", "Read any call in Roman Urdu or English.", "paid", "pro"),
            "live_translate" to FeatureDto(false, "Live Translate", cost = "paid", lowestPlan = "business"),
            "platform_transcription" to FeatureDto(false, "Transcription on EchoLedger", cost = "paid", lowestPlan = "pro")
        ),
        limits = mapOf("devices" to LimitDto(2, 1), "monthly_minutes" to LimitDto(0, 0)),
        transcriptionKey = TranscriptionKeyDto(set = false),
        plans = listOf(
            PlanOfferDto("pro", "Pro", "PRO", "#7c3aed", "Rs 2,000 a month"),
            PlanOfferDto("business", "Business", "BUSINESS", "#0f766e")
        )
    )

    @Test
    fun `a Free plan locks paid features with the lowest plan's badge`() {
        val plan = PlanState.withEntitlements(PlanState(), free)
        val views = plan.lockFor("views")!!
        assertEquals("PRO ✦", views.badge)
        assertEquals("pro", views.planKey)
        assertEquals("#7c3aed", views.color)
        assertEquals("Read any call in Roman Urdu or English.", views.description)
        assertEquals("BUSINESS ✦", plan.lockFor("live_translate")!!.badge)
        assertNull(plan.lockFor("record"))
        assertFalse(plan.liveTranslate)
        assertTrue(plan.needsOwnKey)
        assertEquals("Free", plan.name)
    }

    @Test
    fun `an older server's plan keeps everything on and no badges`() {
        val plan = PlanState(name = "Personal")
        assertTrue(plan.allows("views"))
        assertNull(plan.lockFor("views"))
        assertFalse(plan.needsOwnKey)
    }

    @Test
    fun `limits read in plain words`() {
        assertEquals("1 of 2", limitText("devices", 1, 2))
        assertEquals("50 MB of 1 GB", limitText("storage_bytes", 50L shl 20, 1L shl 30))
        assertEquals("120 min of 600 min", limitText("monthly_minutes", 120, 600))
        assertEquals("30 days", limitText("retention_days", null, 30))
        assertEquals("No limit", limitText("seats", null, null))
        assertEquals("3 · no limit", limitText("devices", 3, null))
    }
}
