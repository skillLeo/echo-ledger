package com.echoledger.mobile.ui.v2

import android.provider.Settings
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.LifecycleRegistry
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.simulateBiometrics
import com.echoledger.mobile.testsupport.simulateScreenLock
import com.echoledger.mobile.ui.LocalUnlockPrompter
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.UnlockPrompter
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.AppLockPage
import com.echoledger.mobile.ui.v2.settings.PrivacyPage
import com.echoledger.mobile.ui.v2.settings.StoragePage
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * Privacy & security parity: today's App lock, Privacy & data and Storage pages and the v2 ones, each walked through the
 * same steps with their controls ("kind: name") collected after every step (ParityKit). App lock's walk is the whole
 * life of the lock: no screen lock (Open security settings, where the phone's PIN is set), coming back with one, turning
 * the lock on with the unlock refused and then given, Lock after, Hide content, the PIN removed and set again in the
 * phone's settings, turning the lock off (no unlock asked), and Reset to defaults asked and confirmed; there each
 * control must be on screen after exactly the same steps on both pages, shown and gone alike.
 */
class PrivacyPagesParityTest : ScreenTest() {
    private var page by mutableIntStateOf(0)

    /** The activity's lifecycle as the pages see it, so coming back from the phone's settings can be played. */
    private val owner = object : LifecycleOwner {
        val registry = LifecycleRegistry(this)
        override val lifecycle: Lifecycle get() = registry
    }

    /** The unlock prompt: each one counted and answered with [answer]. */
    private var answer = false
    private var prompts = 0
    private val prompter = UnlockPrompter { done ->
        prompts++
        done(answer)
    }

    @Before
    fun resumed() {
        owner.registry.currentState = Lifecycle.State.RESUMED
    }

    private fun press(text: String) {
        val node = compose.onAllNodes((hasText(text) or hasContentDescription(text)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    /** Flips the switch named [names] (today's switch is named by the words before it, v2's by its label). */
    private fun toggle(vararg names: String) {
        val all = compose.onAllNodes(isToggleable(), useUnmergedTree = true)
        val i = all.fetchSemanticsNodes().indexOfFirst { ParityKit.nameOf(it) in names }
        assertTrue("no switch ${names.toList()}", i >= 0)
        runCatching { all[i].performScrollTo() }
        all[i].performClick()
        compose.waitForIdle()
    }

    /** Away to the phone's settings and back (the pages check the screen lock again on resume). */
    private fun comeBack() {
        owner.registry.handleLifecycleEvent(Lifecycle.Event.ON_PAUSE)
        owner.registry.handleLifecycleEvent(Lifecycle.Event.ON_RESUME)
        compose.waitForIdle()
    }

    private fun showBoth(today: @Composable () -> Unit, v2: @Composable () -> Unit) = show {
        CompositionLocalProvider(LocalLifecycleOwner provides owner, LocalUnlockPrompter provides prompter) {
            if (page == 0) {
                today()
            } else {
                EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                    CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) { v2() }
                }
            }
        }
    }

    private val search = mapOf("button: Search settings" to "v2 Settings searches from its first page; Back leads there")

    private enum class LockStep {
        NoScreenLock,
        ScreenLockSet,
        Refused,
        TurnedOn,
        OneMinute,
        HideContent,
        ScreenLockRemoved,
        ScreenLockBack,
        TurnedOff,
        Reset,
        ResetDone
    }

    private fun lockStep(step: LockStep) {
        val s = { vm.settings.value }
        when (step) {
            LockStep.NoScreenLock -> Unit

            LockStep.ScreenLockSet -> {
                // Open security settings goes to the phone's own (where its PIN, pattern or password is set).
                press("Open security settings")
                assertEquals(Settings.ACTION_SECURITY_SETTINGS, shadowOf(app).nextStartedActivity?.action)
                app.simulateScreenLock(true)
                comeBack()
            }

            LockStep.Refused -> {
                answer = false
                toggle(*LOCK)
                assertEquals(1, prompts)
                assertFalse(s().appLockEnabled)
            }

            LockStep.TurnedOn -> {
                answer = true
                toggle(*LOCK)
                assertEquals(2, prompts)
                pumpUntil("on") { s().appLockEnabled }
            }

            LockStep.OneMinute -> {
                press("After 1 minute")
                pumpUntil("one minute") { s().lockAfterMs == 60_000L }
            }

            LockStep.HideContent -> {
                toggle("Hide content in Recents and screenshots")
                pumpUntil("hidden") { s().secureScreen }
            }

            LockStep.ScreenLockRemoved -> {
                app.simulateScreenLock(false)
                comeBack()
            }

            LockStep.ScreenLockBack -> {
                app.simulateScreenLock(true)
                comeBack()
            }

            LockStep.TurnedOff -> {
                toggle(*LOCK)
                pumpUntil("off") { !s().appLockEnabled }
                assertEquals("turning the lock off asks for no unlock (as today)", 2, prompts)
            }

            LockStep.Reset -> press("Reset to defaults")

            LockStep.ResetDone -> {
                press("Reset")
                pumpUntil("reset") { s().lockAfterMs == AppSettings().lockAfterMs && !s().secureScreen }
            }
        }
        compose.waitForIdle()
    }

    @Test
    fun appLock() {
        app.simulateBiometrics(false)
        app.simulateScreenLock(false)
        showBoth(
            today = { SettingsScreen(vm, onSetup = {}, openPage = "lock") },
            v2 = {
                val settings by vm.settings.collectAsState()
                AppLockPage(settings, vm::updateSettings, { vm.setWidgetHideTitles(it) }, highlight = null, onBack = {})
            }
        )
        val today = ParityKit.shownIn(compose, LockStep.entries, ::lockStep)
        // The v2 walk from the same start: no screen lock, the lock off, its choices at their defaults.
        app.simulateScreenLock(false)
        vm.updateSettings { it.copy(appLockEnabled = false, lockAfterMs = AppSettings().lockAfterMs, secureScreen = false) }
        pumpUntil("start again") { !vm.settings.value.appLockEnabled }
        prompts = 0
        page = 1
        comeBack()
        val v2 = ParityKit.shownIn(compose, LockStep.entries, ::lockStep)
        val where = mapOf(
            "button: Back" to "button: Back",
            "switch: $LOCK_TODAY" to "switch: App lock",
            "button: Open security settings" to "button: Open security settings",
            "radio: Immediately" to "radio: Immediately",
            "radio: After 1 minute" to "radio: After 1 minute",
            "radio: After 5 minutes" to "radio: After 5 minutes",
            "switch: Hide content in Recents and screenshots" to "switch: Hide content in Recents and screenshots",
            "switch: Hide recording titles on the widget" to "switch: Hide recording titles on the widget",
            "button: Reset to defaults" to "button: Reset to defaults",
            "button: Reset" to "button: Reset",
            "button: Cancel" to "button: Cancel"
        )
        val first = { m: Map<String, List<LockStep>> -> m.mapValues { it.value.first() } }
        assertEquals(emptyList<String>(), ParityKit.problems(first(today), first(v2), where, search))
        assertEquals(emptyList<String>(), ParityKit.presenceProblems(today, v2, where))
    }

    private enum class PrivacyStep { Open, Understood, Shown }

    @Test
    fun privacyAndData() {
        var opened: String? = null
        showBoth(
            today = { SettingsScreen(vm, onSetup = {}, openPage = "privacy") },
            v2 = {
                val settings by vm.settings.collectAsState()
                PrivacyPage(settings, vm::updateSettings, highlight = null, onOpenPage = { opened = it }, onBack = {})
            }
        )
        val run = { step: PrivacyStep ->
            when (step) {
                PrivacyStep.Open -> Unit

                PrivacyStep.Understood -> {
                    toggle("I understand")
                    pumpUntil("understood") { vm.settings.value.consentAcknowledged }
                }

                PrivacyStep.Shown -> {
                    press("Show")
                    pumpUntil("show") { vm.settings.value.screenShareMode == "show" }
                }
            }
            compose.waitForIdle()
        }
        val today = ParityKit.shownIn(compose, PrivacyStep.entries, run)
        val d = AppSettings()
        vm.updateSettings { it.copy(consentAcknowledged = d.consentAcknowledged, screenShareMode = d.screenShareMode) }
        pumpUntil("defaults") { vm.settings.value.screenShareMode == d.screenShareMode }
        page = 1
        compose.waitForIdle()
        val v2 = ParityKit.shownIn(compose, PrivacyStep.entries, run)
        val where = mapOf(
            "button: Back" to "button: Back",
            "switch: I understand" to "switch: I understand",
            "radio: Private (black box)" to "radio: Private (black box)",
            "radio: Show" to "radio: Show",
            "button: Export or delete my data" to "button: Export or delete my data"
        )
        val first = { m: Map<String, List<PrivacyStep>> -> m.mapValues { it.value.first() } }
        assertEquals(emptyList<String>(), ParityKit.problems(first(today), first(v2), where, search))
        assertEquals(emptyList<String>(), ParityKit.presenceProblems(today, v2, where))
        // Export or delete my data opens Sign-in & security, as today's button does.
        press("Export or delete my data")
        assertEquals("account", opened)
    }

    private enum class StorageStep { Failed, Loaded }

    @Test
    fun storage() {
        runBlocking { app.pairLocally(server) }
        // No answer at first (Retry), then the person's recordings.
        showBoth(
            today = { SettingsScreen(vm, onSetup = {}, openPage = "storage") },
            v2 = {
                val settings by vm.settings.collectAsState()
                StoragePage({ vm.storage() }, settings.plan.limits["storage_bytes"], settings.trashKeepHours, null, {})
            }
        )
        val run = { step: StorageStep ->
            when (step) {
                StorageStep.Failed -> compose.waitUntil(10_000) {
                    compose.onAllNodes(hasText("Retry") and hasClickAction()).fetchSemanticsNodes().isNotEmpty()
                }

                StorageStep.Loaded -> {
                    server.on("GET", "/api/storage", FakeServer.json(200, STORAGE))
                    press("Retry")
                    compose.waitUntil(10_000) {
                        compose.onAllNodes(hasText("in 12", substring = true)).fetchSemanticsNodes().isNotEmpty()
                    }
                }
            }
            compose.waitForIdle()
        }
        val today = ParityKit.shownIn(compose, StorageStep.entries, run)
        server.on("GET", "/api/storage", FakeServer.json(404, "{}"))
        page = 1
        compose.waitForIdle()
        val v2 = ParityKit.shownIn(compose, StorageStep.entries, run)
        val where = mapOf("button: Back" to "button: Back", "button: Retry" to "button: Retry")
        val first = { m: Map<String, List<StorageStep>> -> m.mapValues { it.value.first() } }
        assertEquals(emptyList<String>(), ParityKit.problems(first(today), first(v2), where, search))
        assertEquals(emptyList<String>(), ParityKit.presenceProblems(today, v2, where))
    }

    @Test
    fun storageSaysTrashTimeFromTheServer() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/storage", FakeServer.json(200, STORAGE))
        // The config's trash.keep_hours (V2WiringTest reads it into the settings): a month first.
        vm.updateSettings { it.copy(trashKeepHours = 720) }
        pumpUntil("a month") { vm.settings.value.trashKeepHours == 720 }
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    com.echoledger.mobile.ui.v2.settings.V2SettingsScreen(
                        vm,
                        com.echoledger.mobile.ui.v2.shell.UiFlags.Off,
                        {},
                        {},
                        { p, _ -> androidx.compose.material3.Text("classic $p") },
                        { _, _ -> },
                        {}
                    )
                }
            }
        }
        press("Storage")
        waitFor("Deleted recordings can be restored for 30 days.")
        compose.onNode(hasText("Recordings are deleted after 30 days unless kept.")).assertExists()
        vm.updateSettings { it.copy(trashKeepHours = 48) }
        waitFor("Deleted recordings can be restored for 48 hours.")
        // Privacy & data's Export or delete my data opens Your data (v2, under Sign-in & security) with the export lit.
        press("Back")
        press("Privacy & data")
        press("Export or delete my data")
        waitFor("Export my data")
        assertTrue(compose.onAllNodes(hasText("classic account")).fetchSemanticsNodes().isEmpty())
        val lit = androidx.compose.ui.test.SemanticsMatcher.expectValue(com.echoledger.mobile.ui.v2.settings.Highlighted, true)
        compose.onNode(lit and androidx.compose.ui.test.hasAnyDescendant(hasText("Export my data")), useUnmergedTree = true).assertExists()
    }

    private fun waitFor(words: String) = compose.waitUntil(10_000) { compose.onAllNodes(hasText(words)).fetchSemanticsNodes().isNotEmpty() }

    @Test
    fun everyReasonAppLockCantBeUsedHasItsOwnWords() {
        // Every REASON_ the lock's availability can give (AppLock.kt), found by name so a new one can't be missed.
        val reasons = Class.forName("com.echoledger.mobile.ui.AppLockKt").declaredFields
            .filter { it.name.startsWith("REASON_") }
            .map { field ->
                field.isAccessible = true
                field.get(null) as String
            }
        assertTrue(reasons.toString(), reasons.size >= 2)
        assertEquals(reasons.toSet(), com.echoledger.mobile.ui.v2.settings.LOCK_REASONS.keys)
        val words = com.echoledger.mobile.ui.v2.settings.LOCK_REASONS.values
        assertEquals("one string each", words.size, words.toSet().size)
        for (language in EchoLanguage.entries) {
            val res = V2Testing.resources(language)
            assertEquals(words.size, words.map(res::getString).toSet().size)
        }
    }

    @Test
    fun trashTimeInWords() {
        val res = V2Testing.resources(EchoLanguage.English)
        val words = { h: Int -> com.echoledger.mobile.ui.v2.library.keepWords(res, h) }
        assertEquals(listOf("1 hour", "24 hours", "48 hours", "3 days", "30 days", "50 hours"), listOf(1, 24, 48, 72, 720, 50).map(words))
        val ur = V2Testing.resources(EchoLanguage.Urdu)
        assertEquals("30 دن", com.echoledger.mobile.ui.v2.library.keepWords(ur, 720))
        assertEquals("48 گھنٹے", com.echoledger.mobile.ui.v2.library.keepWords(ur, 48))
    }

    private companion object {
        /** Today's App lock switch has no words of its own: it's named by the line before it. */
        const val LOCK_TODAY = "Ask for your fingerprint, face or screen lock when EchoLedger opens."
        val LOCK = arrayOf(LOCK_TODAY, "App lock")
        const val STORAGE = """{"recordings_bytes": 1500000000, "recordings_count": 12, "retention_days": 30}"""
    }
}
