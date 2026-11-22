package com.echoledger.mobile.widget

import androidx.glance.GlanceTheme
import androidx.glance.appwidget.testing.unit.runGlanceAppWidgetUnitTest
import androidx.glance.testing.unit.hasContentDescription
import androidx.glance.testing.unit.hasText
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ProgressDto
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.notify.AppWords
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Settings › Appearance's App language and Text size on the home-screen widget (docs/v2/audit/parity.csv PH-SET-060
 * and 061): its status, buttons, Ready dot and recent rows in Urdu when the app is, word for word as before in English,
 * its text at the person's size, and it follows a change at once.
 */
class WidgetLanguageTest : AppTest() {
    private val ur get() = AppWords.resources(app, "ur")
    private val en get() = AppWords.resources(app, "en")

    private fun render(
        state: WidgetState,
        language: String,
        check: androidx.glance.appwidget.testing.unit.GlanceAppWidgetUnitTest.() -> Unit
    ) = runGlanceAppWidgetUnitTest {
        setContext(app)
        setAppWidgetSize(RecordWidget.LARGE)
        val words = WidgetWords(AppWords.resources(app, language), state)
        provideComposable { WidgetLook(words, 1f) { GlanceTheme(colors = widgetColors(false)) { WidgetContent(state) } } }
        check()
    }

    @Test
    fun theWidgetsWordsAreInTheAppLanguage() {
        val armed = WidgetState(paired = true, armed = true, ready = "READY")
        render(armed, "ur") {
            onNode(hasText("کالز ریکارڈ کرنے کے لیے تیار")).assertExists()
            onNode(hasContentDescription("ریکارڈ کریں")).assertExists()
            onNode(hasContentDescription("کالز: تیار")).assertExists()
        }
        render(WidgetState(paired = true, recording = true, pendingDecisions = 2), "ur") {
            onNode(hasText("ریکارڈ ہو رہا ہے…")).assertExists()
            onNode(hasText("2 دیکھنے کے لیے")).assertExists()
            onNode(hasContentDescription("روکیں")).assertExists()
        }
        render(WidgetState(), "ur") { onNode(hasText("پیئر کرنے کے لیے EchoLedger کھولیں")).assertExists() }
        // English: the same words as the widget always said.
        val states = listOf(
            WidgetState(),
            WidgetState(paired = true),
            WidgetState(paired = true, armed = true, ready = "NOT_READY"),
            WidgetState(paired = true, recording = true, pendingDecisions = 1),
            WidgetState(paired = true, locked = true, ready = "OFF"),
            WidgetState(paired = true, pendingDecisions = 250)
        )
        for (state in states) {
            val words = WidgetWords(en, state)
            assertEquals(state.statusLine, words.statusLine)
            assertEquals(state.buttonLabel, words.buttonLabel)
            assertEquals(state.readyLabel, words.readyLabel)
            assertEquals(state.reviewLine, words.reviewLine)
        }
    }

    @Test
    fun theRecentRowsSayTheirAppProgressAndProblemInTheAppLanguage() {
        val items = listOf(
            LibraryItem(
                id = "b",
                title = null,
                sourceApp = "whatsapp",
                startedAt = "2026-11-09T10:00:00Z",
                status = "processing",
                transcript = TranscriptSummary(finalStatus = "processing", finalProgress = ProgressDto(1, 3)),
                updatedAt = "2026-11-09T10:00:00Z"
            ),
            LibraryItem(
                id = "d",
                title = "Broken",
                sourceApp = "voice_note",
                startedAt = "2026-11-07T09:00:00Z",
                status = "failed",
                transcript = TranscriptSummary(finalStatus = "failed"),
                updatedAt = "2026-11-07T09:00:00Z"
            )
        )
        val rows = WidgetStateStore.recentRows(items, hideTitles = false, today = java.time.LocalDate.of(2026, 11, 9), res = ur)
        assertEquals(ur.getString(com.echoledger.mobile.R.string.v2_source_whatsapp), rows[0].title)
        assertTrue(rows[0].line, rows[0].line.startsWith(ur.getString(com.echoledger.mobile.R.string.v2_transcribing)))
        assertEquals("ٹرانسکرپشن نہیں ہو سکی", rows[1].line)
        // Hide titles: the app's name, in Urdu too.
        val hidden = WidgetStateStore.recentRows(items, hideTitles = true, today = java.time.LocalDate.of(2026, 11, 9), res = ur)
        assertEquals(ur.getString(com.echoledger.mobile.R.string.v2_source_voice_note), hidden[1].title)
    }

    @Test
    fun theWidgetsTextFollowsTheTextSizeAndTheStateKeepsTheChoices() {
        assertEquals(12f * 1.3f, widgetTextSize(12, com.echoledger.mobile.ui.v2.shell.AppLook.scale("largest")), 0.01f)
        assertEquals(13f, widgetTextSize(13, com.echoledger.mobile.ui.v2.shell.AppLook.scale("system")), 0.01f)
        // Saving Urdu and Larger refreshes the widget with them (the app does it as it saves).
        runBlocking { app.graph.settings.update { it.copy(appLanguage = "ur", textSize = "larger") } }
        pumpUntil("refreshed") { WidgetStateStore.load(app).let { it.language == "ur" && it.textSize == "larger" } }
        runBlocking { app.graph.settings.update { it.copy(appLanguage = "en", textSize = "system") } }
        pumpUntil("again") { WidgetStateStore.load(app).let { it.language == "en" && it.textSize == "system" } }
    }
}
