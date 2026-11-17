package com.echoledger.mobile.ui.v2.library

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.R
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoErrorState
import com.echoledger.mobile.ui.v2.components.EchoFilter
import com.echoledger.mobile.ui.v2.components.EchoFilterBar
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoListRow
import com.echoledger.mobile.ui.v2.components.EchoSearchField
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoStatus
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.V2Text
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.screenLocale
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.ZonedDateTime
import kotlinx.coroutines.launch

/** A recording still on the phone (queued, uploading or failed to upload), shown above the library. */
data class UploadRow(
    val id: String,
    val title: String,
    val duration: String,
    val icon: androidx.compose.ui.graphics.vector.ImageVector,
    val failed: Boolean
)

/** What Library shows, worked out from the view model's state. */
sealed interface LibraryState {
    data object Loading : LibraryState
    data object Error : LibraryState
    data class Ready(val rows: List<V2Row>, val uploads: List<UploadRow>, val offline: Boolean, val anyAtAll: Boolean) : LibraryState
}

fun uploadRows(pending: List<LocalRecording>, text: V2Text): List<UploadRow> = pending
    .filter { it.state == "queued" || it.state == "uploading" || it.state == "failed" }
    .map {
        val app = SourceApp.from(it.sourceApp)
        UploadRow(
            it.id,
            it.title?.takeIf { t ->
                t.isNotBlank()
            } ?: it.callerName ?: text.sourceName(it.sourceApp),
            formatClock(it.durationMs + 500),
            LibraryModel.icon(app),
            it.state == "failed"
        )
    }

/**
 * Library on the v2 components (behind `ui_v2.library`), on the existing [MainViewModel] and its repositories: search,
 * the saved views Today and Untranscribed, Trash, WhatsApp-style rows, delete with Undo.
 */
@Composable
fun V2LibraryScreen(
    vm: MainViewModel,
    initials: String,
    onOpen: (LibraryItem) -> Unit,
    onTrash: () -> Unit,
    onAccount: () -> Unit,
    modifier: Modifier = Modifier,
    initialViews: Set<SavedView> = emptySet(),
    /** Projects (Workspaces), while `ui_v2.ask` is on. */
    onProjects: (() -> Unit)? = null
) {
    val text = v2Text
    val library by vm.library.collectAsState()
    val pending by vm.pending.collectAsState()
    val syncing by vm.syncing.collectAsState()
    val offline by vm.offline.collectAsState()
    var query by rememberSaveable { mutableStateOf("") }
    var views by remember(initialViews) { mutableStateOf(initialViews) }
    val locale = screenLocale()
    val now = remember(library) { ZonedDateTime.now() }
    val rows = remember(library, views, query, text, locale) { LibraryModel.rows(library, views, query, now, text, locale) }
    val uploads = remember(pending, text) { uploadRows(pending, text) }
    val state = when {
        library.isEmpty() && uploads.isEmpty() && syncing && !vm.hasSynced() -> LibraryState.Loading
        library.isEmpty() && uploads.isEmpty() && offline -> LibraryState.Error
        else -> LibraryState.Ready(rows, uploads, offline, library.any { !it.deleted } || uploads.isNotEmpty())
    }
    val toasts = LocalEchoToasts.current
    val scope = rememberCoroutineScope()
    val undo = EchoV2.strings.undo
    V2LibraryContent(
        state = state,
        query = query,
        onQuery = { query = it },
        views = views,
        onToggleView = { v -> views = if (v in views) views - v else views + v },
        initials = initials,
        onOpen = { onOpen(it.item) },
        onDelete = { row ->
            // TODO(phone core): MainViewModel.delete is fire-and-forget, so this toast can show before the server has moved
            // the recording to Trash. Show it only after success once the view model has a suspending delete.
            vm.delete(row.item)
            scope.launch { toasts.showUndo(text.deleted, undo) { scope.launch { vm.restore(row.id) } } }
        },
        onRetryUpload = { vm.retryUpload(it) },
        onRetry = { vm.refresh() },
        onTrash = onTrash,
        onAccount = onAccount,
        modifier = modifier,
        onProjects = onProjects
    )
}

/** Library's page without the view model (previews and tests). */
@Composable
fun V2LibraryContent(
    state: LibraryState,
    query: String,
    onQuery: (String) -> Unit,
    views: Set<SavedView>,
    onToggleView: (SavedView) -> Unit,
    initials: String,
    onOpen: (V2Row) -> Unit,
    onDelete: (V2Row) -> Unit,
    onRetryUpload: (String) -> Unit,
    onRetry: () -> Unit,
    onTrash: () -> Unit,
    onAccount: () -> Unit,
    modifier: Modifier = Modifier,
    onProjects: (() -> Unit)? = null
) {
    val c = EchoV2.colors
    val text = v2Text
    var actionsFor by remember { mutableStateOf<V2Row?>(null) }
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(text.recordings, large = true) {
            onProjects?.let { EchoIconButton(EchoIcons.Folder, stringResource(R.string.v2_ws_projects), it) }
            EchoIconButton(EchoIcons.Delete, text.trash, onTrash)
            Box(
                Modifier
                    .size(EchoTokens.Layout.controlTouchMin)
                    .clickable(onClickLabel = text.accountAndSettings, onClick = onAccount)
                    .semantics { contentDescription = text.accountAndSettings },
                contentAlignment = Alignment.Center
            ) { EchoAvatar(initials = initials) }
        }
        EchoSearchField(query, onQuery, text.searchRecordings, Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile))
        EchoFilterBar(
            filters = listOf(EchoFilter(SavedView.Today.name, text.today), EchoFilter(SavedView.Untranscribed.name, text.untranscribed)),
            selected = views.map { it.name }.toSet(),
            onToggle = { onToggleView(SavedView.valueOf(it)) },
            modifier = Modifier.padding(vertical = EchoTokens.Space.s4)
        )
        when (state) {
            LibraryState.Loading -> EchoSkeletonList(7, Modifier.weight(1f))

            LibraryState.Error -> Box(Modifier.weight(1f)) {
                EchoErrorState(text.loadErrorTitle, text.loadErrorBody, actionLabel = text.tryAgain, onAction = onRetry)
            }

            is LibraryState.Ready -> LazyColumn(Modifier.weight(1f)) {
                if (state.offline) {
                    item {
                        EchoBanner(
                            text.offlineBanner,
                            EchoBannerKind.Info,
                            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
                            actionLabel = text.tryAgain,
                            onAction = onRetry
                        )
                    }
                }
                items(state.uploads, key = { "local-" + it.id }) { up ->
                    EchoListRow(
                        title = up.title,
                        meta = if (up.failed) null else text.uploading,
                        metaEnd = { Text(up.duration, style = EchoV2.type.mono, color = c.textSecondary) },
                        status = if (up.failed) ({ EchoStatus(text.failed, EchoStatusKind.Danger) }) else null,
                        leading = { EchoAvatar(icon = up.icon) },
                        tall = true,
                        onClickLabel = if (up.failed) text.tryAgain else null,
                        onClick = if (up.failed) ({ onRetryUpload(up.id) }) else null
                    )
                }
                if (state.rows.isEmpty() && state.uploads.isEmpty()) {
                    item {
                        if (state.anyAtAll) {
                            EchoEmptyState(text.noMatchTitle, text.noMatchBody, icon = EchoIcons.Search)
                        } else {
                            EchoEmptyState(text.noRecordingsTitle, text.noRecordingsBody, icon = EchoIcons.Library)
                        }
                    }
                }
                items(state.rows, key = { it.id }) { row ->
                    EchoListRow(
                        title = row.title,
                        meta = row.meta,
                        titleEnd = row.time,
                        metaEnd = {
                            // The screens icon and count (meeting assets) before the length, when it has any.
                            val screens = row.item.screenCount ?: 0
                            Row(
                                verticalAlignment = Alignment.CenterVertically,
                                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
                            ) {
                                if (screens > 0) ScreensCountMark(screens)
                                Text(row.duration, style = EchoV2.type.mono, color = c.textSecondary)
                            }
                        },
                        status = statusBadge(row.status, text),
                        leading = { EchoAvatar(icon = row.icon, contentDescription = row.source) },
                        tall = true,
                        onLongClickLabel = text.delete,
                        onLongClick = { actionsFor = row },
                        onClick = { onOpen(row) }
                    )
                }
            }
        }
    }
    actionsFor?.let { row ->
        EchoBottomSheet(onDismiss = { actionsFor = null }, title = row.title) {
            EchoSettingRow(text.delete, icon = EchoIcons.Delete, onClick = {
                actionsFor = null
                onDelete(row)
            })
        }
    }
}

/** At most one badge per row: only what needs attention. */
private fun statusBadge(status: RowStatus, text: V2Text): (@Composable () -> Unit)? = when (status) {
    RowStatus.Done -> null
    RowStatus.Untranscribed -> ({ EchoStatus(text.untranscribed, EchoStatusKind.Neutral) })
    RowStatus.Transcribing -> ({ EchoStatus(text.transcribing, EchoStatusKind.Accent) })
    RowStatus.Failed -> ({ EchoStatus(text.failed, EchoStatusKind.Danger) })
}
