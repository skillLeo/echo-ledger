package com.echoledger.mobile.ui.v2

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.AppearancePage
import com.echoledger.mobile.ui.v2.shell.LocalAvatarColour
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.PersonAvatar
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import com.github.takahirom.roborazzi.captureScreenRoboImage
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * One language everywhere (BE-06) and DES-08 on the narrowest phone (360 dp), light and dark, English and Urdu:
 * Settings › Appearance signed in (its language is the account's: English or اردو, no "As on the phone"), and the
 * person's initials on each of the 8 avatar colours (never grey). Written to docs/design/screens/phone/account-language
 * when recording, otherwise only drawn.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h780dp-xhdpi")
class AccountLanguageShotsTest : ScreenTest() {
    private val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots"), "phone/account-language")
    private val record = System.getProperty("roborazzi.test.record") == "true"

    private var dark by mutableStateOf(false)
    private var language by mutableStateOf(EchoLanguage.English)
    private var round by mutableIntStateOf(0)

    private val variants = listOf(
        false to EchoLanguage.English,
        true to EchoLanguage.English,
        false to EchoLanguage.Urdu,
        true to EchoLanguage.Urdu
    )

    private fun shoot(name: String, step: () -> Unit = {}, content: @androidx.compose.runtime.Composable () -> Unit): List<String> {
        show {
            key(round) {
                EchoV2Theme(darkTheme = dark, language = language) {
                    CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) { content() }
                }
            }
        }
        val drawn = mutableListOf<String>()
        for ((d, l) in variants) {
            dark = d
            language = l
            round++
            compose.waitForIdle()
            step()
            compose.waitForIdle()
            val variant = "${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}"
            if (record) captureScreenRoboImage(File(out, "$name-$variant.png").path)
            drawn += variant
        }
        return drawn
    }

    private val all = listOf("light-en", "dark-en", "light-ur", "dark-ur")

    @Test
    fun appearanceSignedIn() = assertEquals(
        all,
        shoot(
            "appearance-signed-in",
            // The language's last choice in view: the whole section shows (English, اردو; no "As on the phone").
            step = { compose.onNode(androidx.compose.ui.test.hasText("اردو")).performScrollTo() }
        ) {
            val signedIn = AppSettings(
                serverUrl = "https://echoledger.test",
                deviceId = "d1",
                accountLanguage = if (language == EchoLanguage.Urdu) "ur" else "en"
            )
            AppearancePage(signedIn, {}, UiFlags(emptyMap()), onSwitchToClassic = {}, highlight = "appearance.language", onBack = {})
        }
    )

    @Test
    fun theAvatarColours() = assertEquals(
        all,
        shoot("avatars") {
            Column(
                Modifier.fillMaxSize().background(EchoV2.colors.bgCanvas).padding(EchoTokens.Space.s16),
                verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
            ) {
                androidx.compose.material3.Text("DES-08", style = EchoV2.type.label, color = EchoV2.colors.textSecondary)
                for (rowOf in listOf(0..3, 4..7)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)) {
                        for (i in rowOf) {
                            CompositionLocalProvider(LocalAvatarColour provides i) { PersonAvatar(listOf("SO", "AK", "علی", "BT")[i % 4]) }
                        }
                    }
                }
            }
        }
    )
}
