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
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/**
 * The Projects list (Workspaces, behind `ui_v2.ask`): the top-level projects with their recordings and folders, New
 * project, and a row to what today's Projects page also holds (shared with me, imported, requests), which opens it.
 */
@Composable
fun V2WorkspacesScreen(
    vm: MainViewModel,
    onOpen: (String) -> Unit,
    onSharedAndImported: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    val all by vm.clients.collectAsState()
    val library by vm.library.collectAsState()
    val scope = rememberCoroutineScope()
    var naming by remember { mutableStateOf(false) }
    V2WorkspacesContent(all, library, onOpen, onSharedAndImported, { naming = true }, onBack, modifier)
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
    onSharedAndImported: () -> Unit,
    onNew: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    Column(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_ws_projects), onBack = onBack) {
            EchoIconButton(EchoIcons.Add, stringResource(R.string.v2_ws_new_project), onNew)
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
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
            roots.forEach { project ->
                val mine = ProjectTree.recordings(all, library, project.id, nested = true)
                ProjectRow(
                    project,
                    recordings = if (mine.isNotEmpty()) mine.size else project.sessionCount,
                    folders = ProjectTree.children(all, project.id).size,
                    onOpen = { onOpen(project.id) }
                )
            }
            EchoSettingRow(
                stringResource(R.string.v2_ws_shared_imported),
                subtitle = stringResource(R.string.v2_ws_shared_imported_help),
                icon = EchoIcons.Library,
                onClick = onSharedAndImported
            )
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
