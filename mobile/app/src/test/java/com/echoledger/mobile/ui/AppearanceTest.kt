package com.echoledger.mobile.ui

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.core.SettingsCatalogue
import com.echoledger.mobile.core.SettingsGroup
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.testsupport.ScreenTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.annotation.Config

/** Settings → General → Appearance (C4): the theme choice and the wallpaper colours switch. */
class AppearanceTest : ScreenTest() {
    private var settings by mutableStateOf(AppSettings())

    private fun showAppearance() = show { AppearanceSection(settings) { change -> settings = change(settings) } }

    @Test
    fun `the theme follows the phone until another one is chosen`() {
        showAppearance()
        compose.onNodeWithText("System").assertIsSelected()

        compose.onNodeWithText("Dark").performClick()
        assertEquals("dark", settings.themeMode)
        compose.onNodeWithText("Dark").assertIsSelected()

        compose.onNodeWithText("Light").performClick()
        assertEquals("light", settings.themeMode)
    }

    @Test
    @Config(sdk = [34])
    fun `wallpaper colours can be turned on from Android 12`() {
        showAppearance()
        assertFalse(settings.dynamicColour)
        compose.onNodeWithContentDescription("Wallpaper colours").performClick()
        assertTrue(settings.dynamicColour)
    }

    @Test
    @Config(sdk = [30])
    fun `wallpaper colours are not offered before Android 12`() {
        showAppearance()
        compose.onNodeWithText("Wallpaper colours").assertDoesNotExist()
    }

    @Test
    fun `appearance is the first General setting and search finds it`() {
        assertEquals("appearance", SettingsCatalogue.inGroup(SettingsGroup.General).first().id)
        assertEquals("appearance", SettingsCatalogue.search("dark theme").single().id)
    }

    @Test
    fun `settings saved before the option existed load with the defaults`() {
        val old = com.echoledger.mobile.data.ApiJson.decodeFromString(AppSettings.serializer(), """{"serverUrl":null}""")
        assertEquals("system", old.themeMode)
        assertFalse(old.dynamicColour)
    }
}
