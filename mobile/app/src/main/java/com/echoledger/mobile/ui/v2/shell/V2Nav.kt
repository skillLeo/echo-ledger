package com.echoledger.mobile.ui.v2.shell

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
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
import com.echoledger.mobile.privilege.AppCallCapture
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoCountBadge
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSwitch
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
    val name = stringResource(if (recording) R.string.v2_nav_stop else R.string.v2_nav_record)
    val more = stringResource(R.string.v2_nav_record_more)
    Box(
        modifier
            .size(EchoTokens.Component.recordButton)
            .background(if (recording) c.record else c.accent, CircleShape)
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
        Icon(if (recording) EchoIcons.Stop else EchoIcons.Mic, null, Modifier.size(EchoTokens.Icon.lg), tint = c.textOnAccent)
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
    val name = if (count > 0) stringResource(R.string.v2_nav_activity_n, count) else stringResource(R.string.v2_nav_activity)
    Box(contentAlignment = Alignment.TopEnd) {
        EchoIconButton(EchoIcons.Bell, name, onClick)
        if (count > 0) {
            Box(Modifier.padding(top = EchoTokens.Space.s4, end = EchoTokens.Space.s4)) {
                EchoCountBadge(count, name, Modifier.clearAndSetSemantics { }, color = EchoV2.colors.record, cap = 9)
            }
        }
    }
}

/** What the Record sheet shows. */
class RecordSheetState(
    val calls: AppCallCapture,
    val armed: Boolean,
    val canArm: Boolean,
    val liveAssist: Boolean,
    val liveTranslate: Boolean,
    /** The plan's badge when Live Translate is not in the plan (it then opens the plans). */
    val translateLocked: String?,
    val game: Boolean,
    val watch: Boolean
)

/** Where the Record sheet's rows go. */
class RecordSheetActions(
    val onRecordNow: () -> Unit,
    val onVoiceAndFiles: () -> Unit,
    val onCalls: () -> Unit,
    val onArm: (Boolean) -> Unit,
    val onLiveAssist: () -> Unit,
    val onDictation: () -> Unit,
    val onLiveTranslate: () -> Unit,
    val onLocked: () -> Unit
)

/**
 * The Record sheet (a long press on Record): Record now, Voice & files, auto-record calls on or off with the calls'
 * status ("Calls: ready"), Calls & meetings, Live Assist (for this person's flag), Dictation and Live Translate (game and
 * watch on or off, or the plan's badge).
 */
@Composable
fun V2RecordSheet(state: RecordSheetState, actions: RecordSheetActions, onDismiss: () -> Unit) {
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_rs_title)) {
        V2RecordSheetBody(state, actions, onDismiss)
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
        Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile).fillMaxWidth(),
        icon = EchoIcons.Mic
    )
    Text(
        stringResource(R.string.v2_rs_record_now_help),
        style = EchoV2.type.caption,
        color = EchoV2.colors.textSecondary,
        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
    )
    EchoSettingRow(
        stringResource(R.string.v2_rs_voice),
        subtitle = stringResource(R.string.v2_rs_voice_help),
        icon = EchoIcons.Upload,
        onClick = go(actions.onVoiceAndFiles)
    )
    val calls = stringResource(
        when (state.calls) {
            AppCallCapture.NOT_IN_THIS_BUILD -> R.string.v2_rs_calls_web
            AppCallCapture.NONE -> R.string.v2_rs_calls_android
            AppCallCapture.NEEDS_HELPER, AppCallCapture.VOICE_ONLY_NEEDS_HELPER -> R.string.v2_rs_calls_setup
            AppCallCapture.VOICE_ONLY -> R.string.v2_rs_calls_voice
            AppCallCapture.BOTH_SIDES -> if (state.armed) R.string.v2_rs_calls_armed else R.string.v2_rs_calls_ready
        }
    )
    EchoSwitch(
        checked = state.armed,
        onCheckedChange = actions.onArm,
        label = stringResource(R.string.v2_rs_calls),
        supporting = calls,
        enabled = state.canArm
    )
    EchoSettingRow(stringResource(R.string.v2_rs_calls_open), icon = EchoIcons.PhoneCall, onClick = go(actions.onCalls))
    if (state.liveAssist) {
        EchoSettingRow(
            stringResource(R.string.v2_rs_assist),
            subtitle = stringResource(R.string.v2_rs_assist_help),
            icon = EchoIcons.Assist,
            onClick = go(actions.onLiveAssist)
        )
    }
    EchoSettingRow(
        stringResource(R.string.v2_rs_dictation),
        subtitle = stringResource(R.string.v2_rs_dictation_help),
        icon = EchoIcons.Dictation,
        onClick = go(actions.onDictation)
    )
    if (state.liveTranslate || state.translateLocked != null) {
        val on = stringResource(R.string.v2_rs_on)
        val off = stringResource(R.string.v2_rs_off)
        EchoSettingRow(
            stringResource(R.string.v2_rs_translate),
            subtitle = state.translateLocked
                ?: stringResource(R.string.v2_rs_translate_status, if (state.game) on else off, if (state.watch) on else off),
            icon = EchoIcons.Translate,
            onClick = go(if (state.liveTranslate) actions.onLiveTranslate else actions.onLocked)
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
    ) { com.echoledger.mobile.ui.v2.components.EchoAvatar(initials = entry.initials) }
}
