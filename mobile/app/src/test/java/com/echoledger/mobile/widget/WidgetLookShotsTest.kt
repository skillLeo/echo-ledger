package com.echoledger.mobile.widget

import android.view.View
import android.view.ViewGroup
import android.widget.FrameLayout
import android.widget.TextView
import androidx.glance.GlanceTheme
import androidx.glance.appwidget.ExperimentalGlanceRemoteViewsApi
import androidx.glance.appwidget.GlanceRemoteViews
import com.echoledger.mobile.notify.AppWords
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.ui.v2.shell.AppLook
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * The home-screen widget drawn as Android draws it (its RemoteViews, docs/v2/audit/parity.csv PH-SET-060 and 061): at
 * Settings › Appearance's Largest text size its text is 1.3 times the phone's, and in Urdu it speaks Urdu; screenshots
 * of both (docs/design/screens/phone-widget-*.png).
 */
@OptIn(ExperimentalGlanceRemoteViewsApi::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi")
class WidgetLookShotsTest : AppTest() {
    private val out = File(System.getProperty("screenshots.dir") ?: "build/screenshots")

    /** Ready, with two recent recordings whose lines are in [language] (as the app writes them when it refreshes). */
    private fun state(language: String) = WidgetState(
        paired = true,
        armed = true,
        ready = "READY",
        recent = listOf(
            WidgetRow("a", "Weekly sync with Omar", "10:42"),
            WidgetRow("b", "WhatsApp", com.echoledger.mobile.ui.v2.shell.V2Text.from(AppWords.resources(app, language)).yesterday)
        )
    )

    /** The widget at 4 x 4 as the launcher shows it: its RemoteViews, applied to a real view. */
    private fun draw(language: String, textSize: String): View = runBlocking {
        val state = state(language)
        val words = WidgetWords(AppWords.resources(app, language), state)
        val result = GlanceRemoteViews().compose(app, RecordWidget.LARGE) {
            WidgetLook(words, AppLook.scale(textSize)) { GlanceTheme(colors = widgetColors(false)) { WidgetContent(state) } }
        }
        // Shown in an activity, as a screenshot needs a window.
        val activity = buildActivity(androidx.activity.ComponentActivity::class.java).setup().get()
        val parent = FrameLayout(activity)
        val view = result.remoteViews.apply(activity, parent)
        val px = (RecordWidget.LARGE.width.value * app.resources.displayMetrics.density).toInt()
        parent.addView(view, ViewGroup.LayoutParams(px, px))
        activity.setContentView(parent, ViewGroup.LayoutParams(px, px))
        parent.measure(
            View.MeasureSpec.makeMeasureSpec(px, View.MeasureSpec.EXACTLY),
            View.MeasureSpec.makeMeasureSpec(px, View.MeasureSpec.EXACTLY)
        )
        parent.layout(0, 0, px, px)
        parent
    }

    private fun texts(view: View): List<TextView> = when (view) {
        is TextView -> listOf(view)
        is ViewGroup -> (0 until view.childCount).flatMap { texts(view.getChildAt(it)) }
        else -> emptyList()
    }

    private fun sizeOf(view: View, words: String): Float = texts(view).first { it.text.toString() == words }.textSize

    /** Draws and shoots the widget in the light and the dark theme (the phone's, as the launcher shows it). */
    private fun shoot(language: String, textSize: String, name: String): View {
        org.robolectric.RuntimeEnvironment.setQualifiers("+night")
        draw(language, textSize).captureRoboImage(File(out, "phone-widget-$name-dark.png"))
        org.robolectric.RuntimeEnvironment.setQualifiers("+notnight")
        return draw(language, textSize).also { it.captureRoboImage(File(out, "phone-widget-$name-light.png")) }
    }

    @Test
    fun atLargestTheWidgetsTextIsLargerByTheSameShareAsTheApps() {
        val phone = draw("en", "system")
        val largest = shoot("en", "largest", "largest")
        for (words in listOf("Ready to record calls", "Weekly sync with Omar", "EchoLedger")) {
            assertEquals(words, sizeOf(phone, words) * 1.3f, sizeOf(largest, words), 0.6f)
        }
    }

    @Test
    fun inUrduTheWidgetSpeaksUrduLinedUpAtTheEnd() {
        val urdu = shoot("ur", "system", "ur")
        val all = texts(urdu)
        val status = all.first { it.text.toString() == "کالز ریکارڈ کرنے کے لیے تیار" }
        // Lined up at the end (the launcher lays the widget out left to right on an English phone: the right edge).
        val end = status.gravity and android.view.Gravity.RELATIVE_HORIZONTAL_GRAVITY_MASK
        assertTrue("gravity ${status.gravity}", end == android.view.Gravity.END || end == android.view.Gravity.RIGHT)
        assertTrue(status.width > status.layout.getLineWidth(0))
        // In English, at the start as before.
        val english = texts(draw("en", "system")).first { it.text.toString() == "Ready to record calls" }
        val start = english.gravity and android.view.Gravity.RELATIVE_HORIZONTAL_GRAVITY_MASK
        assertTrue("gravity ${english.gravity}", start != android.view.Gravity.END && start != android.view.Gravity.RIGHT)
    }
}
