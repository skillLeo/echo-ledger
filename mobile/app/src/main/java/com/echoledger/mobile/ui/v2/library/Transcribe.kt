package com.echoledger.mobile.ui.v2.library

import android.content.res.Resources
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.R
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.TranscribeEstimateAnswer
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.MainViewModel.TranscribeOutcome
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import kotlinx.coroutines.launch

/** Transcribe the selection, asked with what it uses (the server's estimate of the audio-only ones among it). */
data class TranscribeAsk(val ids: List<String>, val estimate: TranscribeEstimateAnswer) {
    /** The month's minutes left can't cover it: the server would start nothing. */
    val tooMany: Boolean get() = estimate.minutesLeft?.let { it < estimate.minutes } == true
}

/** What transcribing uses, in the screen's words: none, N minutes, or N minutes with what is left this month. */
fun minutesWords(res: Resources, minutes: Long, left: Long?): String = when {
    minutes <= 0L -> res.getString(R.string.v2_tx_uses_none)
    left == null -> res.getQuantityString(R.plurals.v2_tx_uses, minutes.toInt(), minutes.toInt())
    else -> res.getQuantityString(R.plurals.v2_tx_uses_left, minutes.toInt(), minutes.toInt(), left.toInt())
}

/** A Transcribe's outcome in the screen's words. */
fun transcribeWords(res: Resources, outcome: TranscribeOutcome): String = when (outcome) {
    is TranscribeOutcome.Started -> listOfNotNull(
        res.getQuantityString(R.plurals.v2_tx_started, outcome.started.size, outcome.started.size).takeIf { outcome.started.isNotEmpty() },
        res.getQuantityString(R.plurals.v2_tx_skipped, outcome.skipped.size, outcome.skipped.size).takeIf { outcome.skipped.isNotEmpty() }
    ).joinToString(" ").ifEmpty { res.getString(R.string.v2_tx_none) }

    is TranscribeOutcome.Budget -> if (outcome.needed != null && outcome.left != null) {
        res.getString(R.string.v2_tx_budget, outcome.needed.toInt(), outcome.left.toInt())
    } else {
        res.getString(R.string.v2_tx_budget_short)
    }

    TranscribeOutcome.NeedsKey -> res.getString(R.string.v2_tx_needs_key)

    TranscribeOutcome.NotAudioOnly -> res.getString(R.string.v2_tx_not_audio_only)

    is TranscribeOutcome.Failed -> outcome.words ?: res.getString(R.string.v2_tx_failed)
}

/** "Transcribe 2 recordings?" with the minutes it uses; when the month's minutes can't cover it, it says so instead. */
@Composable
fun TranscribeDialog(ask: TranscribeAsk, onDismiss: () -> Unit, onConfirm: () -> Unit) {
    val res = LocalContext.current.resources
    val n = ask.ids.size
    val e = ask.estimate
    if (ask.tooMany) {
        EchoDialog(
            title = stringResource(R.string.v2_tx_not_enough_title),
            text = res.getString(R.string.v2_tx_budget, e.minutes.toInt(), (e.minutesLeft ?: 0).toInt()),
            confirmLabel = stringResource(R.string.v2_tx_ok),
            onConfirm = onDismiss,
            // Nothing can start: OK only.
            dismissLabel = null,
            onDismiss = onDismiss
        )
        return
    }
    EchoDialog(
        title = res.getQuantityString(R.plurals.v2_tx_ask_title, n, n),
        text = minutesWords(res, e.minutes, e.minutesLeft),
        confirmLabel = stringResource(R.string.v2_tx_go),
        onConfirm = onConfirm,
        dismissLabel = stringResource(R.string.v2_cancel),
        onDismiss = onDismiss
    )
}

/**
 * The recording page of an audio-only recording, in place of its transcript: kept as audio, what transcribing would
 * use (the item's `transcribe_estimate`), and Transcribe, which starts it now.
 */
@Composable
fun AudioOnlyCard(
    vm: MainViewModel,
    item: LibraryItem,
    /** The page's scope: the card goes once the transcript starts, the toast after it must not go with it. */
    scope: kotlinx.coroutines.CoroutineScope,
    onStarted: () -> Unit
) {
    val res = LocalContext.current.resources
    val toasts = LocalEchoToasts.current
    var working by remember { mutableStateOf(false) }
    val estimate = item.transcribeEstimate
    EchoEmptyState(
        stringResource(R.string.v2_tx_audio_only_title),
        listOfNotNull(
            stringResource(R.string.v2_tx_audio_only_body),
            estimate?.let { minutesWords(res, it.minutes, it.minutesLeft) }
        ).joinToString(" "),
        icon = EchoIcons.Transcribe,
        actionLabel = stringResource(if (working) R.string.v2_rec_starting else R.string.v2_tx_go),
        onAction = {
            if (working) return@EchoEmptyState
            working = true
            scope.launch {
                val outcome = vm.transcribeOne(item.id)
                working = false
                toasts.show(transcribeWords(res, outcome))
                if (outcome is TranscribeOutcome.Started) onStarted()
            }
        }
    )
}
