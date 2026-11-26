package com.echoledger.mobile.ui.v2.settings

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Icon
import androidx.compose.material3.Slider
import androidx.compose.material3.SliderDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.R
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.CaptionStyle
import com.echoledger.mobile.ui.COLOUR_PRESETS
import com.echoledger.mobile.ui.v2.components.EchoChip
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Colors
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** Each speaker colour preset's name (null: the theme's colour), for the swatches' screen-reader names. */
private val COLOUR_WORDS = mapOf(
    null to R.string.v2_cap_colour_theme,
    "#a78bfa" to R.string.v2_cap_colour_violet,
    "#22d3ee" to R.string.v2_cap_colour_cyan,
    "#34d399" to R.string.v2_cap_colour_green,
    "#fbbf24" to R.string.v2_cap_colour_amber,
    "#fb7185" to R.string.v2_cap_colour_rose,
    "#ffffff" to R.string.v2_cap_colour_white
)

/** Classic's opacity slider: 40 % to 100 % in steps of 5. */
private const val OPACITY_MIN = 40f
private const val OPACITY_MAX = 100f
private const val OPACITY_STEPS = 11

/**
 * Captions on the v2 components (classic's Live captions and Caption style, the same settings): Live captions while
 * recording; Text size, Lines, Position, Hide when nobody speaks, Background opacity, Compact captions and a colour for
 * Me and for Client; Reset to defaults. Every choice goes through [update] at once, sanitised as classic's.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun CaptionsPage(
    settings: AppSettings,
    update: ((AppSettings) -> AppSettings) -> Unit,
    highlight: String?,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    val style = settings.captionStyle.sanitized()
    fun save(next: CaptionStyle) = update { it.copy(captionStyle = next.sanitized()) }
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    Column(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_set_page_captions), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            EchoSwitch(
                checked = settings.captionsEnabled,
                onCheckedChange = { on -> update { it.copy(captionsEnabled = on) } },
                label = stringResource(R.string.v2_cap_on),
                supporting = stringResource(R.string.v2_cap_on_help),
                modifier = Modifier.highlightedIf(highlight == "captions.on")
            )
            Column(Modifier.highlightedIf(highlight == "captions.style")) {
                EchoSectionHeader(stringResource(R.string.v2_set_item_captions_style))
                Choices(
                    stringResource(R.string.v2_cap_size),
                    listOf(
                        "small" to stringResource(R.string.v2_cap_small),
                        "medium" to stringResource(R.string.v2_cap_medium),
                        "large" to stringResource(R.string.v2_cap_large)
                    ),
                    style.size,
                    Modifier.highlightedIf(highlight == "captions.size")
                ) { save(style.copy(size = it)) }
                Choices(
                    stringResource(R.string.v2_cap_lines),
                    CaptionStyle.LINES.map {
                        it to "$it"
                    },
                    style.lines,
                    Modifier.highlightedIf(highlight == "captions.lines")
                ) { save(style.copy(lines = it)) }
                Choices(
                    stringResource(R.string.v2_cap_position),
                    listOf("top" to stringResource(R.string.v2_cap_top), "bottom" to stringResource(R.string.v2_cap_bottom)),
                    style.position,
                    Modifier.highlightedIf(highlight == "captions.position")
                ) { save(style.copy(position = it)) }
                Choices(
                    stringResource(R.string.v2_cap_hide),
                    CaptionStyle.AUTO_HIDE.sorted().map { s ->
                        s to if (s == 0) stringResource(R.string.v2_cap_never) else stringResource(R.string.v2_cap_seconds, s)
                    },
                    style.autoHideSeconds,
                    Modifier.highlightedIf(highlight == "captions.hide")
                ) { save(style.copy(autoHideSeconds = it)) }
                val opacityWords = stringResource(R.string.v2_cap_opacity, style.opacity)
                // The slider's own name is the setting's; its value (60 %) is read from the slider itself.
                val opacityName = stringResource(R.string.v2_cap_opacity_name)
                Column(Modifier.highlightedIf(highlight == "captions.opacity")) {
                    Text(opacityWords, style = EchoV2.type.label, color = EchoV2.colors.textSecondary, modifier = pad)
                    var opacity by remember(style.opacity) { mutableFloatStateOf(style.opacity.toFloat()) }
                    val c = EchoV2.colors
                    Slider(
                        value = opacity,
                        onValueChange = { opacity = it },
                        onValueChangeFinished = { save(style.copy(opacity = opacity.toInt())) },
                        valueRange = OPACITY_MIN..OPACITY_MAX,
                        steps = OPACITY_STEPS,
                        colors = SliderDefaults.colors(thumbColor = c.accent, activeTrackColor = c.accent, inactiveTrackColor = c.bgSubtle),
                        modifier = pad.semantics { contentDescription = opacityName }
                    )
                }
                EchoSwitch(
                    checked = style.compact,
                    onCheckedChange = { save(style.copy(compact = it)) },
                    label = stringResource(R.string.v2_cap_compact),
                    supporting = stringResource(R.string.v2_cap_compact_help),
                    modifier = Modifier.highlightedIf(highlight == "captions.compact")
                )
                Column(Modifier.highlightedIf(highlight == "captions.colours")) {
                    Swatches(stringResource(R.string.v2_cap_colour_me), style.meColor) { save(style.copy(meColor = it)) }
                    Swatches(stringResource(R.string.v2_cap_colour_client), style.clientColor) { save(style.copy(clientColor = it)) }
                }
            }
            PageReset("captions", stringResource(R.string.v2_set_page_captions), update)
        }
    }
}

/** A label and its choices as chips (one selected). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun <T> Choices(label: String, options: List<Pair<T, String>>, selected: T, modifier: Modifier = Modifier, onPick: (T) -> Unit) {
    Column(
        modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
    ) {
        Text(label, style = EchoV2.type.label, color = EchoV2.colors.textSecondary)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
            options.forEach { (value, words) ->
                EchoChip(words, value == selected, { onPick(value) }, Modifier.semantics { contentDescription = "$label: $words" })
            }
        }
    }
}

/** A speaker's colour: the presets as round swatches, the chosen one ticked; each is a radio named by its colour. */
@Composable
private fun Swatches(label: String, selected: String?, modifier: Modifier = Modifier, onSelect: (String?) -> Unit) {
    val c = EchoV2.colors
    Column(
        modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
    ) {
        Text(label, style = EchoV2.type.label, color = c.textSecondary)
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
            COLOUR_PRESETS.forEach { hex ->
                val on = hex == selected
                // The person's caption colour (a preset), or the theme's accent.
                val fill = hex?.let { Color(android.graphics.Color.parseColor(it)) } ?: c.accent
                val name = stringResource(COLOUR_WORDS[hex] ?: R.string.v2_cap_colour_theme)
                Box(
                    Modifier.size(EchoTokens.Layout.controlTouchMin)
                        .clip(CircleShape)
                        .background(fill)
                        .border(
                            if (on) EchoTokens.Focus.width else EchoTokens.Border.hairline,
                            if (on) c.textPrimary else c.borderDefault,
                            CircleShape
                        )
                        .selectable(selected = on, role = Role.RadioButton, onClick = { onSelect(hex) })
                        // Selected or not is said by the radio itself.
                        .semantics { contentDescription = "$label: $name" },
                    contentAlignment = androidx.compose.ui.Alignment.Center
                ) {
                    if (on) {
                        // A tick that reads on its swatch: dark on a light colour, light on a dark one.
                        val tick = if (fill.luminance() > 0.5f) EchoV2Colors.Light.textPrimary else EchoV2Colors.Dark.textPrimary
                        Icon(EchoIcons.Check, null, tint = tick, modifier = Modifier.size(EchoTokens.Icon.sm))
                    }
                }
            }
        }
    }
}
