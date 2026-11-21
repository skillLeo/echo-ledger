package com.echoledger.mobile.ui.v2.workspace

import android.content.ClipData
import android.content.ClipboardManager
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.R
import com.echoledger.mobile.data.CallNotesDto
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.FilingRuleDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.MemoryItemDto
import com.echoledger.mobile.data.ProjectScreenDto
import com.echoledger.mobile.data.WorkspaceDto
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.ProjectMenuDialogs
import com.echoledger.mobile.ui.ProjectTree
import com.echoledger.mobile.ui.v2.ask.V2AskScreen
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCard
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoErrorState
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoListRow
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSelect
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTabs
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.library.ScreenMoment
import com.echoledger.mobile.ui.v2.library.ScreenPicture
import com.echoledger.mobile.ui.v2.library.V2ScreenViewer
import com.echoledger.mobile.ui.v2.library.screenLabel
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.screenLocale
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.ZonedDateTime
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** The project menu on the v2 page: today's items, less Share link (the Share button) and Filing rules (on Recordings). */
private val MENU = listOf(
    "subfolder" to R.string.v2_ws_m_subfolder,
    "rename" to R.string.v2_ws_m_rename,
    "move" to R.string.v2_ws_m_move,
    "send" to R.string.v2_ws_m_send,
    "delete" to R.string.v2_ws_m_delete
)

/**
 * A project's page in the new look (Workspaces, docs/v2/IA.md 2.2; behind `ui_v2.ask`), matching the desktop's
 * (desktop/src/v2/workspace/WorkspaceV2.tsx) and keeping every action of today's page (ClientWorkspace.kt;
 * WorkspaceParityTest keeps the two lists equal):
 * - Recordings: its folders, Include its folders, the recordings, and the filing rules (add, remove);
 * - Files: Add files, Paste instructions, Remove after a confirmation;
 * - Ask: about this project only (when the plan has AI notes);
 * - Memory & instructions: the AI notes switch and the month's AI use, the brief (Edit, Save brief, Write again),
 *   memory (open the call, pin, remove, add), the pasted instructions;
 * - Call notes (while AI notes are on) and Screens;
 * and Share (the v2 Share link sheet), Copy for AI and the project menu, whose dialogs stay today's (ProjectMenuDialogs).
 */
@Composable
fun V2WorkspaceScreen(
    vm: MainViewModel,
    projectId: String,
    onBack: () -> Unit,
    onOpenProject: (String?) -> Unit,
    onOpenRecording: (LibraryItem) -> Unit,
    onOpenAt: (sessionId: String, atMs: Long) -> Unit,
    modifier: Modifier = Modifier
) {
    val all by vm.clients.collectAsState()
    val library by vm.library.collectAsState()
    val settings by vm.settings.collectAsState()
    val project = all.firstOrNull { it.id == projectId } ?: return
    BackHandler(onBack = onBack)
    val c = EchoV2.colors
    val context = LocalContext.current
    val toasts = LocalEchoToasts.current
    val scope = rememberCoroutineScope()
    val copied = stringResource(R.string.v2_ws_copied)

    var workspace by remember(projectId) { mutableStateOf<WorkspaceDto?>(null) }
    var failed by remember(projectId) { mutableStateOf(false) }
    var reload by remember(projectId) { mutableIntStateOf(0) }
    LaunchedEffect(projectId, reload) {
        val loaded = vm.workspace(projectId)
        if (loaded != null) workspace = loaded
        failed = loaded == null && workspace == null
    }
    // While the brief, notes or a file are being made on the server, look again every 5 s.
    val working = workspace?.let { w ->
        WorkspaceModel.working(w.brief.status, w.notes.map { it.status }, w.files.map { it.status })
    } == true
    LaunchedEffect(working) {
        while (working) {
            delay(WORKING_POLL_MS)
            reload++
        }
    }

    val aiNotesInPlan = settings.plan.aiNotes
    val tabs = WorkspaceTab.shown(aiNotesInPlan, workspace?.client?.aiNotes ?: true)
    var tab by rememberSaveable(projectId) { mutableStateOf(WorkspaceTab.Recordings) }
    val shown = if (tab in tabs) tab else WorkspaceTab.Recordings
    var nested by rememberSaveable(projectId) { mutableStateOf(false) }
    var sheet by remember { mutableStateOf<String?>(null) }
    var menuOpen by remember { mutableStateOf(false) }
    var viewing by remember { mutableStateOf<Pair<List<ProjectScreenDto>, Int>?>(null) }

    viewing?.let { (list, index) ->
        V2ScreenViewer(
            moments = list.map { ScreenMoment(it.screen(), fileName = null, said = emptyList()) },
            startIndex = index,
            onPlayFrom = { at ->
                viewing = null
                onOpenAt(list[index].sessionId, at)
            },
            onClose = { viewing = null },
            modifier = modifier,
            transcriptReady = false
        )
        return
    }

    val path = ProjectTree.ancestors(all, projectId)
    val folders = ProjectTree.children(all, projectId)
    val recordings = ProjectTree.recordings(all, library, projectId, nested)
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(title = project.name, onBack = onBack) {
            EchoIconButton(EchoIcons.Share, stringResource(R.string.v2_ws_share), { sheet = "share" })
            EchoIconButton(EchoIcons.More, stringResource(R.string.v2_ws_menu), { menuOpen = true })
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            Crumbs(path, onOpenProject)
            Row(
                Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
            ) {
                Text(recordingsCount(recordings.size), style = EchoV2.type.meta, color = c.textSecondary, modifier = Modifier.weight(1f))
                EchoButton(stringResource(R.string.v2_ws_copy_ai), {
                    scope.launch {
                        vm.bundle(projectId)?.let { bundle ->
                            context.getSystemService(ClipboardManager::class.java)
                                ?.setPrimaryClip(ClipData.newPlainText(project.name, bundle))
                            toasts.show(copied)
                        }
                    }
                }, kind = EchoButtonKind.Secondary)
            }
            EchoTabs(tabs.map { stringResource(it.label) }, tabs.indexOf(shown), onSelect = { tab = tabs[it] })
            val w = workspace
            when {
                shown == WorkspaceTab.Recordings -> RecordingsTab(
                    vm,
                    project,
                    folders,
                    recordings,
                    nested,
                    { nested = it },
                    onOpenProject,
                    onOpenRecording
                )

                shown == WorkspaceTab.Screens -> ScreensTab(vm, project, nested) { list, i -> viewing = list to i }

                shown == WorkspaceTab.Ask -> Column(Modifier.fillMaxWidth().height(EchoTokens.Space.s64 * ASK_UNITS)) {
                    V2AskScreen(vm, onOpenAt, fixedProject = project)
                }

                w == null && failed -> EchoErrorState(
                    stringResource(R.string.v2_ws_failed_title),
                    stringResource(R.string.v2_ws_failed_body),
                    actionLabel = stringResource(R.string.v2_ws_try_again),
                    onAction = { reload++ }
                )

                w == null -> EchoSkeletonList(3, avatar = false)

                shown == WorkspaceTab.Files -> FilesTab(vm, project, w, onChanged = { reload++ })

                shown == WorkspaceTab.Memory -> MemoryTab(vm, project, w, aiNotesInPlan, onOpenAt, { workspace = it }, { reload++ })

                else -> NotesTab(project, w.notes, onOpenAt)
            }
            Spacer(Modifier.height(EchoTokens.Space.s24))
        }
    }
    if (menuOpen) {
        EchoBottomSheet(onDismiss = { menuOpen = false }, title = project.name) {
            MENU.forEach { (key, label) ->
                EchoSettingRow(
                    stringResource(label),
                    icon = if (key == "delete") EchoIcons.Delete else null,
                    onClick = {
                        menuOpen = false
                        sheet = key
                    }
                )
            }
        }
    }
    sheet?.let { key ->
        if (key == "share") {
            com.echoledger.mobile.ui.v2.library.V2ShareLinkSheet(vm, sessionId = null, clientId = project.id, onDismiss = { sheet = null })
        } else {
            ProjectMenuDialogs(vm, project, all, key, onClose = { sheet = null }, onOpenProject = onOpenProject)
        }
    }
}

private const val WORKING_POLL_MS = 5_000L

/** The Ask tab's height inside the page's scroll (Ask has its own scroll): 9 × 64 dp. */
private const val ASK_UNITS = 9

@Composable
fun recordingsCount(count: Int): String =
    if (count == 1) stringResource(R.string.v2_ws_count_rec_one) else stringResource(R.string.v2_ws_count_rec_other, count)

@Composable
private fun foldersCount(count: Int): String =
    if (count == 1) stringResource(R.string.v2_ws_count_folder_one) else stringResource(R.string.v2_ws_count_folder_other, count)

/** Projects › Qureshi Textiles › March order: each part opens that level (the last is this page). */
@Composable
private fun Crumbs(path: List<ClientDto>, onOpenProject: (String?) -> Unit) {
    if (path.isEmpty()) return
    Row(
        Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically
    ) {
        EchoButton(stringResource(R.string.v2_ws_projects), { onOpenProject(null) }, kind = EchoButtonKind.Plain)
        path.forEach { crumb ->
            Text("›", style = EchoV2.type.meta, color = EchoV2.colors.textSecondary)
            EchoButton(crumb.name, { onOpenProject(crumb.id) }, kind = EchoButtonKind.Plain)
        }
    }
}

@Composable
private fun RecordingsTab(
    vm: MainViewModel,
    project: ClientDto,
    folders: List<ClientDto>,
    recordings: List<LibraryItem>,
    nested: Boolean,
    onNested: (Boolean) -> Unit,
    onOpenProject: (String?) -> Unit,
    onOpenRecording: (LibraryItem) -> Unit
) {
    val c = EchoV2.colors
    val text = v2Text
    val locale = screenLocale()
    val now = remember(recordings) { ZonedDateTime.now() }
    Column {
        if (folders.isNotEmpty()) {
            EchoSectionHeader(stringResource(R.string.v2_ws_folders))
            folders.forEach { folder ->
                EchoListRow(
                    title = folder.name,
                    meta = recordingsCount(folder.nestedSessionCount ?: folder.sessionCount),
                    leading = { EchoAvatar(icon = EchoIcons.Folder) },
                    onClick = { onOpenProject(folder.id) }
                )
            }
            EchoSwitch(nested, onNested, stringResource(R.string.v2_ws_include_folders))
        }
        if (recordings.isEmpty()) {
            EchoEmptyState(
                stringResource(R.string.v2_ws_rec_empty_title),
                stringResource(R.string.v2_ws_rec_empty_body, project.name),
                icon = EchoIcons.Library
            )
        }
        recordings.forEach { item ->
            val row = remember(item, text, locale) { LibraryModel.row(item, now, text, locale) }
            EchoListRow(
                title = row.title,
                meta = row.meta,
                titleEnd = row.time,
                metaEnd = { Text(row.duration, style = EchoV2.type.mono, color = c.textSecondary) },
                leading = { EchoAvatar(icon = row.icon, contentDescription = row.source) },
                tall = true,
                onClick = { onOpenRecording(item) }
            )
        }
        Rules(vm, project)
    }
}

/** The filing rules, ready to add to: each with Remove, then the kind, the value and Add rule. */
@Composable
private fun Rules(vm: MainViewModel, project: ClientDto) {
    val c = EchoV2.colors
    val scope = rememberCoroutineScope()
    var rules by remember(project.id) { mutableStateOf<List<FilingRuleDto>?>(null) }
    var kind by remember { mutableStateOf("title") }
    var value by remember { mutableStateOf("") }
    LaunchedEffect(project.id) { rules = vm.filingRules(project.id) }
    EchoSectionHeader(stringResource(R.string.v2_ws_rules))
    Text(
        stringResource(R.string.v2_ws_rules_help),
        style = EchoV2.type.meta,
        color = c.textSecondary,
        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
    )
    val list = rules.orEmpty()
    if (rules != null && list.isEmpty()) {
        Text(
            stringResource(R.string.v2_ws_rules_none),
            style = EchoV2.type.meta,
            color = c.textSecondary,
            modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
        )
    }
    val toasts = LocalEchoToasts.current
    val undo = stringResource(R.string.v2_ws_undo)
    val restored = stringResource(R.string.v2_ws_rule_restored)
    val notRestored = stringResource(R.string.v2_ws_rule_not_restored)
    list.forEach { rule ->
        val label = ruleLabel(rule)
        val removed = stringResource(R.string.v2_ws_rule_removed, label)
        EchoSettingRow(label, trailing = {
            EchoIconButton(EchoIcons.Delete, stringResource(R.string.v2_ws_remove_rule, label), {
                scope.launch {
                    if (!vm.deleteFilingRule(project.id, rule.id)) return@launch
                    rules = rules.orEmpty() - rule
                    // One tap removes it; Undo adds the same kind and value back and says how that went.
                    toasts.showUndo(removed, undo) {
                        scope.launch {
                            val before = vm.message.value
                            val back = vm.addFilingRule(project.id, rule.kind, rule.value)
                            if (back != null) {
                                rules = rules.orEmpty() + back
                            } else if (vm.message.value != null && vm.message.value !== before) {
                                // Only the message this re-add set: it's said here instead, once. Any other stays.
                                vm.consumeMessage()
                            }
                            toasts.show(if (back != null) restored else notRestored)
                        }
                    }
                }
            })
        })
    }
    Column(
        Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        val kinds = WorkspaceModel.RULE_KINDS
        val words = kinds.associate { (k, id) -> k to stringResource(id) }
        EchoSelect(
            label = stringResource(R.string.v2_ws_rule_kind),
            options = kinds.map { it.first },
            selected = kind,
            onSelect = { kind = it },
            optionLabel = { words.getValue(it) }
        )
        EchoTextField(value, {
            value = it.take(RULE_MAX)
        }, label = words.getValue(kind), placeholder = stringResource(WorkspaceModel.ruleHint(kind)))
        EchoButton(stringResource(R.string.v2_ws_add_rule), {
            scope.launch {
                vm.addFilingRule(project.id, kind, value.trim())?.let {
                    rules = list + it
                    value = ""
                }
            }
        }, enabled = value.isNotBlank(), kind = EchoButtonKind.Secondary)
    }
}

private const val RULE_MAX = 200

@Composable
private fun ruleLabel(rule: FilingRuleDto): String = when (rule.kind) {
    "contact" -> stringResource(R.string.v2_ws_rule_label_contact, rule.value)
    "app" -> stringResource(R.string.v2_ws_rule_label_app, v2Text.sourceName(rule.value))
    else -> stringResource(R.string.v2_ws_rule_label_title, rule.value)
}

@Composable
private fun FilesTab(vm: MainViewModel, project: ClientDto, w: WorkspaceDto, onChanged: () -> Unit) {
    val c = EchoV2.colors
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var pasting by remember { mutableStateOf(false) }
    var removing by remember { mutableStateOf<Pair<String, String>?>(null) }
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
        if (uris.isNotEmpty()) {
            busy = true
            scope.launch {
                val sent = vm.addClientFiles(project.id, uris)
                busy = false
                if (sent > 0) onChanged()
            }
        }
    }
    Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
        Text(
            stringResource(R.string.v2_ws_files_help),
            style = EchoV2.type.meta,
            color = c.textSecondary,
            modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
        )
        Row(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            EchoButton(stringResource(R.string.v2_ws_add_files), {
                pick.launch(arrayOf("application/pdf", DOCX, "text/plain", "text/markdown"))
            }, loading = busy, loadingText = stringResource(R.string.v2_ws_adding))
            EchoButton(stringResource(R.string.v2_ws_paste), { pasting = true }, kind = EchoButtonKind.Secondary)
        }
        if (w.files.isEmpty()) {
            Text(
                stringResource(R.string.v2_ws_files_none),
                style = EchoV2.type.meta,
                color = c.textSecondary,
                modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
            )
        }
        Column {
            w.files.forEach { file ->
                EchoListRow(
                    title = file.name,
                    meta = when (file.status) {
                        "extracting" -> stringResource(R.string.v2_ws_file_reading)

                        "failed" -> stringResource(R.string.v2_ws_file_failed)

                        else -> listOfNotNull(
                            if (file.instructions) stringResource(R.string.v2_ws_file_instructions) else sizeWords(file.sizeBytes),
                            file.pages?.let { stringResource(R.string.v2_ws_file_pages, it) }
                        ).joinToString(", ")
                    },
                    leading = { EchoAvatar(icon = EchoIcons.Transcribe) },
                    trailing = {
                        EchoIconButton(EchoIcons.Delete, stringResource(R.string.v2_ws_remove_file, file.name), {
                            removing =
                                file.id to file.name
                        })
                    }
                )
            }
        }
    }
    if (pasting) {
        InstructionsSheet(onDismiss = { pasting = false }) { name, body ->
            pasting = false
            scope.launch { if (vm.addInstructions(project.id, name, body) != null) onChanged() }
        }
    }
    removing?.let { (id, name) ->
        EchoDialog(
            title = stringResource(R.string.v2_ws_remove_file_title, name),
            text = stringResource(R.string.v2_ws_remove_file_body),
            confirmLabel = stringResource(R.string.v2_ws_remove),
            onConfirm = {
                removing = null
                scope.launch { if (vm.deleteClientFile(project.id, id) != null) onChanged() }
            },
            dismissLabel = stringResource(R.string.v2_ws_cancel),
            onDismiss = { removing = null },
            destructive = true
        )
    }
}

private const val DOCX = "application/vnd.openxmlformats-officedocument.wordprocessingml.document"

/** "120 KB" / "1.2 MB". */
private fun sizeWords(bytes: Long): String = when {
    bytes >= 1_000_000 -> String.format(java.util.Locale.ROOT, "%.1f MB", bytes / 1_000_000.0)
    else -> "${(bytes / 1_000).coerceAtLeast(1)} KB"
}

/** Paste instructions: a name (Instructions to start with) and the text; Save instructions or Cancel. */
@Composable
private fun InstructionsSheet(onDismiss: () -> Unit, onSave: (name: String, text: String) -> Unit) {
    val first = stringResource(R.string.v2_ws_instructions)
    var name by remember { mutableStateOf(first) }
    var body by remember { mutableStateOf("") }
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_ws_paste)) {
        Column(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            EchoTextField(name, { name = it.take(NAME_MAX) }, label = stringResource(R.string.v2_ws_name))
            EchoTextField(body, { body = it.take(INSTRUCTIONS_MAX) }, label = stringResource(R.string.v2_ws_instr_text), singleLine = false)
            Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                EchoButton(stringResource(R.string.v2_ws_save_instructions), { onSave(name.trim(), body) }, enabled = body.isNotBlank())
                EchoButton(stringResource(R.string.v2_ws_cancel), onDismiss, kind = EchoButtonKind.Quiet)
            }
        }
    }
}

private const val NAME_MAX = 200
private const val INSTRUCTIONS_MAX = 100_000

@Composable
private fun MemoryTab(
    vm: MainViewModel,
    project: ClientDto,
    w: WorkspaceDto,
    aiNotesInPlan: Boolean,
    onOpenAt: (String, Long) -> Unit,
    onWorkspace: (WorkspaceDto) -> Unit,
    onChanged: () -> Unit
) {
    val c = EchoV2.colors
    val scope = rememberCoroutineScope()
    val toasts = LocalEchoToasts.current
    val saved = stringResource(R.string.v2_ws_brief_saved)
    var editing by remember { mutableStateOf<Map<BriefField, String>?>(null) }
    var confirmWrite by remember { mutableStateOf(false) }
    var removingLine by remember { mutableStateOf<MemoryItemDto?>(null) }
    var removingFile by remember { mutableStateOf<Pair<String, String>?>(null) }
    var kind by remember { mutableStateOf(MemoryKind.Decision) }
    var line by remember { mutableStateOf("") }
    val writeAgain = { scope.launch { if (vm.refreshBrief(project.id) != null) onChanged() } }
    Column(
        Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
    ) {
        // AI: the switch and the month's use, or why there's none.
        if (aiNotesInPlan) {
            EchoCard {
                EchoSwitch(
                    w.client.aiNotes,
                    { on ->
                        onWorkspace(w.copy(client = w.client.copy(aiNotes = on)))
                        scope.launch { vm.setAiNotes(project.id, on) }
                    },
                    stringResource(R.string.v2_ws_ai_notes),
                    supporting = stringResource(R.string.v2_ws_ai_notes_help, project.name)
                )
                Text(
                    if (w.ai.configured) {
                        stringResource(
                            R.string.v2_ws_budget,
                            WorkspaceModel.dollars(w.ai.spentUsd),
                            WorkspaceModel.dollars(w.ai.budgetUsd),
                            WorkspaceModel.dollars(w.ai.clientSpentUsd)
                        )
                    } else {
                        stringResource(R.string.v2_ws_ai_not_set)
                    },
                    style = EchoV2.type.caption,
                    color = c.textSecondary
                )
            }
        } else {
            EchoBanner(stringResource(R.string.v2_ws_no_ai_plan), EchoBannerKind.Info)
        }

        // The brief.
        Group(stringResource(if (w.brief.edited) R.string.v2_ws_brief_edited else R.string.v2_ws_brief)) {
            if (editing == null) {
                Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                    EchoButton(
                        stringResource(R.string.v2_ws_edit),
                        { editing = WorkspaceModel.editing(w.brief.sections) },
                        kind = EchoButtonKind.Quiet,
                        enabled = w.brief.status != "working"
                    )
                    if (aiNotesInPlan && w.ai.configured) {
                        EchoButton(
                            stringResource(R.string.v2_ws_write_again),
                            { if (w.brief.edited) confirmWrite = true else writeAgain() },
                            kind = EchoButtonKind.Quiet,
                            loading = w.brief.status == "working",
                            loadingText = stringResource(R.string.v2_ws_writing)
                        )
                    }
                }
            }
        }
        EchoCard {
            if (w.brief.status == "failed") {
                Text(stringResource(R.string.v2_ws_brief_failed), style = EchoV2.type.meta, color = c.danger)
            }
            val edit = editing
            when {
                edit != null -> {
                    BriefField.entries.forEach { field ->
                        EchoTextField(
                            edit[field].orEmpty(),
                            { editing = edit + (field to it) },
                            label = stringResource(field.label),
                            supportingText = if (field == BriefField.Goal) null else stringResource(R.string.v2_ws_one_per_line),
                            singleLine = field == BriefField.Goal
                        )
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                        EchoButton(stringResource(R.string.v2_ws_save_brief), {
                            val sections = WorkspaceModel.sections(edit)
                            editing = null
                            scope.launch {
                                vm.saveBrief(project.id, sections)?.let {
                                    onWorkspace(w.copy(brief = it))
                                    toasts.show(saved)
                                }
                            }
                        })
                        EchoButton(stringResource(R.string.v2_ws_cancel), { editing = null }, kind = EchoButtonKind.Quiet)
                    }
                }

                w.brief.sections.isEmpty -> Text(
                    stringResource(
                        when {
                            w.brief.status == "working" -> R.string.v2_ws_brief_working
                            aiNotesInPlan -> R.string.v2_ws_brief_none
                            else -> R.string.v2_ws_brief_none_manual
                        }
                    ),
                    style = EchoV2.type.meta,
                    color = c.textSecondary
                )

                else -> BriefField.entries.forEach { field ->
                    val lines = WorkspaceModel.value(w.brief.sections, field)
                    if (lines.isNotEmpty()) {
                        Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s2)) {
                            Text(stringResource(field.label), style = EchoV2.type.captionStrong, color = c.textSecondary)
                            lines.forEach { Text(it, style = EchoV2.type.content(it, EchoV2.type.body), color = c.textPrimary) }
                        }
                    }
                }
            }
        }

        // Memory, by kind, pinned first; then a line of one's own.
        Group(stringResource(R.string.v2_ws_memory)) {}
        EchoCard {
            val groups = WorkspaceModel.memoryGroups(w.memory)
            if (groups.isEmpty()) {
                Text(stringResource(R.string.v2_ws_memory_none), style = EchoV2.type.meta, color = c.textSecondary)
            }
            groups.forEach { (k, lines) ->
                Text(stringResource(k.heading), style = EchoV2.type.captionStrong, color = c.textSecondary)
                lines.forEach { item ->
                    MemoryRow(
                        item,
                        onOpenAt,
                        onPin = { scope.launch { if (vm.updateMemory(project.id, item.id, pinned = !item.pinned) != null) onChanged() } },
                        onRemove = { removingLine = item }
                    )
                }
            }
            val kindWords = MemoryKind.entries.associateWith { stringResource(it.label) }
            EchoSelect(
                label = stringResource(R.string.v2_ws_kind),
                options = MemoryKind.entries,
                selected = kind,
                onSelect = { kind = it },
                optionLabel = { kindWords.getValue(it) }
            )
            EchoTextField(
                line,
                { line = it.take(LINE_MAX) },
                label = stringResource(R.string.v2_ws_add_line),
                placeholder = stringResource(R.string.v2_ws_add_line_hint),
                singleLine = false
            )
            EchoButton(stringResource(R.string.v2_ws_add_to_memory), {
                val adding = line.trim()
                line = ""
                scope.launch { if (vm.addMemory(project.id, kind.wire, adding) != null) onChanged() }
            }, enabled = line.isNotBlank(), kind = EchoButtonKind.Secondary)
        }

        // Instructions: the pasted ones (added on Files).
        Group(stringResource(R.string.v2_ws_instructions)) {}
        EchoCard {
            val instructions = w.files.filter { it.instructions }
            if (instructions.isEmpty()) {
                Text(stringResource(R.string.v2_ws_instructions_none), style = EchoV2.type.meta, color = c.textSecondary)
            }
            instructions.forEach { file ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(file.name, style = EchoV2.type.body, color = c.textPrimary, modifier = Modifier.weight(1f))
                    EchoIconButton(EchoIcons.Delete, stringResource(R.string.v2_ws_remove_file, file.name), {
                        removingFile =
                            file.id to file.name
                    })
                }
            }
        }
    }
    if (confirmWrite) {
        EchoDialog(
            title = stringResource(R.string.v2_ws_write_again_title),
            text = stringResource(R.string.v2_ws_write_again_body),
            confirmLabel = stringResource(R.string.v2_ws_write_again),
            onConfirm = {
                confirmWrite = false
                writeAgain()
            },
            dismissLabel = stringResource(R.string.v2_ws_cancel),
            onDismiss = { confirmWrite = false },
            destructive = true
        )
    }
    removingLine?.let { item ->
        EchoDialog(
            title = stringResource(R.string.v2_ws_remove_line_title),
            text = "“${item.text}”",
            confirmLabel = stringResource(R.string.v2_ws_remove),
            onConfirm = {
                removingLine = null
                scope.launch { if (vm.deleteMemory(project.id, item.id) != null) onChanged() }
            },
            dismissLabel = stringResource(R.string.v2_ws_cancel),
            onDismiss = { removingLine = null },
            destructive = true
        )
    }
    removingFile?.let { (id, name) ->
        EchoDialog(
            title = stringResource(R.string.v2_ws_remove_file_title, name),
            text = stringResource(R.string.v2_ws_remove_file_body),
            confirmLabel = stringResource(R.string.v2_ws_remove),
            onConfirm = {
                removingFile = null
                scope.launch { if (vm.deleteClientFile(project.id, id) != null) onChanged() }
            },
            dismissLabel = stringResource(R.string.v2_ws_cancel),
            onDismiss = { removingFile = null },
            destructive = true
        )
    }
}

private const val LINE_MAX = 500

/** A group's heading with its buttons on the end side. */
@Composable
private fun Group(title: String, buttons: @Composable () -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(title, style = EchoV2.type.label, color = EchoV2.colors.textPrimary, modifier = Modifier.weight(1f))
        buttons()
    }
}

/** A memory line: its text and where it's from, then Open (a call's moment), Pin or Unpin, and Remove. */
@Composable
private fun ColumnScope.MemoryRow(item: MemoryItemDto, onOpenAt: (String, Long) -> Unit, onPin: () -> Unit, onRemove: () -> Unit) {
    val c = EchoV2.colors
    val call = item.source.type == "call" && item.source.id != null
    val at = formatClock(item.source.atMs ?: 0)
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s2)) {
        Text(item.text, style = EchoV2.type.content(item.text, EchoV2.type.body), color = c.textPrimary)
        Text(
            listOfNotNull(
                if (item.pinned) stringResource(R.string.v2_ws_pinned) else null,
                item.happenedOn,
                when {
                    call -> stringResource(R.string.v2_ws_from_call, at)
                    item.source.type == "file" -> stringResource(R.string.v2_ws_from_file)
                    else -> stringResource(R.string.v2_ws_added_by_you)
                }
            ).joinToString(", "),
            style = EchoV2.type.caption,
            color = c.textSecondary
        )
        Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4), verticalAlignment = Alignment.CenterVertically) {
            if (call) {
                EchoIconButton(EchoIcons.Play, stringResource(R.string.v2_ws_open_call, at), {
                    onOpenAt(
                        item.source.id!!,
                        item.source.atMs ?: 0
                    )
                })
            }
            EchoIconButton(
                EchoIcons.Pin,
                stringResource(if (item.pinned) R.string.v2_ws_unpin else R.string.v2_ws_pin, item.text),
                onPin,
                tint = if (item.pinned) c.accentText else c.textSecondary
            )
            EchoIconButton(EchoIcons.Delete, stringResource(R.string.v2_ws_remove_line, item.text), onRemove, tint = c.textSecondary)
        }
    }
}

@Composable
private fun NotesTab(project: ClientDto, notes: List<CallNotesDto>, onOpenAt: (String, Long) -> Unit) {
    val c = EchoV2.colors
    if (notes.isEmpty()) {
        EchoEmptyState(
            stringResource(R.string.v2_ws_notes_none_title),
            stringResource(R.string.v2_ws_notes_none_body, project.name),
            icon = EchoIcons.Transcribe
        )
        return
    }
    Column(
        Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
    ) {
        notes.forEach { note ->
            val title = note.session?.title ?: stringResource(R.string.v2_ws_call)
            EchoCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        title,
                        style = EchoV2.type.content(title, EchoV2.type.bodyStrong),
                        color = c.textPrimary,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f)
                    )
                    EchoIconButton(EchoIcons.Play, stringResource(R.string.v2_ws_open_rec, title), { onOpenAt(note.sessionId, 0) })
                }
                when (note.status) {
                    "pending" -> Text(stringResource(R.string.v2_ws_notes_writing), style = EchoV2.type.meta, color = c.textSecondary)

                    "failed", "skipped" -> Text(
                        stringResource(R.string.v2_ws_notes_skipped),
                        style = EchoV2.type.meta,
                        color = c.textSecondary
                    )

                    else -> if (note.sections.isEmpty()) {
                        Text(stringResource(R.string.v2_ws_notes_nothing), style = EchoV2.type.meta, color = c.textSecondary)
                    }
                }
                note.sections.forEach { section ->
                    // The note's own heading (the server's words): its own direction and face.
                    Text(section.heading, style = EchoV2.type.content(section.heading, EchoV2.type.captionStrong), color = c.textSecondary)
                    section.items.forEach { item ->
                        Row(
                            Modifier.fillMaxWidth().clickable(role = Role.Button) { onOpenAt(note.sessionId, item.atMs) },
                            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
                        ) {
                            Text(formatClock(item.atMs), style = EchoV2.type.mono, color = c.accentText)
                            Text(
                                item.text,
                                style = EchoV2.type.content(item.text, EchoV2.type.body),
                                color = c.textPrimary,
                                modifier = Modifier.weight(1f)
                            )
                            if (item.verify) Text(stringResource(R.string.v2_ws_verify), style = EchoV2.type.caption, color = c.warning)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun ScreensTab(vm: MainViewModel, project: ClientDto, nested: Boolean, onOpen: (List<ProjectScreenDto>, Int) -> Unit) {
    val scope = rememberCoroutineScope()
    var list by remember(project.id, nested) { mutableStateOf<List<ProjectScreenDto>?>(null) }
    var cursor by remember(project.id, nested) { mutableStateOf<String?>(null) }
    var failed by remember(project.id, nested) { mutableStateOf(false) }
    var attempt by remember { mutableIntStateOf(0) }
    LaunchedEffect(project.id, nested, attempt) {
        val page = vm.projectScreens(project.id, nested, null)
        failed = page == null
        list = page?.data.orEmpty()
        cursor = page?.nextCursor
    }
    val shown = list
    when {
        failed -> EchoErrorState(
            stringResource(R.string.v2_ws_screens_failed),
            stringResource(R.string.v2_ws_failed_body),
            actionLabel = stringResource(R.string.v2_ws_try_again),
            onAction = { attempt++ }
        )

        shown == null -> EchoSkeletonList(2, avatar = false)

        shown.isEmpty() -> EchoEmptyState(
            stringResource(R.string.v2_ws_screens_none_title),
            stringResource(R.string.v2_ws_screens_none_body),
            icon = EchoIcons.Screens
        )

        else -> Column(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            shown.chunked(2).forEachIndexed { row, pair ->
                Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
                    pair.forEachIndexed { i, s ->
                        val label = screenLabel(s.screen())
                        Column(
                            Modifier.weight(1f).clickable(role = Role.Button, onClickLabel = label) { onOpen(shown, row * 2 + i) },
                            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
                        ) {
                            ScreenPicture(s.screen(), full = false)
                            Text(label, style = EchoV2.type.mono, color = EchoV2.colors.textSecondary)
                            s.sessionTitle?.let {
                                Text(
                                    it,
                                    style = EchoV2.type.content(it, EchoV2.type.caption),
                                    color = EchoV2.colors.textPrimary,
                                    maxLines = 1
                                )
                            }
                        }
                    }
                    if (pair.size == 1) Spacer(Modifier.weight(1f))
                }
            }
            cursor?.let { next ->
                EchoButton(stringResource(R.string.v2_ws_show_more), {
                    scope.launch {
                        vm.projectScreens(project.id, nested, next)?.let { page ->
                            list = shown + page.data
                            cursor = page.nextCursor
                        }
                    }
                }, kind = EchoButtonKind.Quiet)
            }
        }
    }
}

/** A project row on the Projects list: its avatar, name, recordings and folders. */
@Composable
fun ProjectRow(project: ClientDto, recordings: Int, folders: Int, onOpen: () -> Unit) {
    EchoListRow(
        title = project.name,
        meta = listOfNotNull(recordingsCount(recordings), folders.takeIf { it > 0 }?.let { foldersCount(it) }).joinToString(", "),
        leading = { EchoAvatar(icon = EchoIcons.Folder) },
        onClick = onOpen
    )
}
