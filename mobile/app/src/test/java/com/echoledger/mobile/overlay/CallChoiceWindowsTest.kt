package com.echoledger.mobile.overlay

import android.os.Looper
import android.view.Gravity
import android.view.View
import android.view.WindowManager
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.storeRecording
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config
import org.robolectric.shadow.api.Shadow
import org.robolectric.shadows.ShadowSettings
import org.robolectric.shadows.ShadowWindowManagerImpl

/**
 * Where the call choice's windows sit (the call-choice plan, 2.0.5): the after-call card at the bottom above the
 * navigation bar, inside the safe area, across the screen's width less its margins; the "Recording this call" banner
 * just under the status bar, well away from a call screen's answer and hang-up buttons at the bottom, also at the
 * largest text. Neither makes a sound.
 */
@Config(sdk = [34], qualifiers = "w360dp-h740dp-xhdpi")
class CallChoiceWindowsTest : AppTest() {
    private val wm by lazy { app.getSystemService(WindowManager::class.java) }

    @Before
    fun allowOverlays() = ShadowSettings.setCanDrawOverlays(true)

    @After
    fun close() {
        DecisionPopup.hide()
        StartBanner.hide()
    }

    private fun window(kind: FloatingWindow): View = Shadow.extract<ShadowWindowManagerImpl>(wm).views.single {
        (it.layoutParams as? WindowManager.LayoutParams)?.title == kind.title
    }

    private fun params(view: View) = view.layoutParams as WindowManager.LayoutParams

    private fun idle() = shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofMillis(300))

    private fun dimen(name: String): Int {
        val id = app.resources.getIdentifier(name, "dimen", "android")
        return if (id != 0) app.resources.getDimensionPixelSize(id) else 0
    }

    @Test
    fun `the card sits at the bottom above the navigation bar and makes no sound`() {
        val rec = app.storeRecording(id = "call-1", state = "pending_decision", sourceApp = "whatsapp", durationMs = 44_000)
        assertTrue(DecisionPopup.ask(app, rec, "light"))
        val card = window(FloatingWindow.AFTER_CALL_CARD)
        val p = params(card)
        assertEquals(Gravity.BOTTOM, p.gravity and Gravity.VERTICAL_GRAVITY_MASK)
        assertTrue("above the navigation bar (${p.y} px)", p.y >= dimen("navigation_bar_height"))
        assertEquals("the whole width, less the card's own margins", WindowManager.LayoutParams.MATCH_PARENT, p.width)
        assertFalse(card.isSoundEffectsEnabled)
        assertFalse(card.isHapticFeedbackEnabled)
        assertTrue("never takes focus", p.flags and WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE != 0)
    }

    @Test
    fun `the start banner sits under the status bar, far from the call buttons, at the largest text too`() {
        for (scale in listOf(1f, 2f)) {
            app.resources.configuration.fontScale = scale
            assertTrue(StartBanner.show(app, "dark") {})
            idle()
            val banner = window(FloatingWindow.START_BANNER)
            val p = params(banner)
            assertEquals(Gravity.TOP, p.gravity and Gravity.VERTICAL_GRAVITY_MASK)
            assertTrue("under the status bar", p.y >= dimen("status_bar_height"))
            val screen = app.resources.displayMetrics.heightPixels
            // A call screen's answer and hang-up buttons are in its lower half; the banner stays in the top quarter.
            assertTrue("at ${scale}x it ends at ${p.y + banner.height} of $screen px", p.y + banner.height < screen / 4)
            assertFalse(banner.isSoundEffectsEnabled)
            assertTrue(p.flags and WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE != 0)
            StartBanner.hide()
        }
        app.resources.configuration.fontScale = 1f
    }

    @Test
    fun `the start banner goes by itself after five seconds and Don't record runs once`() {
        var dontRecord = 0
        assertTrue(StartBanner.show(app, "dark") { dontRecord++ })
        shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofMillis(StartBanner.SHOW_MS - 200))
        assertTrue(StartBanner.isShowing)
        shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofMillis(400))
        assertFalse(StartBanner.isShowing)
        assertEquals("doing nothing records the call", 0, dontRecord)
    }
}
