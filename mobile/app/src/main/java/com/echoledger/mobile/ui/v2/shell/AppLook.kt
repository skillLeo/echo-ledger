package com.echoledger.mobile.ui.v2.shell

import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.Density
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import kotlinx.coroutines.flow.first

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

    /**
     * Draws [content] with the text [size] on top of the phone's font scale. The density is always provided (also at
     * the phone's own size), so changing the size keeps [content] where it is instead of building it again.
     */
    @Composable
    fun TextSize(size: String, content: @Composable () -> Unit) {
        val factor = scale(size)
        val density = LocalDensity.current
        CompositionLocalProvider(LocalDensity provides Density(density.density, density.fontScale * factor), content = content)
    }
}

/**
 * The person's app language, text size and font for what v2 draws outside the app's window (the recording pill, the
 * after-call card, the Live Assist card), as the shell gives its pages; dark, as those windows are drawn. It starts
 * from the stored settings (read once, briefly: the store keeps them in memory while the app runs), so the first frame
 * is already in the person's language and size, and follows each change after.
 */
@Composable
fun OverlayLook(settings: kotlinx.coroutines.flow.Flow<com.echoledger.mobile.data.AppSettings>?, content: @Composable () -> Unit) {
    val flow = androidx.compose.runtime.remember(settings) {
        settings ?: kotlinx.coroutines.flow.flowOf(com.echoledger.mobile.data.AppSettings())
    }
    val stored = androidx.compose.runtime.remember(flow) {
        kotlinx.coroutines.runBlocking { kotlinx.coroutines.withTimeoutOrNull(OVERLAY_READ_MS) { flow.first() } }
            ?: com.echoledger.mobile.data.AppSettings()
    }
    val look by flow.collectAsState(initial = stored)
    com.echoledger.mobile.ui.v2.theme.EchoV2Theme(
        darkTheme = true,
        language = AppLook.language(look.appLanguage, com.echoledger.mobile.ui.v2.theme.systemEchoLanguage()),
        phoneFont = look.phoneFont
    ) {
        AppLook.TextSize(look.textSize, content)
    }
}

/** How long a window outside the app waits for the stored settings before it starts with the phone's own look. */
private const val OVERLAY_READ_MS = 300L

/** The app's stored settings, for a window outside the activity. */
fun overlaySettings(context: android.content.Context): kotlinx.coroutines.flow.Flow<com.echoledger.mobile.data.AppSettings>? =
    (context.applicationContext as? com.echoledger.mobile.EchoLedgerApp)?.graph?.settings?.settings
