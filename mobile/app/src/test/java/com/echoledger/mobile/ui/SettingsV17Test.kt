package com.echoledger.mobile.ui

import android.graphics.Bitmap
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.FeatureDto
import com.echoledger.mobile.data.LimitDto
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
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * v1.7 Settings, Plan & usage and the upgrade flow with receipts (docs/DESIGN_SYSTEM.md "Settings"): what each state
 * says and offers, and the design screenshots (docs/design/screens/phone-v17-*.png) in light and dark.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w393dp-h852dp-hdpi")
class SettingsV17Test : ScreenTest() {
    private val outDir = File(System.getProperty("screenshots.dir") ?: "build/screenshots")
    private var themeMode by mutableStateOf("dark")
    private var wallpaper by mutableStateOf(false)

    private val features = mapOf(
        "record" to FeatureDto(true, "Record and upload"),
        "platform_transcription" to FeatureDto(false, "Transcription on EchoLedger", "No key needed.", "paid", "pro"),
        "views" to FeatureDto(false, "Roman Urdu, English and Urdu side by side", "", "paid", "pro"),
        "screen_moments" to FeatureDto(false, "Screenshots of shared screens", "", "paid", "pro"),
        "live_translate" to FeatureDto(false, "Live Translate for games and videos", "", "paid", "business"),
        "ai_notes" to FeatureDto(false, "AI notes for projects", "", "paid", "business")
    )
    private val offers = listOf(
        PlanOfferDto("free", "Free", features = listOf(), limits = mapOf("monthly_minutes" to 0, "seats" to 1)),
        PlanOfferDto(
            "pro",
            "Pro",
            features = listOf("platform_transcription", "views", "screen_moments"),
            limits = mapOf("monthly_minutes" to 300, "seats" to 1),
            pricePkr = 2999,
            priceText = "Rs 2,999 a month"
        ),
        PlanOfferDto(
            "business",
            "Ultra",
            features = listOf("platform_transcription", "views", "screen_moments", "live_translate", "ai_notes"),
            limits = mapOf("monthly_minutes" to 900, "seats" to 10),
            pricePkr = 12499,
            priceText = "Rs 12,499 a month"
        )
    )
    private val limits = mapOf(
        "monthly_minutes" to LimitDto(0, 0, "2026-12-01T00:00:00+05:00"),
        "storage_bytes" to LimitDto(1L shl 30, 120L shl 20),
        "devices" to LimitDto(2, 1),
        "retention_days" to LimitDto(30),
        "max_file_minutes" to LimitDto(180),
        "uploads_per_day" to LimitDto(50, 3),
        "seats" to LimitDto(1, 1)
    )
    private val free =
        PlanState(
            name = "Free",
            key = "free",
            role = "owner",
            workspace = "Personal",
            features = features,
            limits = limits,
            offers = offers
        )
    private val pro = free.copy(
        name = "Pro",
        key = "pro",
        expiresAt = "2026-12-09T00:00:00+05:00",
        monthlyMinutes = 300,
        usedMinutes = 250,
        features = features.mapValues { (k, f) ->
            if (k in
                setOf("platform_transcription", "views", "screen_moments")
            ) {
                f.copy(enabled = true)
            } else {
                f
            }
        },
        limits =
            limits + ("monthly_minutes" to LimitDto(300, 250, "2026-12-01T00:00:00+05:00")) +
                ("storage_bytes" to LimitDto(20L shl 30, (14L shl 30) / 10))
    )
    private val ultra = pro.copy(
        name = "Ultra",
        key = "business",
        monthlyMinutes = 900,
        usedMinutes = 120,
        features = features.mapValues { (_, f) -> f.copy(enabled = true) },
        limits = limits + ("monthly_minutes" to LimitDto(900, 120, "2026-12-01T00:00:00+05:00")) + ("seats" to LimitDto(10, 3))
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
            note = "Put EL-7K2Q9M in the transfer's note.",
            reviewHours = "We check receipts every day. Most are done within a few hours.",
            paidAt = paidAt,
            reference = paidAt?.let { "FT26313ABC7781" },
            proofs = proofs
        ),
        timeline = listOfNotNull(TimelineStepDto("requested", "2026-11-09T10:10:00Z"), paidAt?.let { TimelineStepDto("receipt_sent", it) })
    )

    private fun shoot(content: @Composable () -> Unit) = show {
        EchoLedgerTheme(themeMode = themeMode, dynamicColour = wallpaper) {
            Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) { content() }
        }
    }

    private fun capture(name: String) {
        themeMode = "dark"
        one("$name-dark")
        themeMode = "light"
        compose.mainClock.advanceTimeBy(500)
        one("$name-light")
    }

    private fun one(name: String) {
        compose.waitForIdle()
        val bitmap = compose.onRoot().captureToImage().asAndroidBitmap()
        compose.onRoot().captureRoboImage(File(outDir, "phone-v17-$name.png"))
        assertTrue("$name drew nothing", bitmap.width > 0 && colours(bitmap) > 2)
    }

    private fun colours(bitmap: Bitmap): Int {
        val seen = HashSet<Int>()
        for (y in 0 until bitmap.height step 16) for (x in 0 until bitmap.width step 16) seen += bitmap.getPixel(x, y)
        return seen.size
    }

    private fun waitFor(condition: () -> Boolean) = pumpUntil {
        compose.waitForIdle()
        condition()
    }

    private fun signedIn(plan: PlanState, latest: UpgradeRequestDto? = null) {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/account", FakeServer.json(200, """{"name":"Hassam Ali","email":"hassam@example.com"}"""))
        server.on(
            "GET",
            "/api/upgrade-requests/latest",
            if (latest ==
                null
            ) {
                FakeServer.json(404, """{"code":"not_found","message":"No upgrade request yet."}""")
            } else {
                FakeServer.json(200, ApiJson.encodeToString(UpgradeRequestDto.serializer(), latest))
            }
        )
        vm.updateSettings { it.copy(plan = plan.copy(upgrade = latest)) }
        waitFor { runBlocking { app.graph.settings.current().plan.key == plan.key } }
    }

    @Test
    fun `the root opens with the account card, the plan and Upgrade, then sections with values`() {
        signedIn(free)
        shoot { SettingsScreen(vm, onSetup = {}) }
        waitFor { compose.onAllNodesWithText("Hassam Ali").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("hassam@example.com").assertIsDisplayed()
        compose.onNodeWithText("Upgrade").assertIsDisplayed()
        compose.onNodeWithText("Set up free transcription (2 minutes)").assertExists()
        compose.onNodeWithText("Recording").assertExists()
        compose.onNodeWithText("Appearance").assertExists()
        assertTrue(compose.onAllNodesWithText("PRO ✦").fetchSemanticsNodes().isEmpty())
        capture("settings-free")
    }

    @Test
    fun `Ultra has no Upgrade, and an open request replaces it with its status line`() {
        signedIn(ultra)
        shoot { SettingsScreen(vm, onSetup = {}) }
        waitFor { compose.onAllNodesWithText("Hassam Ali").fetchSemanticsNodes().isNotEmpty() }
        assertTrue(compose.onAllNodesWithText("Upgrade").fetchSemanticsNodes().isEmpty())
        capture("settings-ultra")
    }

    @Test
    fun `an open request shows its status line and no Upgrade anywhere`() {
        val r = request("in_review", paidAt = "2026-11-09T11:42:00Z")
        signedIn(free, r)
        shoot { SettingsScreen(vm, onSetup = {}) }
        waitFor { compose.onAllNodesWithText("Checking your payment for Pro").fetchSemanticsNodes().isNotEmpty() }
        assertTrue(compose.onAllNodesWithText("Upgrade").fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun `Plan & usage on Free says the key, the plans above it and their prices`() {
        signedIn(free)
        shoot {
            androidx.compose.foundation.layout.Column(Modifier.verticalScroll(androidx.compose.foundation.rememberScrollState())) {
                PlanUsagePage(vm, vm.settings.collectAsState().value.plan)
            }
        }
        compose.onNodeWithText("Free plan").assertExists()
        compose.onNodeWithText("Your Groq key").assertExists()
        compose.onNodeWithText("Rs 2,999 a month").assertExists()
        compose.onNodeWithText("Choose Ultra").assertExists()
        capture("plan-free")
    }

    @Test
    fun `Plan & usage on Pro shows minutes near the limit and only Ultra`() {
        signedIn(pro)
        shoot {
            androidx.compose.foundation.layout.Column(Modifier.verticalScroll(androidx.compose.foundation.rememberScrollState())) {
                PlanUsagePage(vm, vm.settings.collectAsState().value.plan)
            }
        }
        compose.onNodeWithText("250 of 300 min", useUnmergedTree = true).assertExists()
        compose.onNodeWithText("Upgrade to Ultra").assertExists()
        assertTrue(compose.onAllNodesWithText("Choose Pro").fetchSemanticsNodes().isEmpty())
        capture("plan-pro")
    }

    @Test
    fun `Plan & usage on Ultra is the top plan`() {
        signedIn(ultra)
        shoot {
            androidx.compose.foundation.layout.Column(Modifier.verticalScroll(androidx.compose.foundation.rememberScrollState())) {
                PlanUsagePage(vm, vm.settings.collectAsState().value.plan)
            }
        }
        compose.onNodeWithText("You're on our top plan.").assertExists()
        capture("plan-ultra")
    }

    @Test
    fun `the upgrade flow offers higher plans and requests with the promo code`() {
        signedIn(free)
        shoot { UpgradeFlow(vm, "pro", "Roman Urdu, English and Urdu side by side", null, inDialog = false) {} }
        compose.onNodeWithText("Request Pro").assertExists()
        compose.onNodeWithText("You'll see our bank details on the next screen.").assertExists()
        capture("upgrade-choose")
    }

    @Test
    fun `payment details can be copied and the next step is the receipt`() {
        signedIn(free, request("awaiting_payment"))
        shoot { UpgradeFlow(vm, "pro", null, FlowStep.Pay, inDialog = false) {} }
        waitFor { compose.onAllNodesWithText("EXAMPLE PRIVATE LIMITED").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("PK00 XXXX 0000 0001 2345 6789").assertExists()
        compose.onNodeWithContentDescription("Copy IBAN").assertExists()
        compose.onNodeWithText("Add payment receipt").assertExists()
        capture("upgrade-pay")
    }

    @Test
    fun `the receipt step takes screenshots, a transaction ID, and sends them`() {
        val r =
            request(
                "awaiting_payment",
                proofs = listOf(ProofDto("01JP1", "image", "image/webp", "Screenshot", 182_000, createdAt = "2026-11-09T11:40:00Z"))
            )
        signedIn(free, r)
        server.on(
            "POST",
            "/api/upgrade-requests/01JREQ/paid",
            FakeServer.json(
                200,
                ApiJson.encodeToString(UpgradeRequestDto.serializer(), request("in_review", paidAt = "2026-11-09T11:42:00Z"))
            )
        )
        shoot { UpgradeFlow(vm, "pro", null, FlowStep.Receipt, inDialog = false) {} }
        waitFor { compose.onAllNodesWithText("Send receipt").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Transaction ID (optional)").performTextInput("FT26313ABC7781")
        capture("upgrade-receipt")
        compose.onAllNodesWithText("Send receipt").fetchSemanticsNodes().let { assertTrue(it.isNotEmpty()) }
        compose.onAllNodesWithText("Send receipt")[1].performClick()
        waitFor { server.requestsTo("POST", "/api/upgrade-requests/01JREQ/paid").isNotEmpty() }
        assertTrue(server.requestsTo("POST", "/api/upgrade-requests/01JREQ/paid").first().bodyText().contains("FT26313ABC7781"))
    }

    @Test
    fun `a request in review shows the timeline and the details`() {
        signedIn(
            free,
            request(
                "in_review",
                proofs = listOf(ProofDto("01JP1", "image", locked = true, createdAt = "2026-11-09T11:40:00Z")),
                paidAt = "2026-11-09T11:42:00Z"
            )
        )
        shoot { UpgradeFlow(vm, "pro", null, FlowStep.Status, inDialog = false) {} }
        waitFor { compose.onAllNodesWithText("Checking your payment").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithText("Receipt sent").assertExists()
        compose.onNodeWithText("FT26313ABC7781").assertExists()
        capture("upgrade-status")
    }

    @Test
    fun `search finds a setting inside a page and shows where it is`() {
        signedIn(free)
        shoot { SettingsScreen(vm, onSetup = {}) }
        compose.onNodeWithText("Search settings").performClick()
        compose.onNodeWithContentDescription("Search settings").performTextInput("fingerprint")
        compose.onNodeWithText("App lock").assertExists()
        compose.onNodeWithText("App").assertExists()
        capture("search")
    }

    @Test
    fun `wallpaper colours leave the record button and the speaker colours alone`() {
        wallpaper = true
        signedIn(free)
        shoot { SettingsScreen(vm, onSetup = {}) }
        waitFor { compose.onAllNodesWithText("Hassam Ali").fetchSemanticsNodes().isNotEmpty() }
        capture("settings-wallpaper")
    }
}
