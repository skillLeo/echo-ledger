package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.onRoot
import com.echoledger.mobile.data.LimitDto
import com.echoledger.mobile.data.StorageDto
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.simulateBiometrics
import com.echoledger.mobile.testsupport.simulateScreenLock
import com.echoledger.mobile.ui.LocalUnlockPrompter
import com.echoledger.mobile.ui.UnlockPrompter
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.AppLockPage
import com.echoledger.mobile.ui.v2.settings.PrivacyPage
import com.echoledger.mobile.ui.v2.settings.StoragePage
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/** App lock (on), Privacy & data and Storage in the v2 look, light and dark, English and Urdu, at the S25's size. */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi")
class PrivacyPagesShotsTest : ScreenTest() {
    private val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone/app")
    private var language by mutableStateOf(EchoLanguage.English)
    private var dark by mutableStateOf(false)
    private var which by mutableStateOf("lock")

    @Test
    fun screenshots() {
        app.simulateBiometrics(false)
        app.simulateScreenLock(true)
        vm.updateSettings { it.copy(appLockEnabled = true, lockAfterMs = 60_000L, consentAcknowledged = true) }
        pumpUntil("lock on") { vm.settings.value.appLockEnabled }
        val storage = StorageDto(recordingsBytes = 1_500_000_000, recordingsCount = 12, retentionDays = 30)
        val plan = LimitDto(limit = 10_000_000_000, used = 1_500_000_000)
        show {
            val settings by vm.settings.collectAsState()
            EchoV2Theme(darkTheme = dark, language = language) {
                CompositionLocalProvider(
                    LocalEchoToasts provides rememberEchoToastState(),
                    LocalUnlockPrompter provides UnlockPrompter { it(true) }
                ) {
                    when (which) {
                        "lock" -> AppLockPage(settings, vm::updateSettings, {}, null, {})
                        "privacy" -> PrivacyPage(settings, vm::updateSettings, null, {}, {})
                        else -> StoragePage({ storage }, plan, 48, null, {})
                    }
                }
            }
        }
        for (name in listOf("lock", "privacy", "storage")) {
            for ((l, d) in listOf(EchoLanguage.English to false, EchoLanguage.Urdu to false, EchoLanguage.English to true)) {
                which = name
                language = l
                dark = d
                compose.waitForIdle()
                compose.onRoot().captureRoboImage(
                    File(out, "settings-$name-${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}.png")
                )
            }
        }
    }
}
