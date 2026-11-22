package com.echoledger.mobile.ui.v2

import android.net.Uri
import android.provider.Settings
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.AndroidReleaseDto
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.FeatureDto
import com.echoledger.mobile.data.PaymentDto
import com.echoledger.mobile.data.TranscriptionKeyDto
import com.echoledger.mobile.data.UpgradeRequestDto
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.FlowStep
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.v2.settings.AboutActions
import com.echoledger.mobile.ui.v2.settings.AboutPage
import com.echoledger.mobile.ui.v2.settings.StateLine
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.update.UpdateStatus
import java.time.LocalDate
import java.time.ZoneOffset
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * About and the line under the profile card on the v2 components against classic's (docs/v2/audit/parity.csv PH-SET-003,
 * 092 to 094): the version and the server, Check for updates through each step (download, install, Allow installing),
 * Export and Import settings through the file picker, this phone's name and the Setup guide; the one line under the card
 * while something needs the person, each opening what it is about; in Urdu too.
 */
class AboutPageParityTest : ScreenTest() {
    private var page by mutableIntStateOf(1)
    private var language = EchoLanguage.English
    private var status by mutableStateOf<UpdateStatus>(UpdateStatus.Idle)
    private var line by mutableStateOf(AppSettings())
    private var deletesAt by mutableStateOf<String?>(null)
    private val calls = mutableListOf<String>()
    private var setupOpened = 0
    private val release = AndroidReleaseDto("2.1.0", 210, "https://echoledger.test/android.apk", "0".repeat(64), 31_457_280)

    private val actions = AboutActions(
        check = { calls += "check" },
        install = { calls += "install ${it.version}" },
        exportTo = { calls += "export $it" },
        importFrom = { calls += "import $it" }
    )

    private val launched = mutableListOf<String>()
    private val files = object : androidx.activity.result.ActivityResultRegistryOwner {
        override val activityResultRegistry = object : androidx.activity.result.ActivityResultRegistry() {
            override fun <I : Any?, O : Any?> onLaunch(
                requestCode: Int,
                contract: androidx.activity.result.contract.ActivityResultContract<I, O>,
                input: I,
                options: androidx.core.app.ActivityOptionsCompat?
            ) {
                launched += (input as? Array<*>)?.joinToString() ?: input.toString()
                dispatchResult(requestCode, Uri.parse("content://files/settings.json"))
            }
        }
    }

    private fun showBoth() = show {
        if (page == 0) {
            SettingsScreen(vm, onSetup = {}, openPage = "about")
        } else {
            EchoV2Theme(darkTheme = false, language = language) {
                if (page == 1) {
                    androidx.compose.runtime.CompositionLocalProvider(
                        androidx.activity.compose.LocalActivityResultRegistryOwner provides files
                    ) {
                        AboutPage(
                            vm.settings.value.copy(deviceName = "Galaxy S25", serverUrl = "https://echoledger.test"),
                            status,
                            actions,
                            highlight = null,
                            onSetup = { setupOpened++ },
                            onBack = {},
                            updates = true
                        )
                    }
                } else {
                    StateLine(line, deletesAt, onOpenPlan = { calls += "plan" }, onOpenKey = { calls += "key" }, onRequest = {
                        calls +=
                            "request $it"
                    })
                }
            }
        }
    }

    private fun press(name: String) {
        val node = compose.onAllNodes((hasContentDescription(name) or hasText(name, substring = true)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    private fun shown(words: String) = compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()

    @Test
    fun aboutHasEveryControlOfTodaysPage() {
        vm.updateSettings { it.copy(deviceName = "Galaxy S25") }
        pumpUntil("named") { vm.settings.value.deviceName == "Galaxy S25" }
        page = 0
        showBoth()
        val today = mutableSetOf<String>()
        ParityKit.collect(compose, today)
        page = 1
        val v2 = mutableSetOf<String>()
        ParityKit.collect(compose, v2)
        val same =
            listOf("button: Back", "button: Export settings", "button: Import settings", "button: Setup guide", "button: Check for updates")
        val reasons = mapOf("button: Search settings" to "v2 Settings searches from its first page; Back leads there")
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
    }

    @Test
    fun theVersionServerAndEachUpdateStepWithItsOneButton() {
        showBoth()
        assertTrue(shown("EchoLedger "))
        assertTrue(shown("Server echoledger.test"))
        press("Check for updates")
        assertEquals(listOf("check"), calls)
        status = UpdateStatus.Checking
        compose.waitForIdle()
        assertTrue(shown("Looking for updates…"))
        status = UpdateStatus.Available(release)
        compose.waitForIdle()
        assertTrue(shown("EchoLedger 2.1.0 is ready to install (30.0 MB)."))
        press("Update")
        assertEquals("install 2.1.0", calls.last())
        status = UpdateStatus.Downloading(release, 40)
        compose.waitForIdle()
        assertTrue(shown("Downloading the update… 40 %"))
        status = UpdateStatus.NeedsPermission(release)
        compose.waitForIdle()
        press("Allow")
        val allow = shadowOf(app).nextStartedActivity
        assertEquals(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, allow.action)
        assertEquals(Uri.parse("package:${app.packageName}"), allow.data)
        status = UpdateStatus.UpToDate
        compose.waitForIdle()
        assertTrue(shown("You have the newest version."))
    }

    @Test
    fun exportAndImportGoThroughTheFilePickerAndThisPhoneOpensTheSetupGuide() {
        showBoth()
        // Export asks the file picker for a new file named for today; Import for a JSON (or text) file. Never the pairing.
        press("Export settings")
        pumpUntil("exported") { calls.lastOrNull() == "export content://files/settings.json" }
        assertTrue(launched.last(), launched.last().startsWith("EchoLedger settings ") && launched.last().endsWith(".json"))
        press("Import settings")
        pumpUntil("imported") { calls.lastOrNull() == "import content://files/settings.json" }
        assertEquals("application/json, text/plain", launched.last())
        assertTrue(shown("Galaxy S25"))
        press("Setup guide")
        assertEquals(1, setupOpened)
    }

    private fun inDays(n: Long) = LocalDate.now().plusDays(n).atTime(12, 0).toInstant(ZoneOffset.UTC).toString()

    @Test
    fun theLineUnderTheCardSaysWhatNeedsThePersonAndOpensIt() {
        page = 2
        val keyless = PlanState(
            name = "Free",
            key = "free",
            features = mapOf("platform_transcription" to FeatureDto(false, "Transcription on EchoLedger")),
            transcriptionKey = TranscriptionKeyDto(set = false)
        )
        line = AppSettings(serverUrl = "https://echoledger.test", plan = keyless)
        showBoth()
        pumpUntil("key") { shown("Set up free transcription (2 minutes)") }
        press("Set up free transcription")
        assertEquals("key", calls.last())
        line = AppSettings(serverUrl = "https://echoledger.test", plan = PlanState(name = "Pro", key = "pro", expiresAt = inDays(3)))
        pumpUntil("ends") { shown("Pro ends on ") }
        press("Pro ends on")
        assertEquals("plan", calls.last())
        val request = UpgradeRequestDto(id = "r1", plan = "pro", payment = PaymentDto(stage = "awaiting_payment"))
        line = AppSettings(serverUrl = "https://echoledger.test", plan = keyless.copy(upgrade = request))
        pumpUntil("waiting") { shown("Pro requested. Waiting for your payment") }
        press("Waiting for your payment")
        assertEquals("request ${FlowStep.Pay}", calls.last())
        line =
            AppSettings(
                serverUrl = "https://echoledger.test",
                plan = keyless.copy(upgrade = request.copy(payment = PaymentDto(stage = "needs_receipt")))
            )
        pumpUntil("receipt") { shown("We need another receipt for Pro") }
        press("We need another receipt")
        assertEquals("request ${FlowStep.Receipt}", calls.last())
        line =
            AppSettings(
                serverUrl = "https://echoledger.test",
                plan = keyless.copy(upgrade = request.copy(payment = PaymentDto(stage = "in_review")))
            )
        pumpUntil("checking") { shown("Checking your payment for Pro") }
        // The deletion date comes first of all.
        deletesAt = inDays(5)
        pumpUntil("deletion") { shown("Your account will be deleted on ") }
        press("Your account will be deleted")
        assertEquals("plan", calls.last())
        // Nothing to say: no line.
        deletesAt = null
        line = AppSettings(serverUrl = "https://echoledger.test", plan = PlanState(name = "Pro", key = "pro", expiresAt = inDays(60)))
        compose.waitForIdle()
        assertTrue(compose.onAllNodes(hasClickAction()).fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun inUrduAboutAndTheLineSpeakUrdu() {
        language = EchoLanguage.Urdu
        showBoth()
        pumpUntil("Urdu") { listOf("اپ ڈیٹس دیکھیں", "سیٹنگز ایکسپورٹ کریں", "یہ فون", "سیٹ اپ گائیڈ").all(::shown) }
    }
}
