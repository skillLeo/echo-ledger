package com.echoledger.mobile.ui.v2.shell

import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
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
 * The person's app language and text size for what v2 draws outside the app's window (the recording pill, the
 * after-call card, the Live Assist card), as the shell gives its pages; dark unless [dark] says otherwise (the
 * after-call card follows the app's theme). Always the app's own font, never the phone's (a handwritten system font made
 * the after-call card hard to read over other apps; the call-choice plan, 2.0.5). It starts
 * from the app's hot copy of the stored settings ([settings]), so nothing waits while it draws. Just after a cold start,
 * before the store has been read once, it draws nothing (a few frames: the read was asked for, off the main thread)
 * rather than the phone's look; if the store has not answered after [OVERLAY_WAIT_MS], the phone's own look.
 */
@Composable
fun OverlayLook(
    settings: kotlinx.coroutines.flow.StateFlow<com.echoledger.mobile.data.AppSettings?>?,
    dark: Boolean = true,
    content: @Composable () -> Unit
) {
    val flow = androidx.compose.runtime.remember(settings) { settings ?: kotlinx.coroutines.flow.MutableStateFlow(null) }
    val stored = flow.collectAsState().value
    var waited by androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf(false) }
    androidx.compose.runtime.LaunchedEffect(Unit) {
        kotlinx.coroutines.delay(OVERLAY_WAIT_MS)
        waited = true
    }
    if (stored == null && !waited) return
    val look = stored ?: com.echoledger.mobile.data.AppSettings()
    com.echoledger.mobile.ui.v2.theme.EchoV2Theme(
        darkTheme = dark,
        language = AppLook.language(look.appLanguage, com.echoledger.mobile.ui.v2.theme.systemEchoLanguage()),
        phoneFont = false
    ) {
        AppLook.TextSize(look.textSize, content)
    }
}

/** How long a window outside the app waits for the settings' first read before it shows the phone's own look. */
const val OVERLAY_WAIT_MS = 1_500L

/**
 * The app's stored settings, kept hot, for a window outside the activity. Before they have been read once (a process a
 * call has just started), it asks for that read, once, off the main thread, without waiting for it.
 */
fun overlaySettings(context: android.content.Context): kotlinx.coroutines.flow.StateFlow<com.echoledger.mobile.data.AppSettings?>? {
    val graph = (context.applicationContext as? com.echoledger.mobile.EchoLedgerApp)?.graph ?: return null
    graph.readSettingsOnce()
    return graph.liveSettings
}
