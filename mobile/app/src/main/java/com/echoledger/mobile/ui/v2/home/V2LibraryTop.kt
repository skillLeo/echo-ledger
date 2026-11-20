package com.echoledger.mobile.ui.v2.home

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.R
import com.echoledger.mobile.core.ReadyChip
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCard
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** Where the top of Library leads. */
class LibraryTopActions(
    val onSetup: () -> Unit,
    val onAllow: () -> Unit,
    val onRecord: () -> Unit,
    val onChooseLanguage: () -> Unit,
    val onHideSetup: () -> Unit,
    /** Calls are not being recorded: the Ready check. */
    val onFix: () -> Unit,
    /** Opens Activity, where everything that needs the person is listed. */
    val onReview: () -> Unit,
    val onRetryUploads: () -> Unit
)

/** The one thing the Needs action banner says, and how many more there are. */
data class NeedsBanner(val kind: Kind, val count: Int, val more: Int) {
    enum class Kind { CallsOff, Waiting, FailedUploads, FailedTranscripts }
}

object LibraryTop {
    /**
     * The banner (most urgent first: calls not recorded, calls waiting, uploads, transcripts), or none. While the setup
     * card shows its "Call recording ready" row ([setupShowsCapture]), calls not recorded is left to that row and the
     * Calls chip, so the page says it once.
     */
    fun banner(chip: ReadyChip, needs: List<NeedsAction>, setupShowsCapture: Boolean = false): NeedsBanner? {
        val all = buildList {
            if (chip == ReadyChip.NOT_READY && !setupShowsCapture) add(NeedsBanner.Kind.CallsOff to 1)
            needs.filterIsInstance<NeedsAction.WaitingDecision>().size.takeIf { it > 0 }?.let { add(NeedsBanner.Kind.Waiting to it) }
            needs.filterIsInstance<NeedsAction.FailedUploads>().firstOrNull()?.let { add(NeedsBanner.Kind.FailedUploads to it.ids.size) }
            needs.filterIsInstance<NeedsAction.FailedTranscripts>().firstOrNull()?.let {
                add(NeedsBanner.Kind.FailedTranscripts to it.count)
            }
        }
        val (kind, count) = all.firstOrNull() ?: return null
        return NeedsBanner(kind, count, all.size - 1)
    }
}

/**
 * The top of Library (docs/PLAN_V2_PARITY.md section 2): the setup checklist as a card that can be hidden, until setup
 * is done, then one quiet Needs action banner with its one button (Fix, Review or Retry).
 */
@Composable
fun V2LibraryTop(checklist: List<Pair<ChecklistItem, Boolean>>, setupHidden: Boolean, banner: NeedsBanner?, actions: LibraryTopActions) {
    Column {
        if (checklist.isNotEmpty() && !setupHidden) SetupCard(checklist, actions)
        banner?.let { NeedsLine(it, actions) }
    }
}

@Composable
private fun NeedsLine(banner: NeedsBanner, actions: LibraryTopActions) {
    val res = LocalContext.current.resources
    val words = when (banner.kind) {
        NeedsBanner.Kind.CallsOff -> res.getString(R.string.v2_lib_need_calls_off)

        NeedsBanner.Kind.Waiting -> if (banner.count == 1) {
            res.getString(R.string.v2_lib_need_waiting_one)
        } else {
            res.getString(R.string.v2_lib_need_waiting, banner.count)
        }

        NeedsBanner.Kind.FailedUploads -> if (banner.count == 1) {
            res.getString(R.string.v2_home_failed_upload_one)
        } else {
            res.getString(R.string.v2_home_failed_uploads, banner.count)
        }

        NeedsBanner.Kind.FailedTranscripts -> if (banner.count == 1) {
            res.getString(R.string.v2_home_failed_transcript_one)
        } else {
            res.getString(R.string.v2_home_failed_transcripts, banner.count)
        }
    }
    val (label, run) = when (banner.kind) {
        NeedsBanner.Kind.CallsOff -> res.getString(R.string.v2_lib_need_fix) to actions.onFix

        NeedsBanner.Kind.FailedUploads -> if (banner.more ==
            0
        ) {
            res.getString(R.string.v2_home_retry) to actions.onRetryUploads
        } else {
            res.getString(R.string.v2_lib_need_review) to
                actions.onReview
        }

        else -> res.getString(R.string.v2_lib_need_review) to actions.onReview
    }
    EchoBanner(
        if (banner.more > 0) res.getString(R.string.v2_lib_need_more, words, banner.more) else words,
        if (banner.kind == NeedsBanner.Kind.CallsOff) EchoBannerKind.Warning else EchoBannerKind.Info,
        Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
        actionLabel = label,
        onAction = run
    )
}

/** Whether the setup card is on screen with its "Call recording ready" row still to do. */
fun setupShowsCapture(checklist: List<Pair<ChecklistItem, Boolean>>, setupHidden: Boolean): Boolean =
    !setupHidden && checklist.any { (item, done) -> item == ChecklistItem.Capture && !done }

/** "Finish setting up": what is done and what is left, each with its one action; Hide puts it away. */
@Composable
private fun SetupCard(items: List<Pair<ChecklistItem, Boolean>>, actions: LibraryTopActions) {
    val c = EchoV2.colors
    EchoCard(Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(
                    stringResource(R.string.v2_lib_setup_title),
                    style = EchoV2.type.bodyStrong,
                    color = c.textPrimary,
                    modifier = Modifier.semantics { heading() }
                )
                Text(
                    stringResource(R.string.v2_home_get_started_progress, items.count { it.second }, items.size),
                    style = EchoV2.type.caption,
                    color = c.textSecondary
                )
            }
            EchoIconButton(EchoIcons.Close, stringResource(R.string.v2_lib_setup_hide), actions.onHideSetup)
        }
        items.forEach { (item, done) ->
            val (title, action, run) = when (item) {
                ChecklistItem.Capture -> Triple(R.string.v2_home_check_capture, R.string.v2_home_action_set_up, actions.onSetup)

                ChecklistItem.Permissions -> Triple(R.string.v2_home_check_permissions, R.string.v2_home_action_allow, actions.onAllow)

                ChecklistItem.FirstRecording -> Triple(
                    R.string.v2_home_check_first_recording,
                    R.string.v2_home_action_record,
                    actions.onRecord
                )

                ChecklistItem.Language -> Triple(R.string.v2_home_check_language, R.string.v2_home_action_choose, actions.onChooseLanguage)
            }
            Row(
                Modifier.fillMaxWidth().padding(vertical = EchoTokens.Space.s4),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
            ) {
                Icon(
                    if (done) EchoIcons.Check else EchoIcons.Todo,
                    null,
                    Modifier.size(EchoTokens.Icon.md),
                    tint = if (done) c.success else c.textSecondary
                )
                Text(
                    stringResource(title),
                    style = EchoV2.type.body,
                    color = if (done) c.textSecondary else c.textPrimary,
                    modifier = Modifier.weight(1f)
                )
                if (!done) EchoButton(stringResource(action), run, kind = EchoButtonKind.Secondary)
            }
        }
    }
}
