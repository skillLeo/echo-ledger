package com.echoledger.mobile.overlay

import androidx.compose.ui.graphics.Color
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.LifecycleRegistry
import androidx.savedstate.SavedStateRegistry
import androidx.savedstate.SavedStateRegistryController
import androidx.savedstate.SavedStateRegistryOwner
import com.echoledger.mobile.data.CaptionStyle

/** One caption line as shown: the text (with or without the speaker label) and whose colour it takes. */
data class BubbleLine(val text: String, val client: Boolean, val partial: Boolean)

/** The caption lines (pure): the finals then the partial; compact (caption style) drops the speaker labels. */
fun bubbleLines(lines: List<Pair<String, String>>, partial: Pair<String, String>?, compact: Boolean): List<BubbleLine> =
    (lines.map { it to false } + listOfNotNull(partial).map { it to true }).map { (line, isPartial) ->
        val (speaker, text) = line
        BubbleLine(if (compact) text else "${speaker.uppercase()}  $text", speaker == "Client", isPartial)
    }

/** `#rrggbb` as a colour, else [fallback]. */
fun bubbleColour(hex: String?, fallback: Color): Color {
    if (hex == null || !CaptionStyle.isHexColour(hex)) return fallback
    return Color(0xFF000000 or hex.substring(1).toLong(16))
}

/** Minimal lifecycle + saved-state owner so a ComposeView can live in a window owned by a Service. */
internal class OverlayOwner :
    LifecycleOwner,
    SavedStateRegistryOwner {
    private val registry = LifecycleRegistry(this)
    private val controller = SavedStateRegistryController.create(this)
    override val lifecycle: Lifecycle get() = registry
    override val savedStateRegistry: SavedStateRegistry get() = controller.savedStateRegistry

    fun start() {
        controller.performAttach()
        controller.performRestore(null)
        registry.currentState = Lifecycle.State.RESUMED
    }

    fun stop() {
        registry.currentState = Lifecycle.State.DESTROYED
    }
}
