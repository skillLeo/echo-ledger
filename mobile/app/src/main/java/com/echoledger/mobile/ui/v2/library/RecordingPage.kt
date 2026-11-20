package com.echoledger.mobile.ui.v2.library

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import com.echoledger.mobile.core.ItemStatus
import com.echoledger.mobile.core.RecordingSteps
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.data.SessionMarkerDto
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.data.TranscriptViewsDto
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.RecordingPlayerState
import com.echoledger.mobile.ui.ShowAs
import com.echoledger.mobile.ui.TranscriptVersion
import com.echoledger.mobile.ui.previousVersionId
import com.echoledger.mobile.ui.rememberRecordingPlayer
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * What the v2 recording page knows and does, the same as classic's recording page (SessionScreen): the transcript of the
 * chosen version (and its load failure), the recording's details (playback, bookmarks, speaker names), opening it marks
 * it opened, a search match's moment plays once ready, the page follows a recording on its way, and the Show as views
 * (Roman Urdu, Urdu, English) are asked for and written on demand. The logic is classic's, copied, so both pages behave
 * alike; only the drawing is v2's.
 */
@Stable
class RecordingPage internal constructor(
    private val vm: MainViewModel,
    item: LibraryItem,
    val player: RecordingPlayerState,
    private val scope: CoroutineScope,
    private val url: androidx.compose.runtime.MutableState<String?>
) {
    /** The recording as the Library has it now (its status and transcript change while it is open). */
    var item by mutableStateOf(item)
    var transcript by mutableStateOf<TranscriptDto?>(null)
    var loaded by mutableStateOf(false)
    var loadFailed by mutableStateOf(false)
    var attempt by mutableIntStateOf(0)

    /** The recording's temporary playback address (null: no audio, or not read yet). */
    var playbackUrl: String?
        get() = url.value
        set(value) {
            url.value = value
        }
    var bookmarks by mutableStateOf<List<SessionMarkerDto>>(emptyList())
    var speakerNames by mutableStateOf<Map<String, String>>(emptyMap())
    var pendingSeek by mutableStateOf<Long?>(null)
    var shownTitle by mutableStateOf(item.title)
    var version by mutableStateOf(TranscriptVersion.CURRENT)
    internal var detailFor by mutableStateOf<Pair<String, Int>?>(null)
    var screens by mutableStateOf<List<ScreenDto>>(emptyList())
    var showAs by mutableStateOf(ShowAs.ORIGINAL)
    var views by mutableStateOf<TranscriptViewsDto?>(null)

    /** Upload · Transcribe · Improve while it is on its way (current version only). */
    val steps: RecordingSteps.Steps? get() = RecordingSteps.of(item, transcript.takeIf { version == TranscriptVersion.CURRENT })

    /** Nothing was heard: no player, no sharing, no failure card. */
    val noAudio: Boolean get() = ItemStatus.of(item) == ItemStatus.NO_AUDIO || transcript?.errorCode == "no_audio"

    /** The transcript is final and ready: copy, find and export work on it. */
    val canCopy: Boolean get() = transcript?.let { it.status !in IN_PROGRESS && it.status != "failed" && it.segments.isNotEmpty() } == true

    /** The finished final transcript's id, for export; null while there is none. */
    val exportId: String? get() = transcript?.takeIf { it.status == "completed" && it.kind == "final" }?.id

    /** The view on screen as its key, for copy and export in that view; null for the original words. */
    val viewKey: String? get() = showAs.key.takeIf { ShowAs.lines(transcript?.segments.orEmpty(), showAs, views) != null }

    /** The text of each line in the chosen view (null for a line, or all, in the original words). */
    val shown: List<String?>? get() = ShowAs.lines(transcript?.segments.orEmpty(), showAs, views)

    /** The line under the playhead, or -1 before anything played. */
    val activeIndex: Int get() {
        val segments = transcript?.segments.orEmpty()
        return if (player.playing || player.positionMs > 0) segments.indexOfLast { it.startMs <= player.positionMs } else -1
    }

    fun retry() {
        loaded = false
        attempt++
    }

    /** Show as (since v1.2): the view of the transcript, written by the server the first time it is asked for. */
    fun chooseView(view: ShowAs) {
        showAs = view
        val id = transcript?.id ?: return
        if (view == ShowAs.ORIGINAL || views?.status == "ready") return
        scope.launch {
            var state = vm.transcriptViews(id)
            if (state == null || state.status == "none" || state.status == "failed") state = vm.makeTranscriptViews(id) ?: state
            views = state
            if (state == null) showAs = ShowAs.ORIGINAL
        }
    }

    /** Plays from [ms] once the recording can play (at once when it can). */
    fun playFrom(ms: Long) {
        if (player.ready) {
            player.seekTo(ms)
            if (!player.playing) player.toggle()
        } else {
            pendingSeek = ms
        }
    }

    companion object {
        /** How often the page asks again while a recording is on its way (classic's LIVE_PAGE_REFRESH_MS). */
        const val REFRESH_MS = 5_000L
        val IN_PROGRESS = setOf("pending", "submitted", "processing", "awaiting_device")
    }
}

/** The recording page's state for [item], loaded and kept up to date as classic's page does. */
@Composable
fun rememberRecordingPage(vm: MainViewModel, item: LibraryItem, startAtMs: Long? = null): RecordingPage {
    val scope = rememberCoroutineScope()
    val url = remember(item.id) { mutableStateOf<String?>(null) }
    val player = rememberRecordingPlayer(url.value)
    player.knownMs = item.durationMs ?: 0L
    val p = remember(item.id) { RecordingPage(vm, item, player, scope, url).also { it.pendingSeek = startAtMs } }
    p.item = item
    // Opening the page counts as opening the recording (the unopened dot goes away here and on the server).
    LaunchedEffect(item.id) { vm.markOpened(item) }
    // The final transcript's id and status are keys too, so the page loads it as soon as the server has it.
    LaunchedEffect(item.id, p.attempt, p.version, item.transcript?.finalTranscriptId, item.transcript?.finalStatus) {
        val previous = previousVersionId(item).takeIf { p.version == TranscriptVersion.PREVIOUS }
        val id = previous ?: item.transcript?.finalTranscriptId ?: item.transcript?.liveTranscriptId
        p.transcript = id?.let { vm.transcript(it) }
        // Only a finished transcript that does not arrive is a load failure; otherwise its status says more.
        p.loadFailed = id != null && p.transcript == null && (previous != null || item.transcript?.finalStatus == "completed")
        p.loaded = true
        if (p.detailFor == item.id to p.attempt) return@LaunchedEffect
        p.detailFor = item.id to p.attempt
        val detail = vm.sessionDetail(item.id)
        p.playbackUrl = detail?.playbackUrl
        p.bookmarks = detail?.markers.orEmpty().sortedBy { it.atMs }
        p.speakerNames = detail?.speakerNames.orEmpty()
    }
    // The recording's screens (meeting assets): read when the Library says it has any, or doesn't say.
    LaunchedEffect(item.id) { if (item.screenCount != 0) p.screens = vm.screens(item.id).sortedBy { it.atMs } }
    // Opened from a search match: play from that moment once the recording is ready.
    LaunchedEffect(player.ready, p.pendingSeek) {
        val target = p.pendingSeek
        if (player.ready && target != null) {
            player.seekTo(target)
            p.pendingSeek = null
        }
    }
    // The page follows a recording on its way by itself (the Library's live refresh does not run while it is open).
    val following = p.steps?.working == true
    LaunchedEffect(item.id, following) {
        while (following) {
            delay(RecordingPage.REFRESH_MS)
            vm.refreshQuietly()
            val shown = p.transcript
            if (p.version == TranscriptVersion.CURRENT && shown != null && shown.status in RecordingPage.IN_PROGRESS) {
                vm.transcript(shown.id)?.let { p.transcript = it }
            }
        }
    }
    // A view being written is asked for again until it is ready.
    LaunchedEffect(p.showAs, p.views?.status, p.transcript?.id) {
        val id = p.transcript?.id ?: return@LaunchedEffect
        if (p.showAs != ShowAs.ORIGINAL && p.views?.status == "making") {
            delay(ShowAs.POLL_MS)
            p.views = vm.transcriptViews(id) ?: p.views
        }
    }
    // Open in the default view (console setting) when it is made for this recording.
    LaunchedEffect(p.transcript?.id) {
        val summary = p.transcript?.views ?: return@LaunchedEffect
        val default = ShowAs.of(summary.default)
        if (summary.status == "ready" && default != ShowAs.ORIGINAL && default.key in summary.available) p.chooseView(default)
    }
    return p
}
