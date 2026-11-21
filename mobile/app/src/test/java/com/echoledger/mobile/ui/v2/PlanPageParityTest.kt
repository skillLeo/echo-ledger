package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.FeatureDto
import com.echoledger.mobile.data.LimitDto
import com.echoledger.mobile.data.PaymentDto
import com.echoledger.mobile.data.PlanOfferDto
import com.echoledger.mobile.data.TranscriptionKeyDto
import com.echoledger.mobile.data.UpgradeRequestDto
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.FlowStart
import com.echoledger.mobile.ui.FlowStep
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.v2.settings.PlanPage
import com.echoledger.mobile.ui.v2.settings.pageValue
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.time.LocalDate
import java.time.ZoneOffset
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Plan & usage on the v2 components against classic's page (docs/v2/audit/parity.csv PH-SET-075 to 082): the same
 * controls on Free and on a paid plan; the current plan's card (Paid until, Ends in N days, Upgrade, Compare plans, the
 * top plan); this month's meters and the Groq key line; what the plan includes and every feature, a locked one with its
 * plan's badge opening that plan; the plans with Choose; a request's card by stage with its actions (Cancel request
 * asks); in Urdu too.
 */
class PlanPageParityTest : ScreenTest() {
    private var page by mutableIntStateOf(1)
    private var language = EchoLanguage.English
    private var plan by mutableStateOf(free())
    private val flows = mutableListOf<FlowStart>()
    private val cancelled = mutableListOf<String>()
    private var keyOpened = 0
    private var shownOnce = false

    private fun offers() = listOf(
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

    private fun features() = mapOf(
        "views" to FeatureDto(false, "Roman Urdu and English views", "Read any call in Roman Urdu or English.", "paid", "pro"),
        "platform_transcription" to FeatureDto(false, "Transcription on EchoLedger", cost = "paid", lowestPlan = "pro"),
        "live_translate" to FeatureDto(false, "Live Translate", cost = "paid", lowestPlan = "business"),
        "search" to FeatureDto(true, "Search")
    )

    private fun free() = PlanState(
        name = "Free",
        key = "free",
        features = features(),
        offers = offers(),
        limits = mapOf(
            "storage_bytes" to LimitDto(5_000_000_000, 1_200_000_000),
            "devices" to LimitDto(2, 2),
            "retention_days" to LimitDto(365),
            "max_file_minutes" to LimitDto(180),
            "uploads_per_day" to LimitDto(20)
        ),
        transcriptionKey = TranscriptionKeyDto(set = false)
    )

    private fun inDays(n: Long) = LocalDate.now().plusDays(n).atTime(12, 0).toInstant(ZoneOffset.UTC).toString()

    private fun pro(expires: String? = inDays(3), used: Long = 120) = PlanState(
        name = "Pro",
        key = "pro",
        expiresAt = expires,
        features = features() + ("views" to FeatureDto(true, "Roman Urdu and English views")),
        offers = offers(),
        limits = mapOf("monthly_minutes" to LimitDto(300, used), "seats" to LimitDto(5, 2))
    )

    private fun request(stage: String) = UpgradeRequestDto(
        id = "01JF",
        plan = "business",
        amountDuePkr = 12499,
        referenceCode = "EL-7K2Q9M",
        payment = PaymentDto(stage = stage)
    )

    private fun showBoth() = show {
        if (page == 0) {
            SettingsScreen(vm, onSetup = {}, openPage = "plan")
        } else {
            EchoV2Theme(darkTheme = false, language = language) {
                PlanPage(
                    plan,
                    highlight = null,
                    onBack = {},
                    onOpenKey = { keyOpened++ },
                    onFlow = { flows += it },
                    cancelRequest = { id ->
                        cancelled += id
                        null
                    },
                    canPay = true
                )
            }
        }
    }

    private fun press(name: String) {
        val node = compose.onAllNodes((hasContentDescription(name) or hasText(name)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    private fun shown(words: String) = compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()

    private fun controlsOf(state: PlanState): Pair<Set<String>, Set<String>> {
        plan = state
        vm.updateSettings { it.copy(plan = state) }
        pumpUntil("the plan") { vm.settings.value.plan == state }
        page = 0
        if (shownOnce) compose.waitForIdle() else showBoth()
        shownOnce = true
        val today = mutableSetOf<String>()
        ParityKit.collect(compose, today)
        page = 1
        val v2 = mutableSetOf<String>()
        ParityKit.collect(compose, v2)
        return today to v2
    }

    private val reasons = mapOf("button: Search settings" to "v2 Settings searches from its first page; Back leads there")

    @Test
    fun theV2PageHasEveryControlOfTodaysPageOnFreeAndOnAPaidPlan() {
        val (today, v2) = controlsOf(free())
        val same = listOf(
            "button: Back",
            "button: Upgrade",
            "button: Compare plans",
            "button: Transcription",
            "button: See every feature",
            "button: Choose Pro",
            "button: Choose Ultra"
        )
        assertEquals(
            emptyList<String>(),
            ParityKit.problems(
                today.associateWith {
                    0
                },
                v2.associateWith { 0 },
                same.associateWith { it },
                reasons
            )
        )
        val (paidToday, paidV2) = controlsOf(pro())
        val paidSame = listOf("button: Back", "button: Upgrade to Ultra", "button: See every feature", "button: Choose Ultra")
        assertEquals(
            emptyList<String>(),
            ParityKit.problems(paidToday.associateWith { 0 }, paidV2.associateWith { 0 }, paidSame.associateWith { it }, reasons)
        )
    }

    @Test
    fun theCardSaysThePlanItsEndAndUpgradeOrCompareOpenThePlans() {
        showBoth()
        assertTrue(shown("Free plan"))
        assertTrue(shown("Transcripts use your own free Groq key."))
        press("Upgrade")
        assertEquals(FlowStart("pro"), flows.last())
        press("Choose Ultra")
        assertEquals(FlowStart("business"), flows.last())
        plan = pro(expires = inDays(3))
        compose.waitForIdle()
        assertTrue(shown("Ends in 3 days, on "))
        assertTrue(shown("Nothing is charged automatically."))
        press("Upgrade to Ultra")
        assertEquals(FlowStart("business"), flows.last())
        plan = pro(expires = inDays(40))
        compose.waitForIdle()
        assertTrue(shown("Paid until "))
        plan = pro(expires = null)
        compose.waitForIdle()
        assertTrue(shown("No end date"))
        // The top plan: nothing to upgrade to.
        plan = pro().copy(key = "business", name = "Ultra")
        compose.waitForIdle()
        assertTrue(shown("You're on our top plan."))
        assertTrue(compose.onAllNodes(hasText("Upgrade", substring = true) and hasClickAction()).fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun thisMonthShowsTheMetersTheResetDayAndTheKeyLine() {
        showBoth()
        // Free: the key line opens the key page; storage and devices as meters, the full one says what to do.
        assertTrue(shown("Not added. Tap to add your key"))
        press("Transcription")
        assertEquals(1, keyOpened)
        assertTrue(compose.onAllNodes(hasContentDescription("Storage: 1.1 GB of 4.7 GB")).fetchSemanticsNodes().isNotEmpty())
        assertTrue(shown("Sign out a device to add another."))
        // What the plan includes.
        assertTrue(shown("1 year"))
        assertTrue(shown("3 hours"))
        assertTrue(shown("Up to 20"))
        plan = pro(used = 120)
        compose.waitForIdle()
        assertTrue(compose.onAllNodes(hasContentDescription("Transcription: 120 of 300 min")).fetchSemanticsNodes().isNotEmpty())
        assertTrue(shown("Resets "))
        assertTrue(shown("Minutes reset on the 1st of each month."))
        assertTrue(shown("Team members"))
        plan = pro(used = 255)
        compose.waitForIdle()
        assertTrue(shown("You've used 85% of your minutes."))
        plan = pro(used = 300)
        compose.waitForIdle()
        assertTrue(shown("Transcription is paused until "))
    }

    @Test
    fun everyFeatureShowsALockedOnesPlanAndOpensIt() {
        showBoth()
        press("See every feature")
        pumpUntil("the list") { shown("Live Translate") }
        assertTrue(compose.onAllNodesWithText("Ultra").fetchSemanticsNodes().isNotEmpty())
        assertTrue(compose.onAllNodes(hasContentDescription("Included")).fetchSemanticsNodes().isNotEmpty())
        press("Live Translate")
        assertEquals(FlowStart("business", "Live Translate"), flows.last())
        // The Settings list shows a locked page's plan instead of its value.
        val res = V2Testing.resources(EchoLanguage.English)
        val locked = com.echoledger.mobile.data.AppSettings(plan = free())
        assertEquals(free().lockFor("live_translate")?.badge, pageValue(res, "games", locked))
    }

    @Test
    fun aRequestsCardSaysWhereItStandsAndItsActionsOpenTheFlowOrAsk() {
        plan = free().copy(upgrade = request("awaiting_payment"))
        showBoth()
        assertTrue(shown("Ultra requested"))
        assertTrue(shown("Waiting for payment"))
        assertTrue(shown("Send Rs 12,499 to our bank account"))
        assertTrue(shown("Reference EL-7K2Q9M."))
        press("Payment details")
        assertEquals(FlowStart("business", step = FlowStep.Pay), flows.last())
        // While a request is open nothing offers another plan.
        assertTrue(compose.onAllNodes(hasText("Choose Ultra") and hasClickAction()).fetchSemanticsNodes().isEmpty())
        // Cancel request asks; Keep request keeps it.
        press("Cancel request")
        compose.onAllNodes(hasText("Keep request") and hasClickAction() and hasAnyAncestor(isDialog())).onLast()
            .performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
        assertTrue(cancelled.isEmpty())
        press("Cancel request")
        compose.onAllNodes(hasText("Cancel request") and hasClickAction() and hasAnyAncestor(isDialog())).onLast()
            .performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("cancelled") { cancelled == listOf("01JF") }
        plan = free().copy(upgrade = request("in_review"))
        compose.waitForIdle()
        assertTrue(shown("Checking your payment"))
        press("View request")
        assertEquals(FlowStart("business", step = FlowStep.Status), flows.last())
        plan = free().copy(upgrade = request("needs_receipt"))
        compose.waitForIdle()
        assertTrue(shown("We need another receipt"))
        press("Add receipt")
        assertEquals(FlowStart("business", step = FlowStep.Receipt), flows.last())
        plan = free().copy(upgrade = request("declined").copy(status = "declined", decidedAt = inDays(0)))
        compose.waitForIdle()
        assertTrue(shown("Not approved"))
        press("Request again")
        assertEquals(FlowStart("business", step = FlowStep.Choose), flows.last())
        press("Dismiss")
        assertTrue(!shown("We couldn't confirm your payment"))
    }

    @Test
    fun inUrduThePageSpeaksUrdu() {
        language = EchoLanguage.Urdu
        plan = pro(used = 120)
        showBoth()
        val words = listOf("Pro پلان", "اس مہینے", "ٹرانسکرپشن", "پلانز", "ہر فیچر دیکھیں")
        runCatching { pumpUntil("Urdu") { words.all(::shown) } }.onFailure { throw AssertionError("missing: " + words.filterNot(::shown)) }
        assertTrue(
            compose.onAllNodes(hasContentDescription("ٹرانسکرپشن: 300 منٹ میں سے 120")).fetchSemanticsNodes().isNotEmpty()
        )
    }
}
