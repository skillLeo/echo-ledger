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
    private fun barsAreLight(themeMode: String): Boolean {
        runBlocking { app.graph.settings.update { it.copy(themeMode = themeMode, onboardingDone = true) } }
        val activity = buildActivity(MainActivity::class.java).setup().get()
        repeat(5) { shadowOf(activity.mainLooper).idle() }
        val controller = WindowCompat.getInsetsController(activity.window, activity.window.decorView)
        return controller.isAppearanceLightStatusBars && controller.isAppearanceLightNavigationBars
    }

    @Test
    @Config(qualifiers = "notnight")
    fun `a dark app theme on a light phone draws light bar icons`() {
        assertFalse(barsAreLight("dark"))
    }

    @Test
    @Config(qualifiers = "notnight")
    fun `the light app theme draws dark bar icons`() {
        assertTrue(barsAreLight("light"))
    }

    @Test
    @Config(qualifiers = "night")
    fun `the system theme follows a dark phone`() {
        assertFalse(barsAreLight("system"))
    }
}
