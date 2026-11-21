package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.core.SettingsIndex
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.ui.v2.settings.AppearancePage
import com.echoledger.mobile.ui.v2.settings.SettingsSearch
import com.echoledger.mobile.ui.v2.settings.SettingsTree
import com.echoledger.mobile.ui.v2.settings.V2SettingsRoot
import com.echoledger.mobile.ui.v2.shell.ProfileState
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** Settings v2: one home per feature, search down to each setting in English, Roman Urdu and Urdu, and jump-to. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi", application = Application::class)
class V2SettingsTest {
    @get:Rule
    val compose = createComposeRule()

    private val en = V2Testing.resources(EchoLanguage.English)
    private val ur = V2Testing.resources(EchoLanguage.Urdu)

    @Test
    fun everyPageHasExactlyOneHomeAndEverySettingIsReachable() {
        val placed = SettingsTree.goals.flatMap { it.second }
        assertEquals("a page in two goals", placed.size, placed.toSet().size)
        assertTrue(SettingsIndex.pages.map { it.id }.all { it in placed })
        for (item in SettingsSearch.items()) assertTrue(item.id, SettingsTree.goalOf(item.page) != null)
        // Every setting has words in both languages.
        for (item in SettingsSearch.items()) assertTrue(item.id, item.id in SettingsTree.itemTitles)
        for (page in placed) assertTrue(page, page in SettingsTree.pageTitles)
    }

    @Test
    fun searchReachesEachSettingInEnglishRomanUrduAndUrdu() {
        assertEquals("appearance.theme", SettingsSearch.search(en, "theme").first().item.id)
        assertTrue(SettingsSearch.search(en, "andhera").any { it.item.id == "appearance.theme" })
        assertTrue(SettingsSearch.search(ur, "تھیم").any { it.item.id == "appearance.theme" })
        assertEquals("Appearance", SettingsSearch.search(en, "theme").first().path)
        assertEquals("Account & plan › Plan & usage", SettingsSearch.search(en, "promo").first().path)
        assertTrue(SettingsSearch.search(en, "shizuku").any { it.item.id == "setup.helper" })
        assertTrue(SettingsSearch.search(en, "   ").isEmpty())
    }

    @Test
    fun wallpaperColoursTextSizeAndAppLanguageAreInAppearanceAndFound() {
        // Wallpaper colours came back (docs/PLAN_V2_PARITY.md section 5), with Text size and App language beside it.
        for ((id, words) in listOf(
            "appearance.wallpaper" to "wallpaper",
            "appearance.textsize" to "text size",
            "appearance.language" to "language"
        )) {
            assertTrue(id, SettingsSearch.items().any { it.id == id && it.page == "appearance" })
            assertTrue(id, SettingsSearch.search(en, words).any { it.item.id == id })
        }
    }

    @Test
    fun aSearchResultJumpsToItsPageAndSetting() {
        var opened: Pair<String, String?>? = null
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                V2SettingsRoot(AppSettings(), ProfileState.Loading, true, {}, {}, { p, h -> opened = p to h }, {}, {})
            }
        }
        compose.onNodeWithText("Search settings").performTextInput("quiet")
        compose.onNodeWithText("Quiet hours").performClick()
        assertEquals("notifications" to "notifications.quiet", opened)
    }

    @Test
    fun searchLandsOnEachCaptionAndDictationSettingAndLightsIt() {
        // Each caption and dictation setting is found by its words and opens its page at that setting.
        for ((words, id) in listOf(
            "opacity" to "captions.opacity",
            "caption position" to "captions.position",
            "compact" to "captions.compact",
            "caption colour" to "captions.colours",
            "hold to talk" to "voice.mic",
            "snippets" to "voice.snippets",
            "replacements" to "voice.replacements",
            "recent dictations" to "voice.history"
        )) {
            val hit = SettingsSearch.search(en, words).firstOrNull { it.item.id == id }
            assertTrue("\"$words\" finds $id", hit != null)
        }
        var opened: Pair<String, String?>? = null
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                V2SettingsRoot(AppSettings(), ProfileState.Loading, true, {}, {}, { p, h -> opened = p to h }, {}, {})
            }
        }
        compose.onNodeWithText("Search settings").performTextInput("opacity")
        compose.onNodeWithText("Background opacity").performClick()
        assertEquals("captions" to "captions.opacity", opened)
    }

    @Test
    fun aSearchResultLightsItsSettingOnEveryV2DrawnPage() {
        // Search opens a page with the setting's id (aSearchResultJumpsToItsPageAndSetting); each v2-drawn page must
        // light that id, not an older entry name.
        val sources = java.io.File("src/main/java/com/echoledger/mobile/ui/v2/settings").listFiles()!!.joinToString("\n") { it.readText() }
        val lit = Regex("highlight == \"([^\"]+)\"").findAll(sources).map { it.groupValues[1] }.toSet()
        val ids = SettingsSearch.items().filter { it.page in SettingsTree.nativePages && it.page != SettingsTree.SETUP }.map { it.id }
        assertEquals(emptyList<String>(), ids.filter { it !in lit })
        assertEquals(emptyList<String>(), lit.filter { id -> SettingsSearch.items().none { it.id == id } })
    }

    @Test
    fun theThemeChangesThroughTheExistingSettingsUpdate() {
        var settings by mutableStateOf(AppSettings())
        compose.setContent {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                val toasts = com.echoledger.mobile.ui.v2.components.rememberEchoToastState()
                androidx.compose.runtime.CompositionLocalProvider(com.echoledger.mobile.ui.v2.shell.LocalEchoToasts provides toasts) {
                    AppearancePage(settings, { t -> settings = t(settings) }, UiFlags.Off, {}, "appearance.theme", {})
                }
            }
        }
        compose.onNodeWithText("Dark").performClick()
        assertEquals("dark", settings.themeMode)
    }
}
