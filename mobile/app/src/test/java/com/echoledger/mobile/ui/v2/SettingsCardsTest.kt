package com.echoledger.mobile.ui.v2

import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isHeading
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.performScrollToNode
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.text.font.FontWeight
import com.echoledger.mobile.R
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.GROUP_CARD_TAG
import com.echoledger.mobile.ui.v2.settings.SettingsGoal
import com.echoledger.mobile.ui.v2.settings.SettingsTree
import com.echoledger.mobile.ui.v2.settings.V2SettingsRoot
import com.echoledger.mobile.ui.v2.shell.ProfileState
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * v2.1 UX-16 (One UI's settings): each group's title is text only (14 sp medium, the secondary colour), a heading that
 * is never tapped, with no icon or line under it; its rows sit in one rounded group; no two rows share an icon (the mic
 * is Microphone & audio's alone); and a row's value is the real setting, never an instruction ("Tap to start").
 */
class SettingsCardsTest : ScreenTest() {
    private fun hub(settings: AppSettings = AppSettings()) = show {
        EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
            V2SettingsRoot(settings, ProfileState.Loading, false, {}, {}, { _, _ -> }, {}, {})
        }
    }

    private fun list() = compose.onNode(androidx.compose.ui.test.hasScrollToIndexAction())

    @Test
    fun eachGroupsTitleIsTextOnlyAHeadingAndItsRowsSitInOneRoundedGroup() {
        hub()
        val res = V2Testing.resources(EchoLanguage.English)
        for ((goal, pages) in SettingsTree.goals) {
            val title = res.getString(goal.title)
            list().performScrollToNode(hasText(title) and isHeading())
            val header = compose.onNode(hasText(title) and isHeading())
            assertTrue("$title is not tapped", !hasClickAction().matches(header.fetchSemanticsNode()))
            // 14 sp medium.
            val layouts = mutableListOf<TextLayoutResult>()
            header.fetchSemanticsNode().config[SemanticsActions.GetTextLayoutResult].action?.invoke(layouts)
            val style = layouts.single().layoutInput.style
            assertEquals(14f, style.fontSize.value, 0.01f)
            assertEquals(FontWeight(500), style.fontWeight)
            // No line under the title any more.
            assertTrue(compose.onAllNodesWithText(res.getString(goal.description)).fetchSemanticsNodes().isEmpty())
            // Its pages in a rounded group.
            for (page in pages) {
                val row = com.echoledger.mobile.ui.v2.settings.SettingsSearch.pageTitle(res, page)
                list().performScrollToNode(hasText(row, substring = true))
                compose.onNode(hasText(row, substring = true) and hasAnyAncestor(hasTestTag(GROUP_CARD_TAG))).assertExists()
            }
        }
    }

    @Test
    fun noTwoRowsOfSettingsShareAnIcon() {
        val icons = SettingsTree.goals.flatMap { (_, pages) -> pages.map { SettingsTree.pageIcons.getValue(it) } } +
            EchoIcons.SignOut + com.echoledger.mobile.ui.v2.settings.UsageMeterIcon
        val shared = icons.groupBy { it.name }.filterValues { it.size > 1 }.keys
        assertTrue("shared icons: $shared", shared.isEmpty())
        // The mic is Microphone & audio's alone.
        assertEquals(EchoIcons.Mic.name, SettingsTree.pageIcons.getValue("mic").name)
        assertEquals(SettingsGoal.entries.size, SettingsTree.goals.size)
    }

    @Test
    fun theVoiceRowShowsWhetherTheVoiceKeyboardIsOnNeverAnInstruction() {
        hub()
        list().performScrollToNode(hasText("Voice notes & dictation", substring = true))
        compose.onNode(hasText("Voice notes & dictation", substring = true) and hasText("Keyboard off", substring = true)).assertExists()
        assertTrue(compose.onAllNodesWithText("Tap to start", substring = true).fetchSemanticsNodes().isEmpty())
    }

    @Test
    fun withTheVoiceKeyboardTurnedOnTheRowSaysSo() {
        val keyboard = android.view.inputmethod.InputMethodInfo(
            app.packageName,
            com.echoledger.mobile.dictation.VoiceKeyboardService::class.java.name,
            app.getString(R.string.app_name),
            null
        )
        val keyboards = app.getSystemService(android.view.inputmethod.InputMethodManager::class.java)
        shadowOf(keyboards).setEnabledInputMethodInfoList(listOf(keyboard))
        hub()
        list().performScrollToNode(hasText("Voice notes & dictation", substring = true))
        compose.onNode(hasText("Voice notes & dictation", substring = true) and hasText("Keyboard on", substring = true)).assertExists()
    }
}
