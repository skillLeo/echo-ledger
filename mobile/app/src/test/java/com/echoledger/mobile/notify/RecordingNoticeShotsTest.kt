package com.echoledger.mobile.notify

import android.view.View
import android.view.ViewGroup
import android.widget.FrameLayout
import android.widget.TextView
import com.echoledger.mobile.R
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotSame
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.RuntimeEnvironment
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * The recording notification's own view in Urdu at Settings › Appearance's Largest on a phone with a large font
 * (docs/v2/audit/parity.csv PH-SET-060 and 061): its buttons grow with their words instead of clipping them, light and
 * dark (docs/design/screens/phone-notification-recording-*.png); and the app's Urdu words follow a configuration change.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi")
class RecordingNoticeShotsTest : AppTest() {
    private val out = File(System.getProperty("screenshots.dir") ?: "build/screenshots")

    private fun largestUrdu() {
        runBlocking { app.graph.settings.update { it.copy(appLanguage = "ur", textSize = "largest") } }
        pumpUntil("Urdu at Largest") { app.graph.liveSettings.value?.let { it.appLanguage == "ur" && it.textSize == "largest" } == true }
    }

    /** The expanded recording view as the shade lays it out, the width of a phone's notification. */
    private fun drawn(): View {
        val n = Notifications.recording(app, "Recording call with Omar", "Both sides · auto · 2 bookmarks", discarding = true)
        val activity = buildActivity(androidx.activity.ComponentActivity::class.java).setup().get()
        val parent = FrameLayout(activity)
        val view = n.bigContentView.apply(activity, parent)
        val width = (360 * app.resources.displayMetrics.density).toInt()
        parent.addView(view, ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT))
        parent.setBackgroundColor(if (night()) 0xFF1F1F1F.toInt() else 0xFFFFFFFF.toInt())
        // The shade's own margin around a notification's content.
        val margin = (16 * app.resources.displayMetrics.density).toInt()
        parent.setPadding(margin, margin / 2, margin, margin / 2)
        // As tall as the view asks to be (the shade gives an expanded notification the height it needs).
        parent.measure(
            View.MeasureSpec.makeMeasureSpec(width, View.MeasureSpec.EXACTLY),
            View.MeasureSpec.makeMeasureSpec(0, View.MeasureSpec.UNSPECIFIED)
        )
        val height = parent.measuredHeight
        activity.setContentView(parent, ViewGroup.LayoutParams(width, height))
        parent.measure(
            View.MeasureSpec.makeMeasureSpec(width, View.MeasureSpec.EXACTLY),
            View.MeasureSpec.makeMeasureSpec(height, View.MeasureSpec.EXACTLY)
        )
        parent.layout(0, 0, width, height)
        return parent
    }

    private fun night() = app.resources.configuration.uiMode and android.content.res.Configuration.UI_MODE_NIGHT_MASK ==
        android.content.res.Configuration.UI_MODE_NIGHT_YES

    /** No button cuts its words: each is at least as tall as its laid-out lines, and every line is on screen. */
    private fun assertNothingClipped(view: View) {
        for (id in listOf(R.id.rec_pause, R.id.rec_stop, R.id.rec_captions, R.id.rec_discard)) {
            val button = view.findViewById<TextView>(id)
            val needed = button.layout.height + button.totalPaddingTop + button.totalPaddingBottom
            assertTrue("${button.text}: ${button.height} < $needed", button.height >= needed)
            assertTrue(button.text.toString(), (button.parent as View).height >= button.height)
            // And the whole button is inside the notification.
            val at = IntArray(2).also { button.getLocationInWindow(it) }
            val top = IntArray(2).also { view.getLocationInWindow(it) }
            assertTrue("${button.text} ends below the view", at[1] - top[1] + button.height <= view.height)
        }
    }

    @Test
    fun atLargestInUrduWithALargePhoneFontTheButtonsGrowAndNothingClips() {
        RuntimeEnvironment.setFontScale(1.3f)
        largestUrdu()
        for (dark in listOf(false, true)) {
            RuntimeEnvironment.setQualifiers(if (dark) "+night" else "+notnight")
            val view = drawn()
            assertEquals("${NoticeWords.RLM}واپس لیں", view.findViewById<TextView>(R.id.rec_discard).text.toString())
            assertNothingClipped(view)
            // Taller than the 48 dp the row used to be fixed at, as its words need.
            val row = view.findViewById<TextView>(R.id.rec_discard).parent as View
            assertTrue(row.height >= (48 * app.resources.displayMetrics.density).toInt())
            view.captureRoboImage(File(out, "phone-notification-recording-ur-largest-${if (dark) "dark" else "light"}.png"))
        }
    }

    @Test
    fun theUrduWordsFollowAConfigurationChange() {
        val first = AppWords.resources(app, "ur")
        // The same configuration: the same context, not a new one each time.
        assertSame(first, AppWords.resources(app, "ur"))
        // The phone's configuration changes (a font size, the dark theme): a new one, still in Urdu, with the change.
        RuntimeEnvironment.setFontScale(1.5f)
        RuntimeEnvironment.setQualifiers("+night")
        val after = AppWords.resources(app, "ur")
        assertNotSame(first, after)
        assertEquals("ur", after.configuration.locales[0]?.language)
        assertEquals("بند کریں", after.getString(R.string.w2_stop))
        assertEquals(1.5f, after.configuration.fontScale, 0.01f)
        assertTrue(
            after.configuration.uiMode and android.content.res.Configuration.UI_MODE_NIGHT_MASK ==
                android.content.res.Configuration.UI_MODE_NIGHT_YES
        )
    }

    private fun left(view: View, id: Int): Int = IntArray(2).also { view.findViewById<View>(id).getLocationInWindow(it) }[0]

    @Test
    fun inUrduOnAnEnglishPhonePauseIsOnTheRightAndInEnglishOnTheLeft() {
        largestUrdu()
        val urdu = drawn()
        assertTrue(left(urdu, R.id.rec_pause) > left(urdu, R.id.rec_discard))
        assertTrue(left(urdu, R.id.rec_stop) > left(urdu, R.id.rec_captions))
        runBlocking { app.graph.settings.update { it.copy(appLanguage = "en") } }
        pumpUntil("English") { app.graph.liveSettings.value?.appLanguage == "en" }
        val english = drawn()
        assertTrue(left(english, R.id.rec_pause) < left(english, R.id.rec_discard))
    }

    @Test
    fun aPhoneFontChangeReachesTheViewWithoutBuildingItAgain() {
        largestUrdu()
        val notification = Notifications.recording(app, "Recording call with Omar", "Microphone")
        fun titlePx(): Float {
            val view = notification.bigContentView.apply(app, FrameLayout(app))
            return view.findViewById<TextView>(R.id.rec_title).textSize
        }
        fun expected(): Float = android.util.TypedValue.applyDimension(
            android.util.TypedValue.COMPLEX_UNIT_SP,
            Notifications.recordingTextSp(app).getValue(R.id.rec_title) * 1.3f,
            app.resources.displayMetrics
        )
        val before = titlePx()
        assertEquals(expected(), before, 0.6f)
        // The phone's font goes up after the notification was posted: the same notification draws larger, as Android
        // draws that size at the new font (it scales large fonts non-linearly).
        RuntimeEnvironment.setFontScale(1.5f)
        val after = titlePx()
        assertTrue("$after > $before", after > before + 1f)
        assertEquals(expected(), after, 0.6f)
    }
}
