package com.echoledger.mobile.ui.v2.home

import com.echoledger.mobile.core.ReadyChip
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.library.RowStatus

/** The status strip (docs/v2/IA.md 1.2): recording now, ready, needs setup, calls off, or voice notes only. */
sealed interface HomeStatus {
    data class Recording(val elapsedMs: Long, val paused: Boolean) : HomeStatus
    data object Ready : HomeStatus
    data object NeedsSetup : HomeStatus
    data object CallsOff : HomeStatus
    data object VoiceOnly : HomeStatus
}

/** One item of the Get started checklist (IA 1.1). */
enum class ChecklistItem { Capture, Permissions, FirstRecording, Language }

/** What needs the person (IA 1.3), each from data the phone already has. */
sealed interface NeedsAction {
    data class FailedUploads(val ids: List<String>) : NeedsAction
    data class WaitingDecision(val recording: LocalRecording) : NeedsAction
    data class Untranscribed(val count: Int) : NeedsAction
    data class FailedTranscripts(val count: Int) : NeedsAction
}

/** How the recent part stands: loading before the first sync, the error when offline with nothing, empty, or rows. */
enum class RecentState { Loading, Error, Empty, Ready }

/** Everything Home shows, worked out from the view model's state; no Android or Compose. */
data class HomeState(
    val status: HomeStatus,
    /** The checklist's items and whether each is done; empty once all are done (it then goes away). */
    val checklist: List<Pair<ChecklistItem, Boolean>>,
    val needsAction: List<NeedsAction>,
    val recentState: RecentState,
    val recent: List<LibraryItem>,
    val usedMinutes: Long?,
    val monthlyMinutes: Long?
)

/** The facts Home reads: the Ready check's chip and permissions, the recording, the library and the phone's queue. */
data class HomeFacts(
    val chip: ReadyChip,
    val callsPossible: Boolean,
    val captureReady: Boolean,
    val micAllowed: Boolean,
    val notificationsAllowed: Boolean,
    val live: LiveRecording,
    val library: List<LibraryItem>,
    val pending: List<LocalRecording>,
    val transcriptionLanguage: String?,
    val syncing: Boolean,
    val hasSynced: Boolean,
    val offline: Boolean,
    val usedMinutes: Long?,
    val monthlyMinutes: Long?
)

object HomeModel {
    const val RECENT = 5

    fun status(f: HomeFacts): HomeStatus = when {
        f.live.active -> HomeStatus.Recording(f.live.elapsedMs, f.live.paused)
        f.chip == ReadyChip.READY -> HomeStatus.Ready
        f.chip == ReadyChip.NOT_READY -> HomeStatus.NeedsSetup
        f.chip == ReadyChip.OFF -> HomeStatus.CallsOff
        else -> HomeStatus.VoiceOnly
    }

    /** The checklist until everything on it is done; the capture item only where calls can be recorded. */
    fun checklist(f: HomeFacts): List<Pair<ChecklistItem, Boolean>> {
        val items = buildList {
            if (f.callsPossible) add(ChecklistItem.Capture to f.captureReady)
            add(ChecklistItem.Permissions to (f.micAllowed && f.notificationsAllowed))
            add(ChecklistItem.FirstRecording to (f.library.any { !it.deleted } || f.pending.isNotEmpty()))
            add(ChecklistItem.Language to (f.transcriptionLanguage != null))
        }
        return if (items.all { it.second }) emptyList() else items
    }

    fun needsAction(f: HomeFacts): List<NeedsAction> = buildList {
        f.pending.filter {
            it.state == "pending_decision"
        }.sortedByDescending { it.startedAt }.forEach { add(NeedsAction.WaitingDecision(it)) }
        f.pending.filter { it.state == "failed" }.map { it.id }.takeIf { it.isNotEmpty() }?.let { add(NeedsAction.FailedUploads(it)) }
        val shown = f.library.filter { !it.deleted }
        shown.count { LibraryModel.status(it) == RowStatus.Untranscribed }.takeIf { it > 0 }?.let { add(NeedsAction.Untranscribed(it)) }
        shown.count { LibraryModel.status(it) == RowStatus.Failed }.takeIf { it > 0 }?.let { add(NeedsAction.FailedTranscripts(it)) }
    }

    fun recentState(f: HomeFacts): RecentState {
        val any = f.library.any { !it.deleted }
        return when {
            any -> RecentState.Ready
            f.syncing && !f.hasSynced -> RecentState.Loading
            f.offline -> RecentState.Error
            else -> RecentState.Empty
        }
    }

    fun state(f: HomeFacts) = HomeState(
        status = status(f),
        checklist = checklist(f),
        needsAction = needsAction(f),
        recentState = recentState(f),
        recent = f.library.filter { !it.deleted }.sortedByDescending { it.startedAt ?: it.updatedAt }.take(RECENT),
        usedMinutes = f.usedMinutes,
        monthlyMinutes = f.monthlyMinutes
    )
}
