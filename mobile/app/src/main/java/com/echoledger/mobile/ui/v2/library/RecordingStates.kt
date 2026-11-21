package com.echoledger.mobile.ui.v2.library

import android.content.res.Resources
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.R
import com.echoledger.mobile.core.RecordingSteps
import com.echoledger.mobile.core.RowProgress
import com.echoledger.mobile.core.TranscriptText
import com.echoledger.mobile.data.CallNotesDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ProgressDto
import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.SessionMarkerDto
import com.echoledger.mobile.data.SuggestionDto
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCard
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** A step's word as it stands now (Upload, Uploading, Uploaded…), in the screen's language. */
private fun stepWord(res: Resources, step: RecordingSteps.Step, state: RecordingSteps.State): String = res.getString(
    when (step) {
        RecordingSteps.Step.UPLOAD -> when (state) {
            RecordingSteps.State.ACTIVE -> R.string.v2_rec_step_uploading
            RecordingSteps.State.DONE -> R.string.v2_rec_step_uploaded
            else -> R.string.v2_rec_step_upload
        }

        RecordingSteps.Step.TRANSCRIBE -> when (state) {
            RecordingSteps.State.ACTIVE -> R.string.v2_rec_step_transcribing
            RecordingSteps.State.DONE -> R.string.v2_rec_step_transcribed
            else -> R.string.v2_rec_step_transcribe
        }

        RecordingSteps.Step.IMPROVE -> when (state) {
            RecordingSteps.State.ACTIVE -> R.string.v2_rec_step_improving
            RecordingSteps.State.DONE -> R.string.v2_rec_step_improved
            else -> R.string.v2_rec_step_improve
        }
    }
)

/** "20 s", "3 min" (classic's RowProgress.timeLeft), in the screen's language. */
fun timeLeft(res: Resources, seconds: Long): String = if (seconds < 60) {
    res.getString(R.string.v2_rec_seconds, ((seconds.coerceAtLeast(5) + 4) / 5 * 5).toInt())
} else {
    res.getString(R.string.v2_rec_minutes, ((seconds + 30) / 60).toInt())
}

/**
 * Classic's "Waiting to transcribe — retrying at 2:05 pm" (an Urdu or Auto transcript waiting for its best engine),
 * in the screen's language and locale; null when it is not waiting.
 */
fun waitingWords(res: Resources, t: TranscriptDto, zone: java.time.ZoneId = java.time.ZoneId.systemDefault()): String? {
    val at = t.retryAt?.let { runCatching { java.time.Instant.parse(it) }.getOrNull() } ?: return null
    val locale = res.configuration.locales[0] ?: java.util.Locale.getDefault()
    val time = at.atZone(zone).format(java.time.format.DateTimeFormatter.ofPattern("h:mm a", locale))
    return res.getString(if (t.retryReason == "rate_limited") R.string.v2_rec_waiting_busy else R.string.v2_rec_waiting, time)
}

/** "Transcribing · part 2 of 3 · about 20 s left" (classic's TranscriptText.transcribing), in the screen's language. */
fun transcribingLine(res: Resources, progress: ProgressDto?): String {
    val total = progress?.total ?: 0
    val done = progress?.done ?: 0
    return listOfNotNull(
        res.getString(R.string.v2_rec_step_transcribing),
        if (total > 1) res.getString(R.string.v2_rec_part, (done + 1).coerceAtMost(total), total) else null,
        progress?.etaSeconds?.let { res.getString(R.string.v2_rec_left, timeLeft(res, it)) }
    ).joinToString(" · ")
}

/**
 * The line under the steps, in the screen's language, from the same facts as classic's ([RecordingSteps.Steps.line]):
 * "Transcribing · part 2 of 3 · about 20 s left", "Improving Roman Urdu… 60%", "Transcription failed", "Uploading";
 * waiting for the engine keeps classic's words (with its time).
 */
fun stepLine(res: Resources, item: LibraryItem, transcript: TranscriptDto?, steps: RecordingSteps.Steps): String {
    val s = steps.states
    return when {
        s[RecordingSteps.Step.TRANSCRIBE] == RecordingSteps.State.FAILED -> res.getString(R.string.v2_rec_failed_line)

        s[RecordingSteps.Step.UPLOAD] == RecordingSteps.State.ACTIVE -> res.getString(R.string.v2_rec_step_uploading)

        s[RecordingSteps.Step.TRANSCRIBE] == RecordingSteps.State.ACTIVE -> {
            transcript?.let { waitingWords(res, it) }?.let { return it }
            val progress: ProgressDto? = transcript?.progress ?: item.transcript?.finalProgress
            val total = progress?.total ?: 0
            val done = progress?.done ?: 0
            listOfNotNull(
                res.getString(R.string.v2_rec_step_transcribing),
                if (total > 1) res.getString(R.string.v2_rec_part, (done + 1).coerceAtMost(total), total) else null,
                progress?.etaSeconds?.let { res.getString(R.string.v2_rec_left, timeLeft(res, it)) }
            ).joinToString(" · ")
        }

        else -> conversionWords(res, item) ?: ""
    }
}

/** A view's name in the screen's language: Roman Urdu, Urdu, English. */
fun viewWords(res: Resources, key: String?): String = when (key) {
    "roman" -> res.getString(R.string.v2_rec_view_roman)
    "urdu" -> res.getString(R.string.v2_rec_view_urdu)
    "english" -> res.getString(R.string.v2_rec_view_english)
    else -> key.orEmpty()
}

/**
 * U19, the default view of a finished transcript on its way, in the screen's language: "Converting to Roman Urdu… 60%"
 * (also before the server started it), "Couldn't convert to Roman Urdu · Try again" when it failed; null otherwise.
 */
fun conversionWords(res: Resources, item: LibraryItem): String? {
    val stage = RowProgress.of(item) ?: return null
    val view = viewWords(res, item.transcript?.views?.default)
    return when (stage.step) {
        RowProgress.Step.IMPROVING -> stage.percent?.let { res.getString(R.string.v2_rec_improving_view_pct, view, it) }
            ?: res.getString(R.string.v2_rec_improving_view, view)

        RowProgress.Step.VIEW_FAILED -> res.getString(R.string.v2_rec_convert_failed, view)

        else -> null
    }
}

/**
 * The recording on its way (classic's RecordingStepsBlock): Upload · Transcribe · Improve with the running one named, the
 * line under them with its bar (only when there is a measure: no looping bars in v2), and Notify me when ready while the
 * transcript is still to come.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun StepsBlock(item: LibraryItem, transcript: TranscriptDto?, steps: RecordingSteps.Steps, onNotify: () -> Unit) {
    val c = EchoV2.colors
    val res = LocalContext.current.resources
    var asked by remember(item.id) { mutableStateOf(false) }
    val words = RecordingSteps.Step.entries.map { stepWord(res, it, steps.states.getValue(it)) }
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
        FlowRow(
            Modifier.semantics(mergeDescendants = true) {
                contentDescription =
                    res.getString(R.string.v2_rec_steps, words.joinToString(", "))
            },
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            RecordingSteps.Step.entries.forEachIndexed { i, step ->
                val state = steps.states.getValue(step)
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
                    Box(
                        Modifier.size(EchoTokens.Space.s8).clip(CircleShape).background(
                            when (state) {
                                RecordingSteps.State.DONE -> c.success
                                RecordingSteps.State.ACTIVE -> c.accent
                                RecordingSteps.State.FAILED -> c.danger
                                RecordingSteps.State.WAITING -> c.borderStrong
                            }
                        )
                    )
                    Text(
                        words[i],
                        style = if (state == RecordingSteps.State.ACTIVE) EchoV2.type.bodyStrong else EchoV2.type.body,
                        color = if (state == RecordingSteps.State.ACTIVE) c.textPrimary else c.textSecondary
                    )
                }
            }
        }
        Text(
            stepLine(res, item, transcript, steps),
            style = EchoV2.type.meta,
            color = c.textSecondary,
            modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite }
        )
        steps.percent?.let { Bar(it) }
        val onItsWay = steps.states[RecordingSteps.Step.UPLOAD] == RecordingSteps.State.ACTIVE ||
            steps.states[RecordingSteps.Step.TRANSCRIBE] == RecordingSteps.State.ACTIVE
        if (onItsWay) {
            if (asked) {
                Text(stringResource(R.string.v2_rec_notify_set), style = EchoV2.type.meta, color = c.textSecondary)
            } else {
                EchoButton(stringResource(R.string.v2_rec_notify), {
                    asked = true
                    onNotify()
                }, kind = EchoButtonKind.Quiet)
            }
        }
    }
}

/** A thin bar for [percent] (0-100). */
@Composable
private fun Bar(percent: Int) {
    val c = EchoV2.colors
    Box(Modifier.fillMaxWidth().height(EchoTokens.Border.strong).clip(CircleShape).background(c.borderDefault)) {
        Box(Modifier.fillMaxWidth(percent.coerceIn(0, 100) / 100f).height(EchoTokens.Border.strong).background(c.accent))
    }
}

/** "Converting to Roman Urdu… 60%" with a small progress ring over a transcript that can be read already (in its original words). */
@Composable
fun ImprovingLine(item: LibraryItem, transcript: TranscriptDto?, steps: RecordingSteps.Steps) {
    val res = LocalContext.current.resources
    Column(
        Modifier.semantics(mergeDescendants = true) { liveRegion = LiveRegionMode.Polite },
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
            com.echoledger.mobile.ui.v2.components.EchoProgressRing(steps.percent)
            Text(stepLine(res, item, transcript, steps), style = EchoV2.type.meta, color = EchoV2.colors.textSecondary)
        }
        steps.percent?.let { Bar(it) }
    }
}

/** "Converting to Roman Urdu… 60%" from the page's own views (after Try again, before the Library hears of it). */
@Composable
fun ConvertingLine(view: String?, percent: Int?) {
    val res = LocalContext.current.resources
    val name = viewWords(res, view)
    Column(
        Modifier.semantics(mergeDescendants = true) { liveRegion = LiveRegionMode.Polite },
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
            com.echoledger.mobile.ui.v2.components.EchoProgressRing(percent)
            Text(
                percent?.let { res.getString(R.string.v2_rec_improving_view_pct, name, it) }
                    ?: res.getString(R.string.v2_rec_improving_view, name),
                style = EchoV2.type.meta,
                color = EchoV2.colors.textSecondary
            )
        }
        percent?.let { Bar(it) }
    }
}

/** "Couldn't convert to Roman Urdu · Try again" with Try again, which asks the server to make the view again. */
@Composable
fun ConvertFailedLine(item: LibraryItem, onTryAgain: () -> Unit) {
    val res = LocalContext.current.resources
    val words = conversionWords(res, item) ?: return
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
        Text(words, style = EchoV2.type.meta, color = EchoV2.colors.danger, modifier = Modifier.weight(1f))
        EchoButton(stringResource(R.string.v2_try_again), onTryAgain, kind = EchoButtonKind.Secondary)
    }
}

/** Nothing was heard: say what to do, with Run capture test and Delete (the same question as ⋮'s). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun NoAudioCard(onCaptureTest: () -> Unit, onDelete: () -> Unit) = EchoCard {
    Text(stringResource(R.string.v2_rec_no_audio_title), style = EchoV2.type.bodyStrong, color = EchoV2.colors.textPrimary)
    Text(stringResource(R.string.v2_rec_no_audio_body), style = EchoV2.type.body, color = EchoV2.colors.textSecondary)
    FlowRow(
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        EchoButton(stringResource(R.string.v2_rec_capture_test), onCaptureTest)
        EchoButton(stringResource(R.string.v2_rec_delete), onDelete, kind = EchoButtonKind.Secondary, icon = EchoIcons.Delete)
    }
}

/** Why a transcript failed, in the phone's own words for its error (classic's failureText; never the engine's). */
@Composable
fun failureWords(errorCode: String?): String = stringResource(
    when (errorCode) {
        "no_audio" -> R.string.v2_rec_fail_no_audio
        "budget_exceeded" -> R.string.v2_rec_fail_budget
        "provider_unreachable" -> R.string.v2_rec_fail_unreachable
        "provider_rejected" -> R.string.v2_rec_fail_rejected
        "provider_not_configured" -> R.string.v2_rec_fail_not_configured
        "provider_timeout" -> R.string.v2_rec_fail_timeout
        "interrupted" -> R.string.v2_rec_fail_interrupted
        "recording_missing" -> R.string.v2_rec_fail_missing
        "needs_transcription_key" -> R.string.v2_rec_fail_needs_key
        "own_key_rejected" -> R.string.v2_rec_fail_own_key
        else -> R.string.v2_rec_fail_other
    }
)

/**
 * A failed transcription (classic's): the reason, then Retry (the server starts again with the best engine), or "Check
 * your key" for a refused own key until a newer key was saved, or nothing to do for no audio or missing audio.
 */
@Composable
fun FailedBanner(t: TranscriptDto, checkKey: Boolean, onRetry: suspend () -> Unit, onCheckKey: () -> Unit) {
    val scope = rememberCoroutineScope()
    var retrying by remember(t.id) { mutableStateOf(false) }
    val retryable = t.errorCode !in setOf("no_audio", "recording_missing")
    EchoBanner(
        stringResource(R.string.v2_rec_failed_title) + ". " + failureWords(t.errorCode),
        EchoBannerKind.Error,
        actionLabel = when {
            checkKey -> stringResource(R.string.v2_rec_check_key)
            !retryable -> null
            retrying -> stringResource(R.string.v2_rec_retrying)
            else -> stringResource(R.string.v2_rec_retry)
        },
        onAction = {
            if (checkKey) {
                onCheckKey()
            } else if (!retrying) {
                retrying = true
                scope.launch {
                    onRetry()
                    retrying = false
                }
            }
        }
    )
}

/** Made by a backup engine: offer the best one (Re-transcribe in the transcript's language). */
@Composable
fun BackupBanner(onRetranscribe: suspend () -> Unit) {
    val scope = rememberCoroutineScope()
    var starting by remember { mutableStateOf(false) }
    EchoBanner(
        stringResource(R.string.v2_rec_backup),
        EchoBannerKind.Warning,
        actionLabel = stringResource(if (starting) R.string.v2_rec_starting else R.string.v2_rec_retranscribe_go),
        onAction = {
            if (!starting) {
                starting = true
                scope.launch {
                    onRetranscribe()
                    starting = false
                }
            }
        }
    )
}

/** Suggested fixes for the project's terms: each with its moment (tap to hear it), Accept and Dismiss. */
@Composable
fun SuggestedFixesCard(
    suggestions: List<SuggestionDto>,
    segments: List<SegmentDto>,
    onPlay: (Long) -> Unit,
    onDecide: (Int, Boolean) -> Unit
) {
    val c = EchoV2.colors
    EchoCard {
        Text(stringResource(R.string.v2_rec_fixes), style = EchoV2.type.bodyStrong, color = c.textPrimary)
        Text(stringResource(R.string.v2_rec_fixes_body), style = EchoV2.type.meta, color = c.textSecondary)
        suggestions.forEach { fix ->
            val at = segments.getOrNull(fix.index)?.startMs ?: 0L
            Column(Modifier.fillMaxWidth().padding(top = EchoTokens.Space.s8)) {
                Row(
                    Modifier.fillMaxWidth().clickable(role = Role.Button) { onPlay(at) },
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    val words = "“${fix.from}” → “${fix.to}”"
                    Text(words, style = EchoV2.type.content(words, EchoV2.type.body), color = c.textPrimary, modifier = Modifier.weight(1f))
                    Text(formatClock(at), style = EchoV2.type.mono, color = c.accentText)
                }
                if (fix.reason.isNotBlank()) {
                    Text(
                        fix.reason,
                        style = EchoV2.type.content(fix.reason, EchoV2.type.meta),
                        color = c.textSecondary
                    )
                }
                Row {
                    EchoButton(stringResource(R.string.v2_rec_accept), { onDecide(fix.id, true) }, kind = EchoButtonKind.Quiet)
                    EchoButton(stringResource(R.string.v2_rec_dismiss), { onDecide(fix.id, false) }, kind = EchoButtonKind.Quiet)
                }
            }
        }
    }
}

/**
 * Summary: the recording's call notes (classic's, for a recording in a project on a plan with AI notes): the notes with a
 * tap to hear each moment, written on asking (in English or Roman Urdu), followed while being written; otherwise why
 * there are none (no project, no transcript yet, or the plan).
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun SummaryPane(vm: MainViewModel, item: LibraryItem, ready: Boolean, notesOnPlan: Boolean, onPlay: (Long) -> Unit, onPlans: () -> Unit) {
    val c = EchoV2.colors
    val why = when {
        item.client == null -> R.string.v2_rec_notes_need_project
        !ready -> R.string.v2_rec_notes_need_transcript
        !notesOnPlan -> R.string.v2_rec_notes_locked
        else -> null
    }
    Column(
        Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        Text(stringResource(R.string.v2_rec_call_notes), style = EchoV2.type.titleM, color = c.textPrimary)
        if (why != null) {
            Text(stringResource(why), style = EchoV2.type.body, color = c.textSecondary)
            if (why ==
                R.string.v2_rec_notes_locked
            ) {
                EchoButton(stringResource(R.string.v2_rec_see_plans), onPlans, kind = EchoButtonKind.Secondary)
            }
            return@Column
        }
        val scope = rememberCoroutineScope()
        var notes by remember(item.id) { mutableStateOf<CallNotesDto?>(null) }
        var loaded by remember(item.id) { mutableStateOf(false) }
        var reload by remember { mutableIntStateOf(0) }
        LaunchedEffect(item.id, reload) {
            notes = vm.sessionNotes(item.id)
            loaded = true
        }
        LaunchedEffect(notes?.status) {
            if (notes?.status == "pending") {
                delay(RecordingPage.REFRESH_MS)
                reload++
            }
        }
        if (!loaded) return@Column
        notes?.let { note ->
            when (note.status) {
                "pending" -> Text(stringResource(R.string.v2_ws_notes_writing), style = EchoV2.type.meta, color = c.textSecondary)

                "failed", "skipped" -> Text(stringResource(R.string.v2_ws_notes_skipped), style = EchoV2.type.meta, color = c.textSecondary)

                else -> if (note.sections.isEmpty()) {
                    Text(stringResource(R.string.v2_ws_notes_nothing), style = EchoV2.type.meta, color = c.textSecondary)
                }
            }
            note.sections.forEach { section ->
                Text(section.heading, style = EchoV2.type.content(section.heading, EchoV2.type.captionStrong), color = c.textSecondary)
                section.items.forEach { line ->
                    Row(
                        Modifier.fillMaxWidth().clickable(role = Role.Button) { onPlay(line.atMs) },
                        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
                    ) {
                        Text(formatClock(line.atMs), style = EchoV2.type.mono, color = c.accentText)
                        Text(
                            line.text,
                            style = EchoV2.type.content(line.text, EchoV2.type.body),
                            color = c.textPrimary,
                            modifier = Modifier.weight(1f)
                        )
                        if (line.verify) Text(stringResource(R.string.v2_ws_verify), style = EchoV2.type.caption, color = c.warning)
                    }
                }
            }
        }
        if (notes == null || notes?.status in setOf("failed", "skipped")) {
            FlowRow(
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8),
                verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
            ) {
                EchoButton(stringResource(R.string.v2_rec_write_notes), {
                    scope.launch { vm.makeNotes(item.id, "en")?.let { notes = it } }
                }, kind = EchoButtonKind.Secondary)
                EchoButton(stringResource(R.string.v2_rec_write_notes_roman), {
                    scope.launch { vm.makeNotes(item.id, "roman_urdu")?.let { notes = it } }
                }, kind = EchoButtonKind.Quiet)
            }
        }
    }
}

/** Moments: the bookmarks (time and tag) and the screens, in time order; a tap plays from there or opens the screen. */
@Composable
fun MomentsPane(bookmarks: List<SessionMarkerDto>, screens: List<ScreenDto>, onPlay: (Long) -> Unit, onOpenScreen: (Int) -> Unit) {
    if (bookmarks.isEmpty() && screens.isEmpty()) {
        Text(
            stringResource(R.string.v2_rec_no_moments),
            style = EchoV2.type.body,
            color = EchoV2.colors.textSecondary,
            modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12)
        )
        return
    }
    val bookmark = stringResource(R.string.v2_rec_bookmark)
    val moments = bookmarks.map { Triple(it.atMs, listOfNotNull(bookmark, it.tag, it.note).joinToString(" · ")) { onPlay(it.atMs) } } +
        screens.mapIndexed { i, s -> Triple(s.atMs, s.description?.takeIf { it.isNotBlank() }.orEmpty()) { onOpenScreen(i) } }
    Column {
        moments.sortedBy { it.first }.forEach { (at, words, open) ->
            EchoSettingRow(
                words.ifBlank {
                    formatClock(at)
                },
                value = formatClock(at),
                icon = EchoIcons.Moment,
                content = true,
                onClick = open
            )
        }
    }
}
