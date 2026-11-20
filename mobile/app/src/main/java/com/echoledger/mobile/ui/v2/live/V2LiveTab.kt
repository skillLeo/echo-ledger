package com.echoledger.mobile.ui.v2.live

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
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

/** What the Live tab opens (docs/v2/IA.md 3). */
enum class LiveEntry { Record, VoiceNote, LiveAssist, LiveTranslate, Dictation, Captions }

/**
 * The Live tab v2 (behind `ui_v2.live`): everything during a call or session, as plain rows. Live Assist shows only
 * while `live_assist` is on for this person; Live Translate only where the build has it. Each row opens its screen.
 */
@Composable
fun V2LiveTab(liveAssist: Boolean, liveTranslate: Boolean, onOpen: (LiveEntry) -> Unit, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_livetab_title), large = true) { com.echoledger.mobile.ui.v2.shell.AccountButton() }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
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
                    icon = EchoIcons.Ask,
                    onClick = { onOpen(LiveEntry.LiveAssist) }
                )
            }
            EchoSectionHeader(stringResource(R.string.v2_livetab_tools_section))
            if (liveTranslate) {
                EchoSettingRow(
                    stringResource(R.string.v2_livetab_translate),
                    subtitle = stringResource(R.string.v2_livetab_translate_help),
                    icon = EchoIcons.Live,
                    onClick = { onOpen(LiveEntry.LiveTranslate) }
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
