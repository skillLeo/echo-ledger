package com.echoledger.mobile.ui.v2.assist

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.R
import com.echoledger.mobile.assist.AssistHub
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoStatus
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/** What Live Assist's start screen shows. */
sealed interface AssistStartUi {
    /** Nothing is being recorded: Live Assist follows a recording's words. */
    data object NeedsRecording : AssistStartUi

    /** A recording runs: Start now. [problem] says why the last start did not happen; [busy]: one runs already. */
    data class CanStart(val starting: Boolean = false, val problem: String? = null, val busy: Boolean = false) : AssistStartUi

    /** This phone's session runs. [cardAllowed]: the card can show over other apps. */
    data class On(val cardAllowed: Boolean) : AssistStartUi
}

/**
 * Live Assist's start screen (the Live tab's "Start Live Assist"): on the recording under way, Start now; while a session
 * runs, show its card or end it. The consent line is shown before a start, as on the card.
 */
@Composable
fun V2AssistStartScreen(
    ui: AssistStartUi,
    onBack: () -> Unit,
    onRecord: () -> Unit,
    onStart: (endOther: Boolean) -> Unit,
    onShowCard: () -> Unit,
    onAllowCard: () -> Unit,
    onEnd: () -> Unit,
    modifier: Modifier = Modifier
) {
    val c = EchoV2.colors
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_assist_title), onBack = onBack)
        Column(
            Modifier
                .weight(1f)
                .verticalScroll(rememberScrollState())
                .padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s16),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
        ) {
            when (ui) {
                AssistStartUi.NeedsRecording -> {
                    Body(stringResource(R.string.v2_assist_start_intro))
                    Body(stringResource(R.string.v2_assist_start_needs_recording))
                    EchoButton(stringResource(R.string.v2_assist_start_record), onRecord, Modifier.fillMaxWidth())
                }

                is AssistStartUi.CanStart -> {
                    Body(stringResource(R.string.v2_assist_start_intro))
                    Text(stringResource(R.string.v2_assist_consent_label), style = EchoV2.type.label, color = c.textSecondary)
                    Body(stringResource(R.string.v2_assist_consent))
                    ui.problem?.let { Text(it, style = EchoV2.type.body, color = c.danger) }
                    if (ui.busy) {
                        Body(stringResource(R.string.v2_assist_start_busy))
                        EchoButton(
                            stringResource(R.string.v2_assist_start_end_other),
                            { onStart(true) },
                            Modifier.fillMaxWidth(),
                            loading = ui.starting,
                            loadingText = stringResource(R.string.v2_assist_start_starting)
                        )
                    } else {
                        EchoButton(
                            stringResource(R.string.v2_assist_start_now),
                            { onStart(false) },
                            Modifier.fillMaxWidth(),
                            loading = ui.starting,
                            loadingText = stringResource(R.string.v2_assist_start_starting)
                        )
                    }
                }

                is AssistStartUi.On -> {
                    EchoStatus(stringResource(R.string.v2_assist_start_on), EchoStatusKind.Success)
                    Body(stringResource(R.string.v2_assist_start_on_help))
                    if (ui.cardAllowed) {
                        EchoButton(stringResource(R.string.v2_assist_start_show_card), onShowCard, Modifier.fillMaxWidth())
                    } else {
                        Body(stringResource(R.string.v2_assist_start_no_overlay))
                        EchoButton(stringResource(R.string.v2_assist_start_allow), onAllowCard, Modifier.fillMaxWidth())
                    }
                    EchoButton(
                        stringResource(R.string.v2_assist_start_end),
                        onEnd,
                        Modifier.fillMaxWidth(),
                        kind = EchoButtonKind.Secondary
                    )
                }
            }
        }
    }
}

@Composable
private fun Body(text: String) {
    Text(text, style = EchoV2.type.body, color = EchoV2.colors.textPrimary)
}

/**
 * The start screen on [AssistHub]: [start] asks the server (with the phone's API and the relay's pins), [cardAllowed]
 * says whether the card may show over other apps, [showCard] shows it.
 */
@Composable
fun V2AssistStart(
    onBack: () -> Unit,
    onRecord: () -> Unit,
    start: suspend (endOther: Boolean) -> AssistHub.Start,
    cardAllowed: () -> Boolean,
    showCard: () -> Unit,
    onAllowCard: () -> Unit
) {
    val recording by AssistHub.recordingSessionId.collectAsState()
    val active by AssistHub.active.collectAsState()
    var starting by remember { mutableStateOf(false) }
    var problem by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val notSetUp = stringResource(R.string.v2_assist_error_not_set_up)
    val ui = when {
        active != null -> AssistStartUi.On(cardAllowed())
        recording == null -> AssistStartUi.NeedsRecording
        else -> AssistStartUi.CanStart(starting, problem, busy)
    }
    V2AssistStartScreen(
        ui = ui,
        onBack = onBack,
        onRecord = onRecord,
        onStart = { endOther ->
            if (!starting) {
                starting = true
                scope.launch {
                    val result = start(endOther)
                    starting = false
                    busy = result is AssistHub.Start.Busy
                    problem = when (result) {
                        is AssistHub.Start.Started -> null.also { showCard() }
                        AssistHub.Start.NotSetUp -> notSetUp
                        is AssistHub.Start.Refused -> result.words
                        is AssistHub.Start.Failed -> result.words
                        AssistHub.Start.Busy, AssistHub.Start.NoRecording -> null
                    }
                }
            }
        },
        onShowCard = showCard,
        onAllowCard = onAllowCard,
        onEnd = AssistHub::end
    )
}
