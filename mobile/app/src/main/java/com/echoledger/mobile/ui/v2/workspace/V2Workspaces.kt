package com.echoledger.mobile.ui.v2.workspace

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.R
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.ProjectTree
import com.echoledger.mobile.ui.SmartFolder
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/**
 * The Projects list (Workspaces, behind `ui_v2.ask`), as classic's Projects tab: the smart folders (Inbox, each app,
 * Imported, Shared with me) with their counts, then the top-level projects with their recordings, folders and last
 * call; New project, and Request files in the top bar.
 */
@Composable
fun V2WorkspacesScreen(
    vm: MainViewModel,
    onOpen: (String) -> Unit,
    onFolder: (SmartFolder) -> Unit,
    onRequests: () -> Unit,
    onBack: (() -> Unit)?,
    modifier: Modifier = Modifier
) {
    val all by vm.clients.collectAsState()
    val library by vm.library.collectAsState()
    val scope = rememberCoroutineScope()
    var naming by remember { mutableStateOf(false) }
    // The server's folder counts (imported, shared), read once as the list opens; this phone's folders follow the Library.
    var serverCounts by remember { mutableStateOf<Map<String, Int>?>(null) }
    LaunchedEffect(Unit) { vm.folderCounts()?.let { serverCounts = it } }
    V2WorkspacesContent(
        all,
        library,
        onOpen,
        onNew = { naming = true },
        onBack = onBack,
        modifier = modifier,
        serverCounts = serverCounts,
        onFolder = onFolder,
        onRequests = onRequests
    )
    if (naming) {
        NewProjectSheet(onDismiss = { naming = false }) { name ->
            naming = false
            scope.launch { vm.createProject(name, null)?.let { onOpen(it.id) } }
        }
    }
}

/** The list without the view model (tests and screenshots). */
@Composable
fun V2WorkspacesContent(
    all: List<ClientDto>,
    library: List<LibraryItem>,
    onOpen: (String) -> Unit,
    onNew: () -> Unit,
    onBack: (() -> Unit)?,
    modifier: Modifier = Modifier,
    /** The server's smart-folder counts; null offline or from an older server (Shared with me is then not listed). */
    serverCounts: Map<String, Int>? = null,
    onFolder: (SmartFolder) -> Unit = {},
    onRequests: () -> Unit = {}
) {
    Column(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_ws_projects), onBack = onBack, large = onBack == null) {
            EchoIconButton(EchoIcons.Link, stringResource(R.string.v2_req_title), onRequests)
            EchoIconButton(EchoIcons.Add, stringResource(R.string.v2_ws_new_project), onNew)
            if (onBack == null) com.echoledger.mobile.ui.v2.shell.AccountButton()
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            val live = remember(library) { library.filter { !it.deleted } }
            val folders = remember(live, serverCounts) { SmartFolder.listed(serverCounts) { f -> live.count(f::matches) } }
            EchoSectionHeader(stringResource(R.string.v2_ws_folders))
            FolderRows(folders, onFolder)
            EchoSectionHeader(stringResource(R.string.v2_ws_projects))
            val roots = ProjectTree.roots(all)
            if (roots.isEmpty()) {
                EchoEmptyState(
                    stringResource(R.string.v2_ws_list_empty_title),
                    stringResource(R.string.v2_ws_list_empty_body),
                    icon = EchoIcons.Folder,
                    actionLabel = stringResource(R.string.v2_ws_new_project),
                    onAction = onNew
                )
            }
            val text = com.echoledger.mobile.ui.v2.shell.v2Text
            val locale = com.echoledger.mobile.ui.v2.theme.screenLocale()
            val now = remember(library) { java.time.ZonedDateTime.now() }
            roots.forEach { project ->
                val mine = ProjectTree.recordings(all, library, project.id, nested = true)
                // As classic: when the project's last call was ("Last call 10:42", "Yesterday", "Oct 20").
                // The newest by the moment it began, whatever offset each was written with.
                val last = mine.mapNotNull { item ->
                    runCatching { java.time.OffsetDateTime.parse(item.startedAt).toInstant() }.getOrNull()?.let { item to it }
                }.maxByOrNull { it.second }?.first
                ProjectRow(
                    project,
                    recordings = if (mine.isNotEmpty()) mine.size else project.sessionCount,
                    folders = ProjectTree.children(all, project.id).size,
                    onOpen = { onOpen(project.id) },
                    lastCall = last?.let {
                        com.echoledger.mobile.ui.v2.library.LibraryModel.time(it, now, text, locale)
                    }?.takeIf { it.isNotEmpty() }
                )
            }
        }
    }
}

/** New project: a name, then Create. */
@Composable
private fun NewProjectSheet(onDismiss: () -> Unit, onCreate: (String) -> Unit) {
    var name by remember { mutableStateOf("") }
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_ws_new_project)) {
        Column(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            EchoTextField(name, { name = it.take(NAME_LIMIT) }, label = stringResource(R.string.v2_ws_name))
            Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                EchoButton(stringResource(R.string.v2_ws_create), { onCreate(name.trim()) }, enabled = name.isNotBlank())
                EchoButton(stringResource(R.string.v2_ws_cancel), onDismiss, kind = EchoButtonKind.Quiet)
            }
        }
    }
}

private const val NAME_LIMIT = 120
