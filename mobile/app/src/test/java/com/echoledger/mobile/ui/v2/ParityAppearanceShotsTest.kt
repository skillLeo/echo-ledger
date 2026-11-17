package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.AppearancePage
import com.echoledger.mobile.ui.v2.shell.Design
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/** Settings › Appearance, classic | v2: "Try the new design" on the left, "Switch back to classic" on the right. */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi")
class ParityAppearanceShotsTest : ScreenTest() {
    @Test
    fun appearance() {
        val drawn = ParityShots(compose).shoot(
            "settings-appearance",
            classic = { SettingsScreen(vm, onSetup = {}, openPage = "appearance") },
            v2 = { dark, language ->
                val settings by vm.settings.collectAsState()
                EchoV2Theme(darkTheme = dark, language = language) {
                    CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                        AppearancePage(settings, vm::updateSettings, UiFlags(design = Design.V2), {}, null, {})
                    }
                }
            }
        )
        assertEquals(listOf("light-en", "light-ur", "dark-en", "dark-ur"), drawn)
    }
}
