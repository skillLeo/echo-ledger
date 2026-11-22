package com.echoledger.mobile.ui.v2

import android.content.ClipboardManager
import android.provider.Settings
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import com.echoledger.mobile.core.CheckRow
import com.echoledger.mobile.core.DeviceCheck
import com.echoledger.mobile.core.PhoneBrand
import com.echoledger.mobile.core.PhoneFacts
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.phoneFacts
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.PhoneCheckActions
import com.echoledger.mobile.ui.v2.settings.PhoneCheckPage
import com.echoledger.mobile.ui.v2.settings.PhoneCheckWords
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * Help's phone check on the v2 components against classic's Help page (docs/v2/audit/parity.csv PH-SET-090 and 091):
 * the same controls; every line the device check can say, in English and in Urdu; each fix doing what classic's does;
 * Copy diagnostics, Copy report (in English, as the admin reads it) and Send to my admin; the maker's steps.
 */
class PhoneCheckParityTest : ScreenTest() {
    private var page by mutableIntStateOf(1)
    private var language = EchoLanguage.English
    private var setupOpened = 0
    private var probed = 0
    private val sent = mutableListOf<List<CheckRow>>()

    private fun caps(sdk: Int = 34, helper: Boolean = false, mic: Boolean = true, overlay: Boolean = true, inBuild: Boolean = true) =
        Capabilities(sdk, helper, mic, true, overlay, inBuild)

    private fun facts(
        caps: Capabilities = caps(),
        battery: Boolean = false,
        line: Boolean = true,
        calls: Boolean = true,
        signedIn: Boolean = true,
        plan: Boolean = true,
        maker: String = "samsung"
    ) = PhoneFacts(maker, maker, "SM-S931B", "16", "2.0.4", caps, battery, line, calls, signedIn, plan, 12)

    private var shownFacts = facts()

    private fun showBoth() = show {
        if (page == 0) {
            SettingsScreen(vm, onSetup = {}, openPage = "help")
        } else {
            EchoV2Theme(darkTheme = false, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    PhoneCheckPage(
                        PhoneCheckActions(
                            facts = { shownFacts },
                            diagnostics = { "EchoLedger diagnostics" },
                            send = { _, rows -> sent += rows },
                            signedIn = true
                        ),
                        onSetup = { setupOpened++ },
                        onBack = {},
                        audioProbe = { probed++ }
                    )
                }
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

    /** Every combination the device check reads, so every line it can say comes up. */
    private fun everyFacts(): List<PhoneFacts> = buildList {
        for (sdk in listOf(29, 31, 34)) {
            for (helper in listOf(false, true)) {
                for (mic in listOf(false, true)) {
                    for (overlay in listOf(false, true)) {
                        for (inBuild in listOf(false, true)) {
                            for (battery in listOf(false, true)) {
                                for (line in listOf(false, true)) {
                                    for (calls in listOf(false, true)) {
                                        for (signedIn in listOf(false, true)) {
                                            for (plan in listOf(
                                                false,
                                                true
                                            )) {
                                                add(
                                                    facts(caps(sdk, helper, mic, overlay, inBuild), battery, line, calls, signedIn, plan)
                                                )
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    @Test
    fun everyLineTheDeviceCheckCanSayHasItsWordsInEnglishAndUrdu() {
        val en = V2Testing.resources(EchoLanguage.English)
        val ur = V2Testing.resources(EchoLanguage.Urdu)
        val rows = everyFacts().flatMap { f -> DeviceCheck.rows(f).map { it to f } }.distinctBy { it.first }
        assertTrue(rows.size > 20)
        for ((row, f) in rows) {
            val brand = PhoneBrand.of(f.manufacturer, f.brand)
            // English: exactly the line the device check says (and the server and the report keep).
            assertEquals(row.detail, PhoneCheckWords.detailOrNull(en, row, brand, f.memoryGb))
            assertEquals(row.title, PhoneCheckWords.title(en, row))
            assertEquals(row.state.word, PhoneCheckWords.state(en, row.state))
            row.fix?.let { assertEquals(it.label, PhoneCheckWords.fix(en, it)) }
            // Urdu: words of its own for every one.
            val urdu = PhoneCheckWords.detailOrNull(ur, row, brand, f.memoryGb)
            assertTrue("no Urdu for: ${row.detail}", (urdu != null && urdu != row.detail) || row.key == "memory")
            assertTrue(row.key, PhoneCheckWords.title(ur, row) != row.title)
        }
        // The makers' names and steps, in both languages, one step for each of the maker's.
        for (brand in PhoneBrand.entries) {
            assertEquals(brand.label, PhoneCheckWords.brand(en, brand))
            assertEquals(brand.backgroundSteps, PhoneCheckWords.steps(en, brand))
            val steps = PhoneCheckWords.steps(ur, brand)
            assertEquals(brand.backgroundSteps.size, steps.size)
            steps.zip(brand.backgroundSteps).forEach { (u, e) -> assertTrue(u, u != e) }
        }
    }

    @Test
    fun theV2PageHasEveryControlOfTodaysPage() {
        page = 0
        showBoth()
        val today = mutableSetOf<String>()
        ParityKit.collect(compose, today)
        // Today's rows read this phone: the v2 page is shown the same phone's facts.
        shownFacts = app.phoneFacts(false, vm.settings.value)
        page = 1
        val v2 = mutableSetOf<String>()
        ParityKit.collect(compose, v2)
        val v2Rows = DeviceCheck.rows(shownFacts)
        val en = V2Testing.resources(EchoLanguage.English)
        // Today's fix buttons share their words ("Allow"); v2 names each with its row. The first of each is today's.
        val fixes = v2Rows.mapNotNull { row -> row.fix?.let { it.label to "button: ${it.label}: ${PhoneCheckWords.title(en, row)}" } }
            .groupBy({ it.first }, { it.second })
        val same = listOf(
            "button: Back",
            "button: Capture test",
            "button: Audio probe",
            "button: Copy diagnostics",
            "button: Open settings",
            "button: Copy report",
            "button: Send to my admin"
        ).filter { it in today }
        val where = same.associateWith { it } + fixes.mapKeys { "button: ${it.key}" }.mapValues { it.value.first() }
        val v2Only = fixes.values.flatMap {
            it.drop(1)
        }.associateWith { "today's fix buttons share one name; v2 names the row each one fixes" }
        val reasons = mapOf("button: Search settings" to "v2 Settings searches from its first page; Back leads there").filterKeys {
            it in
                today
        }
        assertEquals(emptyList<String>(), ParityKit.problems(today.associateWith { 0 }, v2.associateWith { 0 }, where, reasons, v2Only))
    }

    @Test
    fun eachFixAndActionDoesWhatClassicsDoes() {
        // A phone with the helper not running, no overlay permission and Android free to stop the app.
        shownFacts = facts(caps(sdk = 34, helper = false, overlay = false), battery = false)
        showBoth()
        press("Set up: App calls")
        assertEquals(1, setupOpened)
        press("Allow: Live Translate")
        assertEquals(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, shadowOf(app).nextStartedActivity.action)
        press("Allow: Staying ready")
        assertEquals(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS, shadowOf(app).nextStartedActivity.action)
        // The maker's own steps and its settings screen (else the app's own page).
        assertTrue(shown("Keep EchoLedger running on Samsung"))
        assertTrue(shown(PhoneBrand.SAMSUNG.backgroundSteps.first()))
        press("Open settings")
        assertTrue(shadowOf(app).nextStartedActivity != null)
        // Copy report: the English report the admin reads.
        press("Copy report")
        val clip = { app.getSystemService(ClipboardManager::class.java).primaryClip!!.getItemAt(0).text.toString() }
        assertEquals(DeviceCheck.report(shownFacts, DeviceCheck.rows(shownFacts)), clip())
        // The page says the report goes in English, as the admin reads it.
        assertTrue(shown("in English, as your admin reads it"))
        press("Copy diagnostics")
        pumpUntil("copied") { clip() == "EchoLedger diagnostics" }
        press("Send to my admin")
        pumpUntil("sent") { sent.size == 1 }
        assertEquals(DeviceCheck.rows(shownFacts), sent.single())
        press("Capture test")
        assertEquals(2, setupOpened)
        press("Audio probe")
        assertEquals(1, probed)
    }

    @Test
    fun inUrduThePageAndItsRowsSpeakUrdu() {
        language = EchoLanguage.Urdu
        shownFacts = facts(caps(sdk = 34, helper = false), battery = false, maker = "xiaomi")
        showBoth()
        val ur = V2Testing.resources(EchoLanguage.Urdu)
        pumpUntil("Urdu") {
            listOf("ڈیوائس کی جانچ", "مدد اور تشخیص", "ایپ کالز", "تیار رہنا", "رپورٹ کاپی کریں", "میرے ایڈمن کو بھیجیں").all(::shown)
        }
        DeviceCheck.rows(shownFacts).forEach { row ->
            val words = PhoneCheckWords.detail(ur, row, PhoneBrand.XIAOMI, shownFacts.memoryGb)
            assertTrue(words, shown(words.take(30)))
        }
        assertTrue(shown(PhoneCheckWords.steps(ur, PhoneBrand.XIAOMI).first().take(30)))
        assertTrue(shown("Xiaomi، Redmi یا POCO"))
        assertTrue(shown("انگریزی میں، جیسے آپ کا ایڈمن پڑھتا ہے"))
        assertTrue(compose.onAllNodes(hasContentDescription("سیٹ اپ کریں: ایپ کالز")).fetchSemanticsNodes().isNotEmpty())
    }
}
