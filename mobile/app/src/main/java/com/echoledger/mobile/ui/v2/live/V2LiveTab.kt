package com.echoledger.mobile.ui.v2.live

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.R
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * What the Live tab opens (docs/v2/IA.md 3); [LiveTranslate] is Game translate and [WatchTranslate] Watch translate, as
 * classic's tiles.
 */
enum class LiveEntry { Record, VoiceNote, LiveAssist, LiveTranslate, WatchTranslate, Dictation, Captions, ImportAudio }

/**
 * What the Live tab says of the calls and Live Translate (v2.1 UX-15: they left the New sheet): auto-record on or off
 * with the calls' status, and Game and Watch translate on or off (or why the service refused).
 */
class LiveStatus(
    val armed: Boolean,
    val canArm: Boolean,
    val calls: String,
    val onArm: (Boolean) -> Unit,
    val game: String? = null,
    val watch: String? = null
)

/**
 * The Live tab v2 (behind `ui_v2.live`): everything during a call or session, as plain rows. Live Assist shows only
 * while `live_assist` is on for this person; Live Translate only where the build has it. Each row opens its screen.
 */
@Composable
fun V2LiveTab(
    liveAssist: Boolean,
    liveTranslate: Boolean,
    onOpen: (LiveEntry) -> Unit,
    modifier: Modifier = Modifier,
    status: LiveStatus? = null
) {
    Column(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_livetab_title), large = true) { com.echoledger.mobile.ui.v2.shell.AccountButton() }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            // Auto-record calls, with how calls stand (it was in the New sheet).
            status?.let { s ->
                // Its icon in the same column as the rows' icons below.
                androidx.compose.foundation.layout.Row(
                    Modifier.padding(start = EchoTokens.Layout.pagePaddingMobile),
                    verticalAlignment = androidx.compose.ui.Alignment.CenterVertically
                ) {
                    androidx.compose.material3.Icon(
                        EchoIcons.AutoRecordCalls,
                        null,
                        Modifier.size(EchoTokens.Icon.lg),
                        tint = if (s.canArm) EchoV2.colors.textSecondary else EchoV2.colors.textDisabled
                    )
                    com.echoledger.mobile.ui.v2.components.EchoSwitch(
                        checked = s.armed,
                        onCheckedChange = s.onArm,
                        label = stringResource(R.string.v2_rs_calls),
                        modifier = Modifier.weight(1f),
                        supporting = s.calls,
                        enabled = s.canArm
                    )
                }
            }
            EchoSettingRow(
                stringResource(R.string.v2_livetab_record),
                subtitle = stringResource(R.string.v2_livetab_record_help),
                icon = EchoIcons.Record,
                onClick = { onOpen(LiveEntry.Record) }
            )
            EchoSettingRow(
                stringResource(R.string.v2_livetab_voice_note),
                subtitle = stringResource(R.string.v2_livetab_voice_note_help),
                icon = EchoIcons.VoiceNote,
                onClick = { onOpen(LiveEntry.VoiceNote) }
            )
            if (liveAssist) {
                EchoSectionHeader(stringResource(R.string.v2_livetab_assist_section))
                EchoSettingRow(
                    stringResource(R.string.v2_assist_start),
                    subtitle = stringResource(R.string.v2_assist_start_help),
                    icon = EchoIcons.Assist,
                    onClick = { onOpen(LiveEntry.LiveAssist) }
                )
            }
            EchoSectionHeader(stringResource(R.string.v2_livetab_tools_section))
            if (liveTranslate) {
                EchoSettingRow(
                    stringResource(R.string.v2_rs_game),
                    subtitle = status?.game ?: stringResource(R.string.v2_livetab_game_help),
                    icon = EchoIcons.Games,
                    onClick = { onOpen(LiveEntry.LiveTranslate) }
                )
                EchoSettingRow(
                    stringResource(R.string.v2_rs_watch),
                    subtitle = status?.watch ?: stringResource(R.string.v2_livetab_watch_help),
                    icon = EchoIcons.Videos,
                    onClick = { onOpen(LiveEntry.WatchTranslate) }
                )
            }
            EchoSettingRow(
                stringResource(R.string.v2_livetab_dictation),
                subtitle = stringResource(R.string.v2_livetab_dictation_help),
                icon = EchoIcons.Transcribe,
                onClick = { onOpen(LiveEntry.Dictation) }
            )
            EchoSettingRow(
                stringResource(R.string.v2_livetab_captions),
                subtitle = stringResource(R.string.v2_livetab_captions_help),
                icon = EchoIcons.Captions,
                onClick = { onOpen(LiveEntry.Captions) }
            )
        }
    }
}
