package com.echoledger.mobile.widget

import androidx.glance.GlanceTheme
import androidx.glance.appwidget.testing.unit.runGlanceAppWidgetUnitTest
import androidx.glance.testing.unit.hasContentDescription
import androidx.glance.testing.unit.hasText
import androidx.test.core.app.ApplicationProvider
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** C4: the home-screen widget in its sizes (2 x 1, 2 x 2, 4 x 2, and 4 x 4 since v1.6). */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class RecordWidgetLayoutTest {
    private val paired = WidgetState(paired = true, lastTitle = "Weekly sync", lastPreview = "Thanks for joining")

    private fun render(
        state: WidgetState,
        size: androidx.compose.ui.unit.DpSize,
        check: androidx.glance.appwidget.testing.unit.GlanceAppWidgetUnitTest.() -> Unit
    ) = runGlanceAppWidgetUnitTest {
        setContext(ApplicationProvider.getApplicationContext())
        setAppWidgetSize(size)
        provideComposable { GlanceTheme(colors = widgetColors(state.dynamicColour)) { WidgetContent(state) } }
        check()
    }

    @Test
    fun `2 x 1 shows the record button and the status only`() = render(paired, RecordWidget.SMALL) {
        onNode(hasContentDescription("Record")).assertExists()
        onNode(hasText("Tap to record")).assertExists()
        onNode(hasText("Weekly sync")).assertDoesNotExist()
    }

    @Test
    fun `2 x 2 adds the app name and the review count while recording`() =
        render(paired.copy(recording = true, pendingDecisions = 2), RecordWidget.SQUARE) {
            onNode(hasText("EchoLedger")).assertExists()
            onNode(hasContentDescription("Stop")).assertExists()
            onNode(hasText("2 to review")).assertExists()
        }

    @Test
    fun `4 x 2 shows the last recording next to the button`() = render(paired, RecordWidget.WIDE) {
        onNode(hasText("Weekly sync")).assertExists()
        onNode(hasText("Thanks for joining")).assertExists()
        onNode(hasContentDescription("Record")).assertExists()
    }

    @Test
    fun `the lock hides titles and an unpaired widget has no button`() {
        render(paired.copy(locked = true), RecordWidget.WIDE) { onNode(hasText("Weekly sync")).assertDoesNotExist() }
        render(WidgetState(), RecordWidget.WIDE) {
            onNode(hasText("Open EchoLedger to pair")).assertExists()
            onNode(hasContentDescription("Record")).assertDoesNotExist()
        }
    }

    @Test
    fun `wallpaper colours are used only when the app uses them`() {
        render(paired.copy(dynamicColour = true), RecordWidget.SQUARE) { onNode(hasText("EchoLedger")).assertExists() }
        assert(widgetColors(true) !== widgetColors(false))
    }

    // ---- v1.6, Phase 1C I4 ---------------------------------------------------------------------------------------------

    private val rows = (1..7).map { WidgetRow("S$it", "Call $it", if (it == 1) "Transcribing · part 2 of 3" else "Today 10:0$it") }

    @Test
    fun `4 x 2 shows the last two recordings with their progress`() = render(paired.copy(recent = rows), RecordWidget.WIDE) {
        onNode(hasText("Call 1")).assertExists()
        onNode(hasText("Transcribing · part 2 of 3")).assertExists()
        onNode(hasText("Call 2")).assertExists()
        onNode(hasText("Call 3")).assertDoesNotExist()
        onNode(hasContentDescription("Record")).assertExists()
    }

    @Test
    fun `4 x 4 shows the last six, each one tap to open`() = render(paired.copy(recent = rows), RecordWidget.LARGE) {
        (1..6).forEach { onNode(hasText("Call $it")).assertExists() }
        onNode(hasText("Call 7")).assertDoesNotExist()
        onNode(hasText("Call 1")).assertExists()
        onNode(hasContentDescription("Record")).assertExists()
    }

    @Test
    fun `the Ready dot says whether calls are recorded, and not ready says to tap to fix`() {
        render(paired.copy(ready = "READY", armed = true), RecordWidget.SMALL) {
            onNode(hasContentDescription("Calls: Ready")).assertExists()
            onNode(hasText("Ready to record calls")).assertExists()
        }
        render(paired.copy(ready = "NOT_READY", armed = true), RecordWidget.SQUARE) {
            onNode(hasContentDescription("Calls: Not ready")).assertExists()
            onNode(hasText("Not ready: tap to fix")).assertExists()
        }
        render(paired.copy(ready = null), RecordWidget.SQUARE) {
            onNode(hasContentDescription("Calls: Ready")).assertDoesNotExist()
        }
    }

    @Test
    fun `App lock shows no recordings on the 4 x 4 either`() = render(paired.copy(recent = rows, locked = true), RecordWidget.LARGE) {
        onNode(hasText("Call 1")).assertDoesNotExist()
        onNode(hasText("Locked")).assertExists()
    }
}
