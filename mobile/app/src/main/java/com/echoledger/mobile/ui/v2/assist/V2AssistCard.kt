package com.echoledger.mobile.ui.v2.assist

import android.os.SystemClock
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.withFrameMillis
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.LayoutDirection
import com.echoledger.mobile.R
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Colors
import com.echoledger.mobile.ui.v2.theme.LocalEchoV2Colors
import com.echoledger.mobile.ui.v2.theme.largeText
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

@Composable
private fun modeWord(mode: LiveMode): String = stringResource(
    when (mode) {
        LiveMode.Fast -> R.string.v2_assist_mode_fast
        LiveMode.Balanced -> R.string.v2_assist_mode_balanced
        LiveMode.Deep -> R.string.v2_assist_mode_deep
        LiveMode.Max -> R.string.v2_assist_mode_max
        LiveMode.CaptionsOnly -> R.string.v2_assist_mode_captions_only
    }
)

/** Why the mode changed, in words (never a code). */
@Composable
fun modeNote(change: Pair<LiveMode, ModeReason>): String? {
    val (mode, reason) = change
    if (mode == LiveMode.CaptionsOnly) {
        return stringResource(if (reason == ModeReason.Plan) R.string.v2_assist_mode_off_plan else R.string.v2_assist_mode_off_budget)
    }
    val word = modeWord(mode)
    return when (reason) {
        ModeReason.Budget -> stringResource(R.string.v2_assist_mode_budget, word)
        ModeReason.BudgetRestored -> stringResource(R.string.v2_assist_mode_restored, word)
        ModeReason.Plan -> stringResource(R.string.v2_assist_mode_plan, word)
        ModeReason.AssistTime -> stringResource(R.string.v2_assist_mode_time, word)
        ModeReason.Requested -> null
    }
}

@Composable
private fun problemWords(state: CardState): String? {
    val problem = state.problem ?: return null
    if (problem == AssistProblem.NotSetUp) return state.problemText ?: stringResource(R.string.v2_assist_error_not_set_up)
    return stringResource(
        when (problem) {
            AssistProblem.Reconnecting -> R.string.v2_assist_error_reconnecting
            AssistProblem.Session -> R.string.v2_assist_error_session
            AssistProblem.Busy -> R.string.v2_assist_error_busy
            AssistProblem.NotSetUp -> R.string.v2_assist_error_not_set_up
            AssistProblem.Slow -> R.string.v2_assist_error_slow
            AssistProblem.Declined -> R.string.v2_assist_error_declined
            AssistProblem.NothingToRewrite -> R.string.v2_assist_error_nothing_to_rewrite
            AssistProblem.DocumentsTooLarge -> R.string.v2_assist_error_documents_too_large
            AssistProblem.TooFast -> R.string.v2_assist_error_too_fast
            AssistProblem.BadMessage -> R.string.v2_assist_error_bad_message
            AssistProblem.BudgetOff -> R.string.v2_assist_error_budget_off
            AssistProblem.BudgetWait -> R.string.v2_assist_error_budget_wait
            AssistProblem.Other -> R.string.v2_assist_error_other
        }
    )
}

private fun bold(text: String): AnnotatedString = buildAnnotatedString {
    SuggestionText.boldParts(text).forEach { (part, strong) ->
        if (strong) withStyle(SpanStyle(fontWeight = FontWeight.SemiBold)) { append(part) } else append(part)
    }
}

/**
 * The Live Assist card in the phone's call pill (v1.9 L5, behind `live_assist` and `ui_v2.live`). Collapsed it is a dot
 * that lights when a suggestion is ready; expanded it shows at most three lines (the first line, then bullets) with
 * Help me now, Regenerate, Shorter, More formal and Urdu. The interview reminder or the consent line shows once per session. It
 * floats over the call, so it uses the dark roles. A component on [CardState]; the core feeds it through [AssistAdapter].
 */
@Composable
fun V2AssistCard(
    state: CardState,
    expanded: Boolean,
    onExpand: (Boolean) -> Unit,
    onSend: (SendKind) -> Unit,
    onCopyConsent: () -> Unit,
    modifier: Modifier = Modifier,
    onShown: (suggestionId: String, renderMs: Long) -> Unit = { _, _ -> },
    elapsedMs: () -> Long = SystemClock::elapsedRealtime
) {
    // "shown" once per suggestion, when its first text is drawn (expanded or not: the dot lights then).
    val reported = remember(state.sessionId) { mutableSetOf<String>() }
    val first = state.shown?.takeIf { it.text.isNotEmpty() && it.id !in reported }
    if (first != null) {
        LaunchedEffect(first.id) {
            withFrameMillis { }
            if (reported.add(first.id)) onShown(first.id, (elapsedMs() - first.receivedAtMs).coerceAtLeast(0))
        }
    }
    CompositionLocalProvider(LocalEchoV2Colors provides EchoV2Colors.Dark) {
        if (!expanded) AssistDot(state, onExpand, modifier) else AssistExpanded(state, onExpand, onSend, onCopyConsent, modifier)
    }
}

@Composable
private fun AssistDot(state: CardState, onExpand: (Boolean) -> Unit, modifier: Modifier) {
    val c = EchoV2.colors
    val label = if (state.ready) stringResource(R.string.v2_assist_ready_dot) else stringResource(R.string.v2_assist_title)
    Surface(
        onClick = { onExpand(true) },
        shape = CircleShape,
        color = c.bgOverlay,
        border = BorderStroke(EchoTokens.Border.hairline, c.borderDefault),
        shadowElevation = EchoTokens.Space.s8,
        modifier = modifier.size(EchoTokens.Layout.controlTouchMin).semantics { contentDescription = label }
    ) {
        Box(contentAlignment = Alignment.Center) {
            Box(
                Modifier
                    .size(EchoTokens.Component.statusDotSize + EchoTokens.Space.s4)
                    .background(if (state.ready) c.accent else c.borderStrong, CircleShape)
            )
        }
    }
}

@Composable
private fun AssistExpanded(
    state: CardState,
    onExpand: (Boolean) -> Unit,
    onSend: (SendKind) -> Unit,
    onCopyConsent: () -> Unit,
    modifier: Modifier
) {
    val c = EchoV2.colors
    var noteSeen by remember(state.sessionId) { mutableStateOf(false) }
    val s = state.shown
    val parsed = s?.text?.takeIf { it.isNotBlank() }?.let(SuggestionText::parse)
    val question = s?.turnId?.let { state.turns[it] }
    Surface(
        shape = RoundedCornerShape(EchoTokens.Component.cardRadius),
        color = c.bgOverlay,
        contentColor = c.textPrimary,
        border = BorderStroke(EchoTokens.Border.hairline, c.borderDefault),
        shadowElevation = EchoTokens.Space.s8,
        modifier = modifier.widthIn(max = EchoTokens.Space.s64 * 6).fillMaxWidth()
    ) {
        Column(Modifier.padding(bottom = EchoTokens.Space.s8)) {
            Row(Modifier.padding(start = EchoTokens.Space.s16), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    stringResource(R.string.v2_assist_title),
                    style = EchoV2.type.label,
                    color = c.textSecondary,
                    modifier = Modifier.weight(1f)
                )
                Text(modeWord(state.mode), style = EchoV2.type.caption, color = c.textSecondary)
                EchoIconButton(EchoIcons.Expand, stringResource(R.string.v2_assist_collapse), { onExpand(false) }, tint = c.textSecondary)
            }
            if (!noteSeen && state.meetingType != null) {
                Reminder(state.meetingType, onCopyConsent, onOk = { noteSeen = true })
            }
            if (state.autoPaused) {
                Text(
                    stringResource(R.string.v2_assist_auto_paused),
                    style = EchoV2.type.meta,
                    color = c.textSecondary,
                    modifier = Modifier.padding(horizontal = EchoTokens.Space.s16)
                )
            }
            state.modeChange?.let { change ->
                modeNote(change)?.let {
                    Text(
                        it,
                        style = EchoV2.type.meta,
                        color = c.textSecondary,
                        modifier = Modifier.padding(horizontal = EchoTokens.Space.s16)
                    )
                }
            }
            // At large text the suggestion gets all its lines: up to six show, and the text scrolls beyond that.
            val large = largeText()
            // One line converted, then times six: Android scales large sizes less, so 6 × 24 sp would come out short.
            val sixLines = with(LocalDensity.current) { EchoV2.type.contentStrong.lineHeight.toDp() } * MAX_LINES_LARGE
            Column(
                Modifier
                    .then(if (large) Modifier.heightIn(max = sixLines).verticalScroll(rememberScrollState()) else Modifier)
                    .testTag(TEXT_TAG)
                    .padding(horizontal = EchoTokens.Space.s16, vertical = EchoTokens.Space.s8)
                    .semantics { liveRegion = LiveRegionMode.Polite },
                verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
            ) {
                state.practiceDone?.let {
                    Text(stringResource(R.string.v2_assist_practice_done, it), style = EchoV2.type.meta, color = c.textSecondary)
                }
                state.practice?.takeIf { state.practiceDone == null }?.let { (index, total) ->
                    Text(
                        stringResource(R.string.v2_assist_practice_question, index, total),
                        style = EchoV2.type.caption,
                        color = c.textSecondary
                    )
                }
                question?.let {
                    Text(
                        it,
                        style = EchoV2.type.contentCaption.copy(textDirection = TextDirection.Content),
                        color = c.textSecondary,
                        maxLines = if (large) 2 else 1,
                        overflow = TextOverflow.Ellipsis
                    )
                }
                when {
                    state.ended -> Text(stringResource(R.string.v2_assist_ended), style = EchoV2.type.meta, color = c.textSecondary)

                    state.captionsOnly -> Unit

                    parsed != null && parsed.first.isNotEmpty() -> {
                        // At most three lines on the phone: the first line, then bullets while there is room. At large
                        // text nothing is cut: the area scrolls instead.
                        Text(
                            bold(parsed.first),
                            style = EchoV2.type.contentStrong.copy(textDirection = TextDirection.Content),
                            maxLines = if (large) Int.MAX_VALUE else 2,
                            overflow = TextOverflow.Ellipsis,
                            modifier = Modifier.fillMaxWidth()
                        )
                        parsed.bullets.take(if (large) 3 else 1).forEach {
                            Text(
                                bold("• $it"),
                                style = EchoV2.type.metaLatin.copy(textDirection = TextDirection.Content),
                                maxLines = if (large) Int.MAX_VALUE else 1,
                                overflow = TextOverflow.Ellipsis,
                                modifier = Modifier.fillMaxWidth()
                            )
                        }
                        parsed.language?.let { (label, text) ->
                            // The language line, styled apart: its label, then the explanation in its own direction.
                            Row(
                                verticalAlignment = Alignment.CenterVertically,
                                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
                            ) {
                                Text(label, style = EchoV2.type.contentCaption, color = c.textSecondary)
                                Text(
                                    text,
                                    style = (
                                        if (label ==
                                            "Roman Urdu"
                                        ) {
                                            EchoV2.type.metaLatin
                                        } else {
                                            EchoV2.type.urduBody
                                        }
                                        ).copy(textDirection = TextDirection.Content),
                                    color = c.textSecondary,
                                    maxLines = if (large) Int.MAX_VALUE else 1,
                                    overflow = TextOverflow.Ellipsis,
                                    modifier = Modifier.weight(1f)
                                )
                            }
                        }
                    }

                    s?.status == SuggestionStatus.Streaming -> Text(
                        stringResource(R.string.v2_assist_writing),
                        style = EchoV2.type.meta,
                        color = c.textSecondary
                    )

                    state.skipped -> Text(stringResource(R.string.v2_assist_no_reply), style = EchoV2.type.meta, color = c.textSecondary)

                    state.problem == null -> Text(
                        stringResource(R.string.v2_assist_waiting),
                        style = EchoV2.type.meta,
                        color = c.textSecondary
                    )
                }
                problemWords(state)?.let { Text(it, style = EchoV2.type.meta, color = c.danger) }
            }
            if (!state.captionsOnly && !state.ended) {
                // Five buttons wrap onto a second line rather than scroll out of sight (narrow phones, large text).
                FlowRow(
                    Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Space.s8),
                    horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
                ) {
                    EchoButton(stringResource(R.string.v2_assist_help_now), { onSend(SendKind.HelpNow) })
                    EchoButton(
                        stringResource(R.string.v2_assist_regenerate),
                        {
                            onSend(SendKind.Regenerate)
                        },
                        kind = EchoButtonKind.Plain,
                        enabled =
                            s != null
                    )
                    EchoButton(
                        stringResource(R.string.v2_assist_shorter),
                        {
                            onSend(SendKind.Shorter)
                        },
                        kind = EchoButtonKind.Plain,
                        enabled =
                            s != null
                    )
                    EchoButton(
                        stringResource(R.string.v2_assist_formal),
                        { onSend(SendKind.Formal) },
                        kind = EchoButtonKind.Plain,
                        enabled = s != null
                    )
                    EchoButton(
                        stringResource(R.string.v2_assist_urdu),
                        {
                            onSend(SendKind.Translate)
                        },
                        kind = EchoButtonKind.Plain,
                        enabled =
                            s != null
                    )
                }
            }
        }
    }
}

/** Once per session: the interview reminder, or for other meetings the consent line to say, with Copy. */
@Composable
private fun Reminder(meeting: MeetingType, onCopyConsent: () -> Unit, onOk: () -> Unit) {
    val c = EchoV2.colors
    Column(
        Modifier
            .padding(horizontal = EchoTokens.Space.s8)
            .fillMaxWidth()
            .background(c.bgSubtle, RoundedCornerShape(EchoTokens.Component.cardRadius))
            .padding(start = EchoTokens.Space.s8, top = EchoTokens.Space.s8)
    ) {
        if (meeting == MeetingType.Interview) {
            Text(
                stringResource(R.string.v2_assist_interview_note),
                style = EchoV2.type.meta,
                color = c.textPrimary,
                modifier = Modifier.padding(end = EchoTokens.Space.s8)
            )
        } else {
            Text(stringResource(R.string.v2_assist_consent_label), style = EchoV2.type.caption, color = c.textSecondary)
            // The sentence to say is English in every language: laid out left to right as a whole (its own direction,
            // alignment and layout), so on an Urdu screen its "?" stays at the end of the last line.
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Text(
                    stringResource(R.string.v2_assist_consent),
                    style = EchoV2.type.metaLatin.copy(textDirection = TextDirection.Ltr),
                    color = c.textPrimary,
                    textAlign = TextAlign.Left,
                    modifier = Modifier.fillMaxWidth().padding(end = EchoTokens.Space.s8).testTag(CONSENT_TAG)
                )
            }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
            if (meeting !=
                MeetingType.Interview
            ) {
                EchoButton(stringResource(R.string.v2_assist_copy), onCopyConsent, kind = EchoButtonKind.Quiet)
            }
            EchoButton(stringResource(R.string.v2_assist_ok), onOk, kind = EchoButtonKind.Quiet)
        }
    }
}

/** The card's text area, for tests. */
const val TEXT_TAG = "assist-text"

/** Lines of suggestion shown at large text before the text area scrolls. */
private const val MAX_LINES_LARGE = 6

/** The consent sentence, for tests. */
const val CONSENT_TAG = "assist-consent"
