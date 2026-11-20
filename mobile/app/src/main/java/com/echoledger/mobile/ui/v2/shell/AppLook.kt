package com.echoledger.mobile.ui.v2.shell

import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.ui.v2.theme.EchoLanguage

/** Settings › Appearance's text size and app language, as the new design applies them. */
object AppLook {
    /** The text sizes on offer, each on top of the phone's own size: `system` keeps the phone's. */
    val sizes = listOf("smaller", "system", "larger", "largest")

    fun scale(size: String): Float = when (size) {
        "smaller" -> 0.9f
        "larger" -> 1.15f
        "largest" -> 1.3f
        else -> 1f
    }

    /** The app's language: `en` or `ur` as chosen, else the phone's ([system]). */
    fun language(choice: String, system: EchoLanguage): EchoLanguage = when (choice) {
        "en" -> EchoLanguage.English
        "ur" -> EchoLanguage.Urdu
        else -> system
    }

    /** Draws [content] with the text [size] on top of the phone's font scale. */
    @Composable
    fun TextSize(size: String, content: @Composable () -> Unit) {
        val factor = scale(size)
        if (factor == 1f) {
            content()
            return
        }
        val density = LocalDensity.current
        CompositionLocalProvider(LocalDensity provides Density(density.density, density.fontScale * factor), content = content)
    }
}
