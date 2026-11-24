package com.echoledger.mobile.ui.v2.shell

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.minimumInteractiveComponentSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.R
import com.echoledger.mobile.core.ReadyChip
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoCountBadge
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * The Record button in the middle of the bottom bar (docs/PLAN_V2_PARITY.md section 2): the accent colour, as in
 * classic. A tap records a voice note now (or stops the recording that runs); a long press opens the Record sheet.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun EchoRecordButton(recording: Boolean, onTap: () -> Unit, onLongPress: () -> Unit, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    // The button keeps its fixed colours with Wallpaper colours on, as classic's Record button.
    val fixed = if (c.isDark) com.echoledger.mobile.ui.v2.theme.EchoV2Colors.Dark else com.echoledger.mobile.ui.v2.theme.EchoV2Colors.Light
    val name = stringResource(if (recording) R.string.v2_nav_stop else R.string.v2_nav_record)
    val more = stringResource(R.string.v2_nav_record_more)
    Box(
        modifier
            .size(EchoTokens.Component.recordButton)
            .background(if (recording) c.record else fixed.accent, CircleShape)
            .combinedClickable(
                role = Role.Button,
                onClickLabel = name,
                onLongClickLabel = more,
                onLongClick = onLongPress,
                onClick = onTap
            )
            .semantics { contentDescription = name },
        contentAlignment = Alignment.Center
    ) {
        Icon(if (recording) EchoIcons.Stop else EchoIcons.Mic, null, Modifier.size(EchoTokens.Icon.lg), tint = fixed.textOnAccent)
    }
}

/**
 * While a recording runs, above the bar (classic's recording strip): the dot, "Recording" and the time, and Stop; a
 * tap elsewhere opens the voice page. It alone reads the time, so nothing else redraws each second.
 */
@Composable
fun RecordingStrip(onStop: () -> Unit, onOpen: () -> Unit, modifier: Modifier = Modifier) {
    val live by com.echoledger.mobile.recording.RecordingService.live.collectAsState()
    val c = EchoV2.colors
    val recordingWords = stringResource(R.string.v2_nav_recording)
    val openWords = stringResource(R.string.v2_nav_open_recording)
    Row(
        modifier
            .fillMaxWidth()
            .heightIn(min = EchoTokens.Layout.controlTouchMin)
            .background(c.dangerSoft)
            .combinedClickable(role = Role.Button, onClickLabel = openWords, onClick = onOpen)
            .padding(start = EchoTokens.Layout.pagePaddingMobile),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        com.echoledger.mobile.ui.v2.components.EchoRecordingDot(live = !live.paused)
        Text(recordingWords, style = EchoV2.type.label, color = c.textPrimary)
        Text(
            "\u2066" + com.echoledger.mobile.ui.v2.components.formatClock(live.elapsedMs) + "\u2069",
            style = EchoV2.type.mono,
            color = c.textPrimary,
            modifier = Modifier.weight(1f)
        )
        EchoIconButton(EchoIcons.Stop, stringResource(R.string.v2_nav_stop), onStop)
    }
}

/** Library's "Calls: Ready" chip, as classic's header has it; nothing where this phone can't record calls. */
@Composable
fun CallsChip(chip: ReadyChip, onClick: () -> Unit, modifier: Modifier = Modifier) {
    if (chip == ReadyChip.NONE) return
    val c = EchoV2.colors
    val (words, dot) = when (chip) {
        ReadyChip.READY -> stringResource(R.string.v2_calls_ready) to c.success
        ReadyChip.NOT_READY -> stringResource(R.string.v2_calls_not_ready) to c.warning
        else -> stringResource(R.string.v2_calls_off) to c.textSecondary
    }
    Row(
        modifier
            // 48 dp to touch, however small the chip draws.
            .minimumInteractiveComponentSize()
            .background(c.bgSubtle, RoundedCornerShape(EchoTokens.Component.chipRadius))
            .combinedClickable(role = Role.Button, onClick = onClick)
            .padding(horizontal = EchoTokens.Space.s12, vertical = EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Box(Modifier.size(EchoTokens.Space.s8).background(dot, CircleShape))
        Text(
            words,
            style = EchoV2.type.captionStrong,
            color = c.textPrimary,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.padding(start = EchoTokens.Space.s8)
        )
    }
}

/** Activity as a bell in Library's header, with what needs the person as its badge ("9+" above 9). */
@Composable
fun ActivityBell(count: Int, onClick: () -> Unit) {
    val name = if (count >
        0
    ) {
        androidx.compose.ui.res.pluralStringResource(R.plurals.v2_nav_activity_n, count, count)
    } else {
        stringResource(R.string.v2_nav_activity)
    }
    Box(contentAlignment = Alignment.TopEnd) {
        EchoIconButton(EchoIcons.Bell, name, onClick)
        if (count > 0) {
            Box(Modifier.padding(top = EchoTokens.Space.s4, end = EchoTokens.Space.s4)) {
                EchoCountBadge(count, name, Modifier.clearAndSetSemantics { }, color = EchoV2.colors.record, cap = 9)
            }
        }
    }
}

/** What the "New" sheet shows (v2.1 UX-15: actions only; the calls' and Live Translate's states are on the Live tab). */
class RecordSheetState(
    val liveAssist: Boolean,
    val liveTranslate: Boolean,
    /** The plan's badge when Live Translate is not in the plan (it then opens the plans). */
    val translateLocked: String?
)

/** Where the "New" sheet's rows go. */
class RecordSheetActions(
    val onRecordNow: () -> Unit,
    val onVoiceNote: () -> Unit,
    /** Import audio: the voice page's file picker, open at once. */
    val onImport: () -> Unit,
    val onLiveAssist: () -> Unit,
    val onDictation: () -> Unit,
    /** Translate a game or video: where both are, with their state (the Live tab). */
    val onTranslate: () -> Unit,
    val onLocked: () -> Unit
)

/**
 * The "New" sheet (a long press on Record, v2.1 UX-15): actions only, at most six, each also reachable without the long
 * press: Record now (the big one; a tap on Record does the same), Voice note, Import audio, Start Live Assist (for this
 * person's flag), Dictation, and Translate a game or video (or one row with the plan's badge). The auto-record switch
 * and the statuses are on the Live tab and in Settings.
 */
@Composable
fun V2RecordSheet(state: RecordSheetState, actions: RecordSheetActions, onDismiss: () -> Unit) {
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_rs_title)) {
        // The rows scroll: on a narrow phone with large text they are taller than the screen.
        androidx.compose.foundation.layout.Column(
            Modifier.verticalScroll(androidx.compose.foundation.rememberScrollState())
        ) { V2RecordSheetBody(state, actions, onDismiss) }
    }
}

/** The sheet's rows (also drawn on their own in tests and pictures). */
@Composable
fun V2RecordSheetBody(state: RecordSheetState, actions: RecordSheetActions, onDismiss: () -> Unit) {
    fun go(run: () -> Unit) = {
        onDismiss()
        run()
    }
    EchoButton(
        stringResource(R.string.v2_rs_record_now),
        go(actions.onRecordNow),
        Modifier
            .padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
            .fillMaxWidth()
            .heightIn(min = EchoTokens.Component.bigButtonHeight),
        icon = EchoIcons.Mic
    )
    Text(
        stringResource(R.string.v2_rs_record_now_help),
        style = EchoV2.type.caption,
        color = EchoV2.colors.textSecondary,
        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
    )
    EchoSettingRow(stringResource(R.string.v2_rs_voice_note), icon = EchoIcons.VoiceNote, onClick = go(actions.onVoiceNote))
    EchoSettingRow(stringResource(R.string.v2_rs_import), icon = EchoIcons.Upload, onClick = go(actions.onImport))
    if (state.liveAssist) {
        EchoSettingRow(stringResource(R.string.v2_rs_start_assist), icon = EchoIcons.Assist, onClick = go(actions.onLiveAssist))
    }
    // The same icons as the Live tab's rows: the mic is Voice note's alone.
    EchoSettingRow(stringResource(R.string.v2_rs_dictation), icon = EchoIcons.Transcribe, onClick = go(actions.onDictation))
    if (state.liveTranslate || state.translateLocked != null) {
        EchoSettingRow(
            stringResource(R.string.v2_rs_translate_any),
            subtitle = state.translateLocked.takeIf { !state.liveTranslate },
            icon = EchoIcons.Translate,
            onClick = go(if (state.liveTranslate) actions.onTranslate else actions.onLocked)
        )
    }
}

/** The person's initials and what their avatar opens (Settings), for every main screen's top bar. */
class AccountEntry(val initials: String, val onOpen: () -> Unit)

val LocalAccountEntry = androidx.compose.runtime.staticCompositionLocalOf<AccountEntry?> { null }

/** The avatar at the top right of a main screen: it opens Settings. Nothing outside the shell. */
@Composable
fun AccountButton() {
    val entry = LocalAccountEntry.current ?: return
    val words = v2Text.accountAndSettings
    Box(
        Modifier
            .size(EchoTokens.Layout.controlTouchMin)
            .combinedClickable(role = Role.Button, onClickLabel = words, onClick = entry.onOpen)
            .semantics { contentDescription = words },
        contentAlignment = Alignment.Center
    ) { PersonAvatar(entry.initials) }
}
