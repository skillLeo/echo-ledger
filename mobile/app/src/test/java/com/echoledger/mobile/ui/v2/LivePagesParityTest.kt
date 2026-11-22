package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.translate.BoxStyle
import com.echoledger.mobile.translate.GamePrefs
import com.echoledger.mobile.translate.InstalledApp
import com.echoledger.mobile.translate.LiveTranslatePrefs
import com.echoledger.mobile.translate.SubtitleStyle
import com.echoledger.mobile.translate.TranslateMode
import com.echoledger.mobile.translate.TranslateUsage
import com.echoledger.mobile.translate.WatchPrefs
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.GamesPage
import com.echoledger.mobile.ui.v2.settings.VideosPage
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * Games and Videos on the v2 components against classic's pages (docs/v2/audit/parity.csv PH-SET-045 to 056): the same
 * controls; each one changes the same Live Translate setting (the games and apps picked in the sheet, Start with the
 * game, They speak, Translate to or Subtitles in, Speed, the box's and the subtitles' look, the privacy switches, the
 * monthly budget and Continue this month anyway, Use whatever is playing, Save subtitles); Reset puts them back and
 * keeps the picked games and apps; in Urdu too.
 */
class LivePagesParityTest : ScreenTest() {
    private var page by mutableIntStateOf(1)
    private var which by mutableStateOf("games")
    private var language = EchoLanguage.English
    private val apps = listOf(
        InstalledApp("com.tencent.ig", "PUBG MOBILE", game = true),
        InstalledApp("com.activision.callofduty.shooter", "Call of Duty", game = true),
        InstalledApp("org.videolan.vlc", "VLC", game = false)
    )

    private fun showBoth() = show {
        if (page == 0) {
            SettingsScreen(vm, onSetup = {}, openPage = which)
        } else {
            EchoV2Theme(darkTheme = false, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    val s by vm.settings.collectAsState()
                    if (which == "games") {
                        GamesPage(s, vm::updateSettings, highlight = null, onBack = {}, installed = { apps })
                    } else {
                        VideosPage(s, vm::updateSettings, highlight = null, onBack = {}, installed = { apps })
                    }
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

    private fun toggle(name: String) {
        val node = compose.onAllNodes(hasText(name) and isToggleable()).onFirst()
        runCatching { node.performScrollTo() }
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    private fun slide(name: String, to: Float) {
        val node = compose.onNode(hasContentDescription(name) and SemanticsMatcher.keyIsDefined(SemanticsActions.SetProgress))
        runCatching { node.performScrollTo() }
        node.performSemanticsAction(SemanticsActions.SetProgress) { it(to) }
        compose.waitForIdle()
    }

    private fun shown(words: String) = compose.onAllNodesWithText(words).fetchSemanticsNodes().isNotEmpty()

    private fun lt() = vm.settings.value.liveTranslate

    private fun controls(): Pair<Set<String>, Set<String>> {
        page = 0
        showBoth()
        val today = mutableSetOf<String>()
        ParityKit.collect(compose, today)
        page = 1
        val v2 = mutableSetOf<String>()
        ParityKit.collect(compose, v2)
        return today to v2
    }

    private val spoken = listOf("Any language", "Arabic", "English", "Urdu", "Hindi", "Persian", "Turkish")
    private val targets = listOf("English", "Urdu", "Arabic", "Persian", "Turkish", "Russian", "Spanish", "French", "German") +
        listOf("Portuguese", "Indonesian", "Japanese", "Korean", "Chinese")

    /**
     * Today's two language chip rows share their names: a language both rows have maps to its They speak chip, one only
     * the second row has to its [target] chip; v2 names whose chip each is, so the second row's shared ones are v2's own.
     */
    private fun languages(target: String): Pair<Map<String, String>, Map<String, String>> {
        val where = spoken.associate { "radio: $it" to "radio: They speak: $it" } +
            (targets - spoken.toSet()).associate { "radio: $it" to "radio: $target: $it" }
        val v2Only = targets.filter { it in spoken }.associate {
            "radio: $target: $it" to "today's They speak and $target chips share their names; v2 names whose each one is"
        }
        return where to v2Only
    }

    private val reasons = mapOf("button: Search settings" to "v2 Settings searches from its first page; Back leads there")

    @Test
    fun theGamesPageHasEveryControlOfTodaysPage() {
        val (today, v2) = controls()
        val same = listOf(
            "button: Back",
            "button: Reset to defaults",
            "button: Choose games",
            "switch: Start with the game",
            "switch: Show what was said",
            "switch: Show the grip dot",
            "switch: Mask swear words",
            "switch: Save session text"
        )
        val (chips, v2Only) = languages("Translate to")
        val where = same.associateWith { it } + chips +
            listOf("Fastest", "Balanced", "Accurate").associate { "button: $it" to "radio: Speed: $it" } +
            listOf("1", "2", "3", "4").associate { "button: $it" to "radio: Lines: $it" } +
            listOf("Dark", "Light", "None").associate { "button: $it" to "radio: Background: $it" } +
            listOf("Never", "3 s", "6 s", "10 s").associate { "button: $it" to "radio: Fade out when quiet: $it" } +
            listOf("$5", "$10", "$20", "$50").associate { "radio: $it" to "radio: Monthly budget: $it" }
        assertEquals(emptyList<String>(), ParityKit.problems(today.associateWith { 0 }, v2.associateWith { 0 }, where, reasons, v2Only))
    }

    @Test
    fun theVideosPageHasEveryControlOfTodaysPage() {
        which = "videos"
        vm.updateSettings { it.copy(liveTranslate = it.liveTranslate.copy(watch = WatchPrefs(apps = listOf("org.videolan.vlc")))) }
        pumpUntil("an app") { lt().watch.apps.isNotEmpty() }
        val (today, v2) = controls()
        val same = listOf(
            "button: Back",
            "button: Reset to defaults",
            "button: Change apps",
            "button: Use whatever is playing",
            "switch: Black box behind the text",
            "switch: Show what was said",
            "switch: Save subtitles (.srt)"
        )
        val (chips, v2Only) = languages("Subtitles in")
        val where = same.associateWith { it } + chips +
            listOf("Fastest", "Balanced", "Accurate").associate { "button: $it" to "radio: Speed: $it" } +
            listOf("1", "2", "3").associate { "button: $it" to "radio: Lines: $it" }
        assertEquals(emptyList<String>(), ParityKit.problems(today.associateWith { 0 }, v2.associateWith { 0 }, where, reasons, v2Only))
    }

    @Test
    fun eachGamesControlChangesItsSettingAndResetKeepsThePickedGames() {
        vm.updateSettings { it.copy(liveTranslate = LiveTranslatePrefs()) }
        pumpUntil("defaults") { lt() == LiveTranslatePrefs() }
        showBoth()
        // Choose games: the sheet lists the phone's apps, games marked; Done saves the ticked ones.
        press("Choose games")
        pumpUntil("the apps") { shown("PUBG MOBILE") }
        toggle("PUBG MOBILE")
        toggle("Call of Duty")
        press("Done")
        pumpUntil("games picked") { lt().game.games == listOf("com.tencent.ig", "com.activision.callofduty.shooter") }
        pumpUntil("Change games") { shown("Change games") }
        toggle("Start with the game")
        pumpUntil("no auto start") { !lt().game.autoStart }
        press("They speak: Urdu")
        pumpUntil("urdu spoken") { lt().game.spoken == listOf("ar", "en", "ur") }
        press("They speak: Arabic")
        pumpUntil("no arabic") { lt().game.spoken == listOf("en", "ur") }
        press("They speak: Any language")
        pumpUntil("any") { lt().game.spoken.isEmpty() }
        press("Translate to: Urdu")
        pumpUntil("to urdu") { lt().game.target == "ur" }
        press("Speed: Accurate")
        pumpUntil("accurate") { lt().game.speed == "ACCURATE" }
        compose.onNode(hasContentDescription("Speed: Accurate")).assert(SemanticsMatcher.expectValue(SemanticsProperties.Selected, true))
        // The box.
        slide("Text size", 24f)
        pumpUntil("text size") { lt().game.style.textSp == 24 }
        press("Lines: 4")
        pumpUntil("lines") { lt().game.style.lines == 4 }
        press("Background: Light")
        pumpUntil("light") { lt().game.style.background == "LIGHT" }
        slide("Opacity", 0.5f)
        pumpUntil("opacity") { lt().game.style.opacity == 0.5f }
        toggle("Show what was said")
        pumpUntil("original") { lt().game.style.showOriginal }
        press("Fade out when quiet: 10 s")
        pumpUntil("fade") { lt().game.style.fadeAfterSeconds == 10 }
        toggle("Show the grip dot")
        pumpUntil("grip") { !lt().showGrip }
        // Privacy and the budget.
        toggle("Mask swear words")
        pumpUntil("mask") { lt().game.profanityMask }
        toggle("Save session text")
        pumpUntil("save") { lt().game.saveText }
        press("Monthly budget: $20")
        pumpUntil("budget") { lt().monthlyBudgetUsd == 20.0 }
        // Reset puts the page's choices back and keeps the picked games.
        press("Reset to defaults")
        compose.onAllNodes(hasText("Reset") and hasClickAction() and hasAnyAncestor(isDialog())).onLast()
            .performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("reset") { lt().game.style == BoxStyle() && lt().game.autoStart && lt().game.target == GamePrefs().target }
        assertEquals(listOf("com.tencent.ig", "com.activision.callofduty.shooter"), lt().game.games)
    }

    @Test
    fun overTheBudgetContinueThisMonthAnywayLetsItGoOn() {
        vm.updateSettings { it.copy(liveTranslate = LiveTranslatePrefs(monthlyBudgetUsd = 5.0)) }
        pumpUntil("five") { lt().monthlyBudgetUsd == 5.0 }
        val usage = TranslateUsage(app)
        // Fifty hours of Game translate is well past five dollars.
        usage.add(TranslateMode.GAME, 50L * 3_600_000)
        showBoth()
        pumpUntil("the button") { shown("Continue this month anyway") }
        press("Continue this month anyway")
        pumpUntil("overridden") { lt().budgetOverrideMonth == usage.month() }
        assertTrue(compose.onAllNodesWithText("Continue this month anyway").fetchSemanticsNodes().isEmpty())
    }

    private fun liveNotice(): String? = shadowOf(app.getSystemService(android.app.NotificationManager::class.java))
        .getNotification(com.echoledger.mobile.translate.LiveTranslateService.NOTIFICATION_PROBLEM_ID)
        ?.extras?.getCharSequence(android.app.Notification.EXTRA_TEXT)?.toString()

    private fun startGameTranslate() {
        app.getSystemService(android.app.NotificationManager::class.java).cancelAll()
        val intent = com.echoledger.mobile.translate.LiveTranslateService.startIntent(app, TranslateMode.GAME)
        buildService(com.echoledger.mobile.translate.LiveTranslateService::class.java, intent).create().startCommand(0, 1)
        pumpUntil("Game translate to decide") { liveNotice() != null }
    }

    @Test
    fun continueThisMonthAnywayLetsGameTranslateStartOverTheBudget() {
        vm.updateSettings { it.copy(liveTranslate = LiveTranslatePrefs(monthlyBudgetUsd = 5.0)) }
        pumpUntil("five") { lt().monthlyBudgetUsd == 5.0 && lt().budgetOverrideMonth == null }
        TranslateUsage(app).add(TranslateMode.GAME, 50L * 3_600_000)
        // Over the budget, Game translate pauses and says where to continue.
        startGameTranslate()
        assertTrue(liveNotice(), liveNotice()!!.startsWith("Live Translate is paused"))
        com.echoledger.mobile.translate.LiveTranslateService.stop(app)
        // Continue this month anyway on the v2 page, and it goes on past the budget (to its next step: the games).
        showBoth()
        press("Continue this month anyway")
        pumpUntil("overridden") { lt().budgetOverrideMonth == TranslateUsage(app).month() }
        startGameTranslate()
        assertFalse(liveNotice(), liveNotice()!!.startsWith("Live Translate is paused"))
        assertEquals("Choose your games in Game translate first.", liveNotice())
        com.echoledger.mobile.translate.LiveTranslateService.stop(app)
    }

    @Test
    fun eachVideosControlChangesItsSettingAndResetKeepsThePickedApps() {
        which = "videos"
        vm.updateSettings { it.copy(liveTranslate = LiveTranslatePrefs()) }
        pumpUntil("defaults") { lt() == LiveTranslatePrefs() }
        showBoth()
        assertTrue(shown("Whatever is playing."))
        press("Choose apps")
        pumpUntil("the apps") { shown("VLC") }
        toggle("VLC")
        press("Done")
        pumpUntil("vlc") { lt().watch.apps == listOf("org.videolan.vlc") }
        press("They speak: Hindi")
        pumpUntil("hindi") { lt().watch.spoken == listOf("hi") }
        press("Subtitles in: English")
        pumpUntil("english") { lt().watch.target == "en" }
        press("Speed: Fastest")
        pumpUntil("fastest") { lt().watch.speed == "FASTEST" }
        slide("Text size", 28f)
        pumpUntil("size") { lt().watch.style.textSp == 28 }
        press("Lines: 3")
        pumpUntil("lines") { lt().watch.style.lines == 3 }
        toggle("Black box behind the text")
        pumpUntil("box") { lt().watch.style.box }
        slide("Height above the bottom", 160f)
        pumpUntil("height") { lt().watch.style.offsetDp in 150..170 }
        toggle("Show what was said")
        pumpUntil("original") { lt().watch.style.showOriginal }
        toggle("Save subtitles (.srt)")
        pumpUntil("srt") { lt().watch.saveSrt }
        press("Reset to defaults")
        compose.onAllNodes(hasText("Reset") and hasClickAction() and hasAnyAncestor(isDialog())).onLast()
            .performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("reset") { lt().watch.style == SubtitleStyle() && lt().watch.target == WatchPrefs().target }
        assertEquals(listOf("org.videolan.vlc"), lt().watch.apps)
        // Use whatever is playing clears the apps.
        press("Use whatever is playing")
        pumpUntil("whatever") { lt().watch.apps.isEmpty() }
        pumpUntil("said") { shown("Whatever is playing.") }
    }

    @Test
    fun inUrduThePagesSpeakUrdu() {
        language = EchoLanguage.Urdu
        showBoth()
        pumpUntil("games in Urdu") { listOf("گیمز چنیں", "وہ بولتے ہیں", "خانے کا انداز", "ماہانہ بجٹ", "عربی").all(::shown) }
        // "$1.00 of $10.00" reads in Urdu as the budget, then what was spent ("$10.00 میں سے $1.00").
        vm.updateSettings { it.copy(liveTranslate = LiveTranslatePrefs(monthlyBudgetUsd = 10.0)) }
        TranslateUsage(app).add(TranslateMode.GAME, (1.0 / com.echoledger.mobile.translate.TranslateCost.USD_PER_HOUR * 3_600_000).toLong())
        which = "videos"
        compose.waitForIdle()
        which = "games"
        compose.waitForIdle()
        pumpUntil("the budget in Urdu") {
            compose.onAllNodesWithText("اس مہینے: \$10.00 میں سے \$1.00", substring = true).fetchSemanticsNodes().isNotEmpty()
        }
        which = "videos"
        pumpUntil("videos in Urdu") { listOf("ایپس چنیں", "سب ٹائٹل کا انداز", "متن کے پیچھے کالا خانہ").all(::shown) }
    }
}
