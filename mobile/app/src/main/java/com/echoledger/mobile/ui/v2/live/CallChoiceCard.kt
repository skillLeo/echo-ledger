package com.echoledger.mobile.ui.v2.live

import android.content.res.Resources
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.CustomAccessibilityAction
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.customActions
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.R
import com.echoledger.mobile.calls.CallChoice
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoSourceLogo
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.shell.sourceName
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlin.math.abs
import kotlinx.coroutines.launch

/** What the after-call card shows: the question, one confirmation line, or several calls saved together. */
sealed interface CallCardScreen {
    /** The question. [hint]: the first card ever; [always]: "Always do this?" for that choice, once. */
    data class Ask(
        val title: String,
        val durationMs: Long,
        val sourceWire: String?,
        val hint: Boolean = false,
        val always: CallChoice.Pick? = null
    ) : CallCardScreen

    /** One confirmation line with Undo (and Transcribe after Save audio only). */
    data class Done(val pick: CallChoice.Pick) : CallCardScreen

    /** Back-to-back calls: "3 calls saved as audio · Review". */
    data class Grouped(val count: Int) : CallCardScreen
}

/** The name or number the card shows for [rec] (a number kept left to right in Urdu), else the call's app. */
fun callCardTitle(res: Resources, rec: LocalRecording): String = rec.title?.takeIf { it.isNotBlank() }
    ?: rec.callerName?.takeIf { it.isNotBlank() }
    ?: rec.callerNumber?.takeIf { it.any(Char::isDigit) }?.let { isolated(it) }
    ?: sourceName(res, rec.sourceApp)

/** [text] isolated (FSI…PDI) inside a line of any direction, as the copy deck asks for names, apps and numbers. */
internal fun isolated(text: String): String = "\u2068$text\u2069"

/**
 * The after-call card (the call-choice plan, docs/PLAN_CALL_CHOICE.md 3): the app's logo, the name or number,
 * "0:44 · just now", Delete (a quiet text button) and Transcribe (the one filled button), an 8 s line, and "Swipe away
 * to keep audio only". A swipe left or right, or nothing for 8 s, keeps the audio only ([onAudio], also TalkBack's
 * custom action). A solid surface from the tokens with radius 16; the buttons go onto two rows when they don't fit (a
 * 360 dp phone, the largest text, Urdu). It slides up in 200 ms; the progress line is its only animation and stands
 * still under Remove animations ([still]). No sound and no vibration. After a choice the same card is one line with
 * Undo, never two lines and never a toast on top.
 */
@Composable
fun CallChoiceCard(
    screen: CallCardScreen,
    onTranscribe: () -> Unit,
    onDelete: () -> Unit,
    onAudio: () -> Unit,
    onUndo: () -> Unit,
    modifier: Modifier = Modifier,
    onAlwaysYes: () -> Unit = {},
    onReview: () -> Unit = {},
    still: Boolean = false,
    /** Ask's time to answer (tests and screenshots hold it still). */
    askMs: Long = CallChoice.CARD_MS
) {
    val c = EchoV2.colors
    val density = LocalDensity.current
    val rise = remember { Animatable(if (still) 0f else 1f) }
    LaunchedEffect(Unit) { if (!still) rise.animateTo(0f, tween(RISE_MS, easing = LinearEasing)) }
    val swipe = remember(screen) { Animatable(0f) }
    val scope = rememberCoroutineScope()
    val audioLabel = stringResource(R.string.cc_save_audio_only)
    val swipes = screen is CallCardScreen.Ask
    Surface(
        shape = RoundedCornerShape(EchoTokens.Radius.sheet),
        // Solid: never the home screen through it.
        color = c.bgOverlay.copy(alpha = 1f),
        contentColor = c.textPrimary,
        border = BorderStroke(EchoTokens.Border.hairline, c.borderDefault),
        shadowElevation = EchoTokens.Space.s4,
        modifier = modifier
            .widthIn(max = EchoTokens.Space.s64 * 7)
            .fillMaxWidth()
            .graphicsLayer {
                translationY = rise.value * with(density) { EchoTokens.Space.s64.toPx() }
                translationX = swipe.value
                alpha = 1f - (abs(swipe.value) / (size.width.coerceAtLeast(1f))).coerceIn(0f, 1f)
            }
            .then(
                if (swipes) {
                    Modifier
                        .pointerInput(screen) {
                            detectHorizontalDragGestures(
                                onDragEnd = {
                                    scope.launch {
                                        if (abs(swipe.value) > size.width * SWIPE_FRACTION) {
                                            onAudio()
                                        } else if (still) {
                                            swipe.snapTo(0f)
                                        } else {
                                            swipe.animateTo(0f, tween(RISE_MS))
                                        }
                                    }
                                },
                                onDragCancel = { scope.launch { swipe.snapTo(0f) } }
                            ) { change, amount ->
                                change.consume()
                                scope.launch { swipe.snapTo(swipe.value + amount) }
                            }
                        }
                        .semantics { customActions = listOf(CustomAccessibilityAction(audioLabel) { onAudio().let { true } }) }
                } else {
                    Modifier
                }
            )
            .semantics { liveRegion = LiveRegionMode.Polite }
    ) {
        when (screen) {
            is CallCardScreen.Ask -> AskContent(screen, onTranscribe, onDelete, onAlwaysYes, still, askMs)

            is CallCardScreen.Done -> DoneLine(screen.pick, onTranscribe, onUndo)

            is CallCardScreen.Grouped -> Line(pluralCount(screen.count)) {
                EchoButton(stringResource(R.string.cc_grouped_review), onReview, kind = EchoButtonKind.Quiet)
            }
        }
    }
}

@Composable
private fun pluralCount(count: Int): String = stringResource(R.string.cc_grouped_audio, count)

@Composable
private fun AskContent(
    screen: CallCardScreen.Ask,
    onTranscribe: () -> Unit,
    onDelete: () -> Unit,
    onAlwaysYes: () -> Unit,
    still: Boolean,
    askMs: Long
) {
    val c = EchoV2.colors
    val res = androidx.compose.ui.platform.LocalContext.current.resources
    val left = remember { Animatable(1f) }
    LaunchedEffect(Unit) { if (!still) left.animateTo(0f, tween(askMs.toInt(), easing = LinearEasing)) }
    Column(Modifier.padding(top = EchoTokens.Space.s12)) {
        Row(
            Modifier.padding(horizontal = EchoTokens.Space.s16),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            // The app's own mark, as the Library's rows draw it (its installed icon, else the one icon set's glyph).
            EchoSourceLogo(SourceApp.from(screen.sourceWire), label = sourceName(res, screen.sourceWire))
            Column(Modifier.weight(1f)) {
                // A name keeps its own script's face: Latin letters in Urdu stay in the app's Latin font.
                Text(
                    screen.title,
                    style = EchoV2.type.content(screen.title, EchoV2.type.bodyStrong),
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis
                )
                Text(meta(screen.durationMs), style = EchoV2.type.meta, color = c.textSecondary)
            }
        }
        // Two rows only when the two don't fit side by side (a small phone, the largest text, Urdu).
        FlowRow(
            Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s8),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4),
            itemVerticalAlignment = Alignment.CenterVertically
        ) {
            EchoButton(stringResource(R.string.cc_delete), onDelete, kind = EchoButtonKind.Quiet)
            EchoButton(stringResource(R.string.cc_transcribe), onTranscribe, kind = EchoButtonKind.Primary)
        }
        // The time left: the card's only animation, standing still under Remove animations.
        Box(Modifier.fillMaxWidth().height(EchoTokens.Border.strong).background(c.borderDefault)) {
            Box(Modifier.fillMaxWidth(if (still) 1f else left.value).height(EchoTokens.Border.strong).background(c.accent))
        }
        Text(
            stringResource(R.string.cc_card_swipe),
            style = EchoV2.type.meta,
            color = c.textSecondary,
            textAlign = TextAlign.Center,
            modifier = Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Space.s16, vertical = EchoTokens.Space.s8)
        )
        if (screen.hint) {
            Text(
                stringResource(R.string.cc_card_first_hint),
                style = EchoV2.type.meta,
                color = c.textSecondary,
                textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth().padding(
                    start = EchoTokens.Space.s16,
                    end = EchoTokens.Space.s16,
                    bottom = EchoTokens.Space.s8
                )
            )
        } else if (screen.always != null) {
            FlowRow(
                Modifier.fillMaxWidth().padding(start = EchoTokens.Space.s16, end = EchoTokens.Space.s8, bottom = EchoTokens.Space.s4),
                horizontalArrangement = Arrangement.SpaceBetween,
                itemVerticalAlignment = Alignment.CenterVertically
            ) {
                Text(stringResource(R.string.cc_card_always), style = EchoV2.type.meta, color = c.textSecondary)
                EchoButton(stringResource(R.string.cc_card_always_yes), onAlwaysYes, kind = EchoButtonKind.Quiet)
            }
        }
    }
}

/**
 * "0:44 · just now" in the screen's language, the length isolated (FSI…PDI). Everything outside Urdu's script (the
 * length, the dot and the spaces around it) is in the Latin face with tabular digits, so in Urdu the dot keeps its
 * spaces on both sides instead of taking the Nastaliq font's.
 */
@Composable
private fun meta(durationMs: Long): androidx.compose.ui.text.AnnotatedString {
    val line = stringResource(R.string.cc_card_meta, isolated(formatClock(durationMs)))
    val latin = androidx.compose.ui.text.SpanStyle(fontFamily = EchoV2.type.latin, fontFeatureSettings = "tnum")
    return androidx.compose.ui.text.buildAnnotatedString {
        append(line)
        var start = -1
        line.forEachIndexed { at, ch ->
            val arabicScript = Character.UnicodeScript.of(ch.code) == Character.UnicodeScript.ARABIC
            if (!arabicScript && start < 0) start = at
            if (arabicScript && start >= 0) {
                addStyle(latin, start, at)
                start = -1
            }
        }
        if (start >= 0) addStyle(latin, start, line.length)
    }
}

@Composable
private fun DoneLine(pick: CallChoice.Pick, onTranscribe: () -> Unit, onUndo: () -> Unit) {
    val words = when (pick) {
        CallChoice.Pick.TRANSCRIBE -> R.string.cc_done_transcribing
        CallChoice.Pick.AUDIO -> R.string.cc_done_audio
        CallChoice.Pick.DELETE -> R.string.cc_done_deleted
    }
    Line(stringResource(words)) {
        if (pick == CallChoice.Pick.AUDIO) EchoButton(stringResource(R.string.cc_transcribe), onTranscribe, kind = EchoButtonKind.Quiet)
        EchoButton(stringResource(R.string.cc_undo), onUndo, kind = EchoButtonKind.Quiet)
    }
}

/** One line: its words, then its buttons (on the next row when they don't fit). */
@Composable
private fun Line(text: String, buttons: @Composable () -> Unit) {
    FlowRow(
        Modifier.fillMaxWidth().padding(
            start = EchoTokens.Space.s16,
            end = EchoTokens.Space.s8,
            top = EchoTokens.Space.s4,
            bottom = EchoTokens.Space.s4
        ),
        horizontalArrangement = Arrangement.SpaceBetween,
        itemVerticalAlignment = Alignment.CenterVertically
    ) {
        Text(text, style = EchoV2.type.bodyStrong, modifier = Modifier.padding(vertical = EchoTokens.Space.s8))
        Row(verticalAlignment = Alignment.CenterVertically) { buttons() }
    }
}

/** The card slides up in this long (ms); a swipe past this part of its width keeps the audio only. */
private const val RISE_MS = 200
private const val SWIPE_FRACTION = 0.3f
