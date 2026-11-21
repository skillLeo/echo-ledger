package com.echoledger.mobile.ui

import androidx.core.view.WindowCompat
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.testsupport.AppTest
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config

/**
 * A6.M10: the status and navigation bar icons follow the app's theme. Before, the app was always dark, so with the
 * phone in its light theme the bars drew dark icons on the dark app background.
 */
class SystemBarsThemeTest : AppTest() {
    /**
     * Whether the bars draw dark-on-light icons once the saved theme is applied. The theme arrives with the settings, a
     * moment after the window shows the phone's own (P-103): it waits for [expected] (up to 5 s, the looper idled each
     * time) instead of a fixed number of idles, and returns what the bars show then, so a theme never applied fails.
     */
    private fun barsAreLight(themeMode: String, expected: Boolean): Boolean {
        runBlocking { app.graph.settings.update { it.copy(themeMode = themeMode, onboardingDone = true) } }
        val activity = buildActivity(MainActivity::class.java).setup().get()
        val controller = WindowCompat.getInsetsController(activity.window, activity.window.decorView)
        val light = { controller.isAppearanceLightStatusBars && controller.isAppearanceLightNavigationBars }
        val until = System.nanoTime() + 5_000_000_000L
        while (true) {
            shadowOf(activity.mainLooper).idle()
            if (light() == expected || System.nanoTime() > until) return light()
            Thread.sleep(10)
        }
    }

    @Test
    @Config(qualifiers = "notnight")
    fun `a dark app theme on a light phone draws light bar icons`() {
        assertFalse(barsAreLight("dark", expected = false))
    }

    @Test
    @Config(qualifiers = "notnight")
    fun `the light app theme draws dark bar icons`() {
        assertTrue(barsAreLight("light", expected = true))
    }

    @Test
    @Config(qualifiers = "night")
    fun `the system theme follows a dark phone`() {
        assertFalse(barsAreLight("system", expected = false))
    }
}
