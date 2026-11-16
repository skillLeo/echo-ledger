package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onRoot
import com.echoledger.mobile.ui.v2.samples.SampleContent
import com.echoledger.mobile.ui.v2.samples.SampleLibraryScreen
import com.echoledger.mobile.ui.v2.samples.SampleRecordingScreen
import com.echoledger.mobile.ui.v2.samples.SampleSettingsScreen
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * The Gate 3 sample screens at the Galaxy S25's size (1080 x 2340 px, 420 dpi): Library, a recording and Settings, each
 * light and dark, in English and in Urdu. Recorded to docs/v2/gate3/phone/ (`-Proborazzi.test.record=true`).
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class Gate3ScreensTest {
    @get:Rule
    val compose = createComposeRule()

    private val outDir = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone")

    private var dark by mutableStateOf(false)
    private var language by mutableStateOf(EchoLanguage.English)

    private fun shootAll(name: String, screen: @Composable (SampleContent) -> Unit) {
        compose.setContent {
            EchoV2Theme(darkTheme = dark, language = language) { screen(SampleContent.of(language)) }
        }
        for (lang in EchoLanguage.entries) {
            for (isDark in listOf(false, true)) {
                language = lang
                dark = isDark
                compose.waitForIdle()
                val file = "$name-${if (isDark) "dark" else "light"}-${if (lang == EchoLanguage.Urdu) "ur" else "en"}.png"
                val bitmap = compose.onRoot().captureToImage().asAndroidBitmap()
                assertEquals("$file width", 1080, bitmap.width, 2f)
                compose.onRoot().captureRoboImage(File(outDir, file))
            }
        }
    }

    private fun assertEquals(message: String, expected: Int, actual: Int, tolerance: Float) =
        assertEquals(message, expected.toFloat(), actual.toFloat(), tolerance)

    @Test
    fun library() = shootAll("library") { SampleLibraryScreen(it) }

    @Test
    fun recording() = shootAll("recording") { SampleRecordingScreen(it) }

    @Test
    fun settings() = shootAll("settings") { SampleSettingsScreen(it) }
}
