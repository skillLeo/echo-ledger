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
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
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
import com.echoledger.mobile.ui.v2.components.LocalPageMargin
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
    val onRetryUploads: () -> Unit,
    /** A server banner's Update: the update check (the website build) or the store page. */
    val onUpdate: () -> Unit = {},
    /** A server announcement's ×: closed on this phone until the text changes. */
    val onCloseAnnouncement: (String) -> Unit = {}
)

/** The one thing the Needs action banner says, and how many more there are. */
data class NeedsBanner(
    val kind: Kind,
    val count: Int,
    val more: Int,
    /** Why calls are not recorded, as Setup & health words it (a string resource); null when unknown. */
    val reason: Int? = null
) {
    enum class Kind { CallsOff, Waiting, FailedUploads, FailedTranscripts }
}

object LibraryTop {
    /**
     * The banner (most urgent first: calls not recorded, calls waiting, uploads, transcripts), or none. While the setup
     * card shows its "Call recording ready" row ([setupShowsCapture]), calls not recorded is left to that row and the
     * Calls chip, so the page says it once.
     */
    fun banner(chip: ReadyChip, needs: List<NeedsAction>, setupShowsCapture: Boolean = false, blocking: String? = null): NeedsBanner? {
        val all = buildList {
            if (chip == ReadyChip.NOT_READY && !setupShowsCapture) add(NeedsBanner.Kind.CallsOff to 1)
            needs.filterIsInstance<NeedsAction.WaitingDecision>().size.takeIf { it > 0 }?.let { add(NeedsBanner.Kind.Waiting to it) }
            needs.filterIsInstance<NeedsAction.FailedUploads>().firstOrNull()?.let { add(NeedsBanner.Kind.FailedUploads to it.ids.size) }
            needs.filterIsInstance<NeedsAction.FailedTranscripts>().firstOrNull()?.let {
                add(NeedsBanner.Kind.FailedTranscripts to it.count)
            }
        }
        val (kind, count) = all.firstOrNull() ?: return null
        val reason = if (kind ==
            NeedsBanner.Kind.CallsOff
        ) {
            blocking?.let { com.echoledger.mobile.ui.v2.setup.HealthChecks.failWords(it) }
        } else {
            null
        }
        return NeedsBanner(kind, count, all.size - 1, reason)
    }
}

/**
 * The top of Library (docs/PLAN_V2_PARITY.md section 2): the setup checklist as a card that can be hidden, until setup
 * is done, then one quiet Needs action banner with its one button (Fix, Review or Retry).
 */
@Composable
fun V2LibraryTop(
    checklist: List<Pair<ChecklistItem, Boolean>>,
    setupHidden: Boolean,
    banner: NeedsBanner?,
    actions: LibraryTopActions,
    server: List<com.echoledger.mobile.core.ServerBanners.Banner> = emptyList()
) {
    // As classic (v1.6.1): every banner has ×. An announcement stays closed on this phone; the others close for now and
    // come back once what they say changes (another reason, another count).
    var closed by remember { mutableStateOf(emptySet<String>()) }
    Column {
        server.forEach { b ->
            val key = "server:${b.kind}:${b.text}"
            if (key !in closed) {
                ServerLine(b, actions) { b.key?.let(actions.onCloseAnnouncement) ?: run { closed = closed + key } }
            }
        }
        if (checklist.isNotEmpty() && !setupHidden) SetupCard(checklist, actions)
        banner?.let { b ->
            val key = "needs:$b"
            if (key !in closed) NeedsLine(b, actions) { closed = closed + key }
        }
    }
}

@Composable
private fun NeedsLine(banner: NeedsBanner, actions: LibraryTopActions, onClose: () -> Unit) {
    val res = LocalContext.current.resources
    val words = when (banner.kind) {
        // Since v1.5, in its own words ("…: the helper stopped when the phone restarted"), never only "not recorded".
        // v2.1 UX-14: "Calls won't record · Fix", in its own words when it has them.
        NeedsBanner.Kind.CallsOff -> banner.reason?.let {
            res.getString(R.string.v2_lib_need_wont_record_why, res.getString(it).replaceFirstChar { ch -> ch.lowercase() })
        } ?: res.getString(R.string.v2_lib_need_wont_record)

        NeedsBanner.Kind.Waiting -> if (banner.count == 1) {
            res.getString(R.string.v2_lib_need_waiting_one)
        } else {
            res.getString(R.string.v2_lib_need_waiting, banner.count)
        }

        // "3 didn't upload · Retry all".
        NeedsBanner.Kind.FailedUploads -> res.getQuantityString(R.plurals.v2_lib_need_not_uploaded, banner.count, banner.count)

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
            res.getString(if (banner.count > 1) R.string.v2_lib_need_retry_all else R.string.v2_home_retry) to actions.onRetryUploads
        } else {
            res.getString(R.string.v2_lib_need_review) to
                actions.onReview
        }

        else -> res.getString(R.string.v2_lib_need_review) to actions.onReview
    }
    EchoBanner(
        if (banner.more > 0) res.getString(R.string.v2_lib_need_more, words, banner.more) else words,
        if (banner.kind == NeedsBanner.Kind.CallsOff) EchoBannerKind.Warning else EchoBannerKind.Info,
        Modifier.padding(horizontal = LocalPageMargin.current, vertical = EchoTokens.Space.s8),
        actionLabel = label,
        onAction = run,
        onClose = onClose,
        closeLabel = res.getString(R.string.v2_lib_server_close)
    )
}

/**
 * A server banner, as classic shows it at the top of Library: an app update (Update), maintenance, or the admin's
 * announcement (its × closes it on this phone for good; the others' for now). The server's own words; the app's fallbacks in the screen's language.
 */
@Composable
private fun ServerLine(banner: com.echoledger.mobile.core.ServerBanners.Banner, actions: LibraryTopActions, onClose: () -> Unit) {
    val res = LocalContext.current.resources
    val words = when (banner.text) {
        com.echoledger.mobile.core.ServerBanners.UPDATE_FALLBACK -> res.getString(R.string.v2_lib_server_update)
        com.echoledger.mobile.core.ServerBanners.REFUSED -> res.getString(R.string.v2_lib_server_refused)
        com.echoledger.mobile.core.ServerBanners.MAINTENANCE_FALLBACK -> res.getString(R.string.v2_lib_server_maintenance)
        else -> banner.text
    }
    val update = banner.kind == com.echoledger.mobile.core.ServerBanners.Kind.UPDATE
    EchoBanner(
        words,
        if (banner.warning) EchoBannerKind.Warning else EchoBannerKind.Info,
        Modifier.padding(horizontal = LocalPageMargin.current, vertical = EchoTokens.Space.s8),
        actionLabel = if (update) res.getString(R.string.v2_lib_server_update_action) else null,
        onAction = actions.onUpdate,
        onClose = onClose,
        closeLabel = res.getString(R.string.v2_lib_server_close)
    )
}

/** Whether the setup card is on screen with its "Call recording ready" row still to do. */
fun setupShowsCapture(checklist: List<Pair<ChecklistItem, Boolean>>, setupHidden: Boolean): Boolean =
    !setupHidden && checklist.any { (item, done) -> item == ChecklistItem.Capture && !done }

/** "Finish setting up": what is done and what is left, each with its one action; Hide puts it away. */
@Composable
private fun SetupCard(items: List<Pair<ChecklistItem, Boolean>>, actions: LibraryTopActions) {
    val c = EchoV2.colors
    EchoCard(Modifier.padding(horizontal = LocalPageMargin.current, vertical = EchoTokens.Space.s8)) {
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
