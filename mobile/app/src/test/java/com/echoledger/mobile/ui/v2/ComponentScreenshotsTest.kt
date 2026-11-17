package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.foundation.layout.Box
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * Every v2 component in all its states, light and dark, left to right (English) and right to left (Urdu), at font
 * scale 1.0, 1.3 and 2.0. Recorded to docs/v2/gate3/phone/components/ (`-Proborazzi.test.record=true`); a plain run
 * renders every variant, so a crash or a layout exception in any of them fails the test.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-hdpi", application = Application::class)
class ComponentScreenshotsTest {
    @get:Rule
    val compose = createComposeRule()

    private val outDir =
        File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone/components")

    private data class Variant(val name: String, val dark: Boolean, val language: EchoLanguage, val fontScale: Float)

    private val variants = listOf(
        Variant("light-en", false, EchoLanguage.English, 1f),
        Variant("dark-en", true, EchoLanguage.English, 1f),
        Variant("light-ur", false, EchoLanguage.Urdu, 1f),
        Variant("dark-ur", true, EchoLanguage.Urdu, 1f),
        Variant("light-en-font130", false, EchoLanguage.English, 1.3f),
        Variant("light-en-font200", false, EchoLanguage.English, 2f),
        Variant("dark-ur-font200", true, EchoLanguage.Urdu, 2f)
    )

    private var variant by mutableStateOf(variants.first())
    private var gallery by mutableStateOf(ComponentGalleries.all.keys.first())

    @Test
    fun everyComponentInEveryVariant() {
        compose.setContent {
            val v = variant
            val density = LocalDensity.current
            CompositionLocalProvider(LocalDensity provides Density(density.density, v.fontScale)) {
                EchoV2Theme(darkTheme = v.dark, language = v.language) {
                    Box(Modifier.testTag("gallery")) { ComponentGalleries.all.getValue(gallery)(v.language == EchoLanguage.Urdu) }
                }
            }
        }
        for (name in ComponentGalleries.all.keys) {
            for (v in variants) {
                gallery = name
                variant = v
                compose.waitForIdle()
                compose.onNodeWithTag("gallery").captureRoboImage(File(outDir, "$name-${v.name}.png"))
            }
        }
    }
}
