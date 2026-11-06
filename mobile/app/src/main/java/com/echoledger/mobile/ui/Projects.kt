package com.echoledger.mobile.ui

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Business
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Chat
import androidx.compose.material.icons.rounded.FileDownload
import androidx.compose.material.icons.rounded.Folder
import androidx.compose.material.icons.rounded.FolderShared
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.Link
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.VideoCall
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.FilingRuleDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.RequestLinkDto
import com.echoledger.mobile.data.ShareLinkDto
import com.echoledger.mobile.data.SharedItemDto
import com.echoledger.mobile.ui.design.Avatar
import com.echoledger.mobile.ui.design.BannerKind
import com.echoledger.mobile.ui.design.BarIcon
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.EchoEmptyState
import com.echoledger.mobile.ui.design.EchoTopBar
import com.echoledger.mobile.ui.design.IconTile
import com.echoledger.mobile.ui.design.InlineBanner
import com.echoledger.mobile.ui.design.LibraryItemRow
import com.echoledger.mobile.ui.design.ListPadding
import com.echoledger.mobile.ui.design.RecordingRow
import com.echoledger.mobile.ui.design.SectionHeader
import com.echoledger.mobile.ui.design.SkeletonRow
import com.echoledger.mobile.ui.design.SuccessCheck
import com.echoledger.mobile.ui.design.rowTime
import java.time.Instant
import java.time.LocalDate
import java.time.temporal.ChronoUnit
import kotlinx.coroutines.launch

/**
 * Projects (since v1.5): the client folders with subfolders. Pure and unit-tested: children, roots, the breadcrumbs,
 * and where a project may move (never under itself or its own subfolders, at most [MAX_DEPTH] levels).
 */
object ProjectTree {
    const val MAX_DEPTH = 4

    fun sorted(list: List<ClientDto>): List<ClientDto> = list.sortedWith(
        compareByDescending<ClientDto> {
            it.pinned
        }.thenBy { it.name.lowercase() }
    )

    /** Top-level projects (and any whose parent this phone does not know). */
    fun roots(all: List<ClientDto>): List<ClientDto> = sorted(all.filter { p -> p.parentId == null || all.none { it.id == p.parentId } })

    fun children(all: List<ClientDto>, id: String): List<ClientDto> = sorted(all.filter { it.parentId == id })

    /** The ancestors of [id] from the top (without it); guards against a cycle in bad data. */
    fun ancestors(all: List<ClientDto>, id: String): List<ClientDto> {
        val byId = all.associateBy { it.id }
        val out = ArrayDeque<ClientDto>()
        var at = byId[id]?.parentId
        val seen = mutableSetOf(id)
        while (at != null && seen.add(at)) {
            val parent = byId[at] ?: break
            out.addFirst(parent)
            at = parent.parentId
        }
        return out.toList()
    }

    fun descendants(all: List<ClientDto>, id: String): Set<String> {
        val out = mutableSetOf<String>()
        val queue = ArrayDeque(listOf(id))
        while (queue.isNotEmpty()) {
            val next = queue.removeFirst()
            all.filter { it.parentId == next && out.add(it.id) }.forEach { queue.addLast(it.id) }
        }
        return out
    }

    /** 1 for a top-level project. */
    fun depth(all: List<ClientDto>, id: String): Int = ancestors(all, id).size + 1

    /** Levels from [id] down to its deepest subfolder, itself included. */
    fun height(all: List<ClientDto>, id: String): Int = 1 + (children(all, id).maxOfOrNull { height(all, it.id) } ?: 0)

    /** Whether [id] may move into [target] (null = the top level). */
    fun canMove(all: List<ClientDto>, id: String, target: String?): Boolean {
        if (target == null) return height(all, id) <= MAX_DEPTH
        if (target == id || target in descendants(all, id)) return false
        return depth(all, target) + height(all, id) <= MAX_DEPTH
    }

    /** Every project in tree order with its depth (0 = top level), for pickers. */
    fun flatten(all: List<ClientDto>): List<Pair<ClientDto, Int>> {
        val out = mutableListOf<Pair<ClientDto, Int>>()
        fun walk(list: List<ClientDto>, depth: Int) {
            list.forEach {
                out += it to depth
                walk(children(all, it.id), depth + 1)
            }
        }
        walk(roots(all), 0)
        return out
    }

    /** Recordings directly in [id], or also in its subfolders. */
    fun recordings(all: List<ClientDto>, library: List<LibraryItem>, id: String, nested: Boolean): List<LibraryItem> {
        val ids = if (nested) descendants(all, id) + id else setOf(id)
        return library.filter { !it.deleted && it.client?.id in ids }.sortedByDescending { it.startedAt }
    }
}

/**
 * The smart folders (since v1.5): views of the Library, never copies. Most are worked out from this phone's copy of
 * the Library; [server] ones (imported files and what teammates sent) come from the server.
 */
enum class SmartFolder(val key: String, val label: String, val icon: ImageVector, val server: Boolean = false) {
    INBOX("inbox", "Inbox", Icons.Rounded.Inbox),
    WHATSAPP("whatsapp", "WhatsApp", Icons.Rounded.Chat),
    WHATSAPP_BUSINESS("whatsapp_business", "WhatsApp Business", Icons.Rounded.Business),
    GOOGLE_MEET("google_meet", "Google Meet", Icons.Rounded.VideoCall),
    ZOOM("zoom", "Zoom", Icons.Rounded.VideoCall),
    TEAMS("teams", "Teams", Icons.Rounded.Groups),
    PHONE("phone", "Phone", Icons.Rounded.Call),
    VOICE_NOTES("voice_notes", "Voice notes", Icons.Rounded.Mic),
    IMPORTED("imported", "Imported", Icons.Rounded.FileDownload, server = true),
    SHARED("shared", "Shared with me", Icons.Rounded.FolderShared, server = true);

    fun matches(item: LibraryItem): Boolean = !item.deleted &&
        when (this) {
            INBOX -> item.client == null
            WHATSAPP -> item.sourceApp == SourceApp.WHATSAPP.wire
            WHATSAPP_BUSINESS -> item.sourceApp == SourceApp.WHATSAPP_BUSINESS.wire
            GOOGLE_MEET -> item.sourceApp == SourceApp.GOOGLE_MEET.wire
            ZOOM -> item.sourceApp == SourceApp.ZOOM.wire
            TEAMS -> item.sourceApp == SourceApp.MICROSOFT_TEAMS.wire
            PHONE -> item.sourceApp == SourceApp.PHONE_CALL.wire
            VOICE_NOTES -> item.sourceApp == SourceApp.VOICE_NOTE.wire
            IMPORTED, SHARED -> false
        }

    companion object {
        fun of(key: String?): SmartFolder? = entries.firstOrNull { it.key == key }

        /**
         * The folders the Projects tab lists, with their counts (the desktop's rule): the Inbox always, Shared with me
         * when the server has it, the others once they hold something. [server] are the server's counts (null: offline or
         * an older server), [local] this phone's own count of a folder it can work out.
         */
        fun listed(server: Map<String, Int>?, local: (SmartFolder) -> Int?): List<Pair<SmartFolder, Int?>> = entries.mapNotNull { folder ->
            val count = server?.get(folder.key) ?: if (folder.server) null else local(folder)
            val shown = when (folder) {
                INBOX -> true
                SHARED -> server?.containsKey(folder.key) == true
                else -> (count ?: 0) > 0
            }
            if (shown) folder to count else null
        }
    }
}

/** Where the Projects tab goes next when asked from outside it (a link opened in the app, since v1.5). */
sealed interface ProjectsTarget {
    data class Project(val id: String) : ProjectsTarget

    data class Folder(val key: String) : ProjectsTarget

    data object Requests : ProjectsTarget
}

/** The Projects tab (since v1.5, was Clients): smart folders, then the projects; a project opens its page. */
@Composable
fun ProjectsScreen(
    vm: MainViewModel,
    onOpenItem: (LibraryItem) -> Unit,
    scrollToTop: Int = 0,
    today: LocalDate = LocalDate.now(),
    onOpenAt: (String, Long) -> Unit = { _, _ -> }
) {
    val projects by vm.clients.collectAsState()
    val library by vm.library.collectAsState()
    var openId by rememberSaveable { mutableStateOf<String?>(null) }
    var folderKey by rememberSaveable { mutableStateOf<String?>(null) }
    var requests by rememberSaveable { mutableStateOf(false) }
    var naming by remember { mutableStateOf<String?>(null) } // a parent id ("" = top level) while the new-project dialog is open
    val scope = rememberCoroutineScope()
    val list = rememberLazyListState()
    // The server's folder counts (since v1.5), looked up again whenever the library changes.
    var serverCounts by remember { mutableStateOf<Map<String, Int>?>(null) }
    LaunchedEffect(library) { vm.folderCounts()?.let { serverCounts = it } }
    val target by vm.projectsTarget.collectAsState()
    LaunchedEffect(target) {
        when (val t = target ?: return@LaunchedEffect) {
            is ProjectsTarget.Project -> {
                folderKey = null
                requests = false
                openId = t.id
            }

            is ProjectsTarget.Folder -> folderKey = t.key

            ProjectsTarget.Requests -> requests = true
        }
        vm.openInProjects(null)
    }
    LaunchedEffect(scrollToTop) {
        if (scrollToTop > 0) {
            when {
                folderKey != null -> folderKey = null
                requests -> requests = false
                openId != null -> openId = projects.firstOrNull { it.id == openId }?.parentId
                else -> list.animateScrollToItem(0)
            }
        }
    }
    val live = library.filter { !it.deleted }
    SmartFolder.of(folderKey)?.let { folder ->
        FolderScreen(vm, folder, live, today, onOpenItem, onBack = { folderKey = null })
        return
    }
    if (requests) {
        RequestLinksScreen(vm, projects, onBack = { requests = false })
        return
    }
    val open = projects.firstOrNull { it.id == openId }
    if (open != null) {
        ProjectPage(vm, open, projects, live, today, onOpenItem, onOpenAt, onOpenProject = { openId = it }, onBack = {
            openId =
                open.parentId
        })
        return
    }
    LazyColumn(Modifier.fillMaxSize(), state = list, contentPadding = ListPadding) {
        item(key = "top") {
            EchoTopBar("Projects", actions = {
                BarIcon(Icons.Rounded.Link, "Request files", { requests = true })
                BarIcon(Icons.Rounded.Add, "New project", { naming = "" })
            })
        }
        item(key = "h-folders") { SectionHeader("Folders") }
        val folders = SmartFolder.listed(serverCounts) { folder -> live.count(folder::matches) }
        itemsIndexed(folders, key = { _, it -> "f-${it.first.key}" }) { i, (folder, count) ->
            RecordingRow(
                title = folder.label,
                time = "",
                meta = count?.let(::countLabel) ?: "",
                tile = { IconTile(folder.icon, Brand.AccentText) },
                onClick = { folderKey = folder.key },
                onLongClick = { folderKey = folder.key },
                divider = i < folders.lastIndex
            )
        }
        item(key = "h-projects") { SectionHeader("Projects") }
        val roots = ProjectTree.roots(projects)
        if (roots.isEmpty()) {
            item(key = "empty") {
                EchoEmptyState(
                    Icons.Rounded.Folder,
                    "No projects yet",
                    body = "A project keeps a client's calls, files and notes together. Calls with its contacts file themselves.",
                    action = "New project",
                    onAction = { naming = "" }
                )
            }
        }
        itemsIndexed(roots, key = { _, it -> it.id }) { i, project ->
            ProjectRow(project, projects, live, today, onClick = { openId = project.id }, divider = i < roots.lastIndex)
        }
    }
    naming?.let { parent ->
        ProjectNameDialog("New project", "", onDismiss = { naming = null }) { name ->
            naming = null
            scope.launch { vm.createProject(name, parent.ifEmpty { null })?.let { openId = it.id } }
        }
    }
}

@Composable
private fun ProjectRow(
    project: ClientDto,
    all: List<ClientDto>,
    live: List<LibraryItem>,
    today: LocalDate,
    onClick: () -> Unit,
    divider: Boolean
) {
    val mine = live.filter { it.client?.id == project.id }
    val count = if (mine.isNotEmpty()) mine.size else project.sessionCount
    val folders = ProjectTree.children(all, project.id).size.takeIf { it > 0 } ?: project.childrenCount
    val last = mine.maxByOrNull { it.startedAt.orEmpty() }?.startedAt
    RecordingRow(
        title = project.name,
        time = "",
        meta = listOfNotNull(
            countLabel(count),
            folders.takeIf { it > 0 }?.let { if (it == 1) "1 folder" else "$it folders" },
            last?.let { "Last call ${rowTime(it, today)}" }
        ).joinToString(" · "),
        tile = { Avatar(project.name, size = 44.dp) },
        onClick = onClick,
        onLongClick = onClick,
        divider = divider
    )
}

/** A project's page: breadcrumbs, its subfolders, the v1.4 workspace tabs, and the project's menu. */
@Composable
private fun ProjectPage(
    vm: MainViewModel,
    project: ClientDto,
    all: List<ClientDto>,
    live: List<LibraryItem>,
    today: LocalDate,
    onOpenItem: (LibraryItem) -> Unit,
    onOpenAt: (String, Long) -> Unit,
    onOpenProject: (String?) -> Unit,
    onBack: () -> Unit
) {
    val scope = rememberCoroutineScope()
    var sheet by remember { mutableStateOf<String?>(null) }
    var nested by rememberSaveable(project.id) { mutableStateOf(false) }
    val recordings = ProjectTree.recordings(all, live, project.id, nested)
    ClientWorkspaceScreen(
        vm,
        project,
        recordings,
        today,
        onOpenItem,
        onOpenAt,
        onBack = onBack,
        path = ProjectTree.ancestors(all, project.id),
        subfolders = ProjectTree.children(all, project.id),
        onOpenProject = onOpenProject,
        nested = nested,
        onNested = { nested = it },
        onMenu = { sheet = it }
    )
    when (sheet) {
        "subfolder" -> ProjectNameDialog("New folder in ${project.name}", "", onDismiss = { sheet = null }) { name ->
            sheet = null
            scope.launch { vm.createProject(name, project.id)?.let { onOpenProject(it.id) } }
        }

        "rename" -> ProjectNameDialog("Rename project", project.name, onDismiss = { sheet = null }) { name ->
            sheet = null
            scope.launch { vm.renameProject(project.id, name) }
        }

        "move" -> ProjectPickerSheet(
            title = "Move ${project.name} to",
            all = all,
            allow = { target -> ProjectTree.canMove(all, project.id, target) },
            topLabel = "Top level",
            onDismiss = { sheet = null }
        ) { target ->
            sheet = null
            scope.launch { vm.moveProject(project.id, target) }
        }

        "share" -> ShareSheet(vm, sessionId = null, clientId = project.id, title = project.name, onDismiss = { sheet = null })

        "send" -> SendSheet(vm, sessionId = null, clientId = project.id, title = project.name, onDismiss = { sheet = null })

        "rules" -> FilingRulesSheet(vm, project, onDismiss = { sheet = null })

        "delete" -> AlertDialog(
            onDismissRequest = { sheet = null },
            title = { Text("Delete ${project.name}?") },
            text = {
                Text(
                    "Its recordings go to the Inbox and its folders move up a level; nothing is lost. Its files, notes and filing rules are deleted."
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    sheet = null
                    scope.launch { if (vm.deleteProject(project.id)) onOpenProject(project.parentId) }
                }) { Text("Delete", color = Brand.Danger) }
            },
            dismissButton = { TextButton(onClick = { sheet = null }) { Text("Cancel") } }
        )
    }
}

/** A smart folder's recordings; Shared with me lists what teammates sent, with Save to my library. */
@Composable
private fun FolderScreen(
    vm: MainViewModel,
    folder: SmartFolder,
    live: List<LibraryItem>,
    today: LocalDate,
    onOpenItem: (LibraryItem) -> Unit,
    onBack: () -> Unit
) {
    BackHandler(onBack = onBack)
    var server by remember(folder) { mutableStateOf<List<LibraryItem>?>(null) }
    var shared by remember(folder) { mutableStateOf<List<SharedItemDto>?>(null) }
    var failed by remember(folder) { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    LaunchedEffect(folder, reload) {
        when (folder) {
            SmartFolder.SHARED -> vm.sharedWithMe().let {
                shared = it
                failed = it == null
            }

            SmartFolder.IMPORTED -> vm.libraryFolder(folder.key).let {
                server = it
                failed = it == null
            }

            else -> Unit
        }
    }
    LazyColumn(Modifier.fillMaxSize(), contentPadding = ListPadding) {
        item(key = "top") {
            EchoTopBar(folder.label, large = false, navigation = {
                BarIcon(Icons.AutoMirrored.Rounded.ArrowBack, "Back", onBack, tint = Brand.Text)
            })
        }
        if (failed) {
            item(key = "failed") {
                InlineBanner(
                    "Couldn't load ${folder.label}. Check the connection.",
                    Modifier.padding(horizontal = Space.screen),
                    kind = BannerKind.Error,
                    action = "Retry",
                    onAction = { reload++ }
                )
            }
        }
        if (folder == SmartFolder.SHARED) {
            val list = shared
            when {
                list == null && !failed -> items(3, key = { "s-$it" }) { SkeletonRow() }

                list.isNullOrEmpty() -> item(key = "empty") {
                    EchoEmptyState(
                        folder.icon,
                        "Nothing shared with you",
                        body = "Recordings and projects teammates send you show up here."
                    )
                }

                else -> items(list, key = { it.id }) { shared ->
                    SharedRow(shared, today, onOpenItem, onSave = { scope.launch { if (vm.saveShared(shared.id)) reload++ } }, onRemove = {
                        scope.launch { if (vm.removeShared(shared.id)) reload++ }
                    })
                }
            }
        } else {
            val list = if (folder.server) server else live.filter(folder::matches).sortedByDescending { it.startedAt }
            when {
                list == null && !failed -> items(3, key = { "s-$it" }) { SkeletonRow() }

                list.isNullOrEmpty() -> item(key = "empty") {
                    EchoEmptyState(folder.icon, "Nothing in ${folder.label}", body = SmartFolderText.empty(folder))
                }

                else -> items(list, key = {
                    it.id
                }) { item -> LibraryItemRow(item, today, onOpen = { onOpenItem(item) }, onToggle = { onOpenItem(item) }) }
            }
        }
    }
}

internal object SmartFolderText {
    fun empty(folder: SmartFolder): String = when (folder) {
        SmartFolder.INBOX -> "Recordings that are in no project show up here."
        SmartFolder.IMPORTED -> "Files you import, upload in the console or receive through a request link show up here."
        else -> "${folder.label} recordings show up here."
    }
}

@Composable
private fun SharedRow(
    shared: SharedItemDto,
    today: LocalDate,
    onOpenItem: (LibraryItem) -> Unit,
    onSave: () -> Unit,
    onRemove: () -> Unit
) {
    val type = LocalEchoType.current
    Column(Modifier.fillMaxWidth().padding(horizontal = Space.screen, vertical = Space.s2)) {
        val from = shared.from?.name ?: shared.from?.email ?: "A teammate"
        Text(shared.title ?: shared.session?.title ?: shared.client?.name ?: "Shared", style = type.bodyL, color = Brand.Text)
        Text(
            listOfNotNull(
                "From $from",
                shared.sentAt?.let {
                    rowTime(it, today)
                },
                shared.note?.takeIf { it.isNotBlank() }
            ).joinToString(" · "),
            style = type.labelM,
            color = Brand.Muted
        )
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
            shared.session?.let { s -> EchoButton("Open", { onOpenItem(s) }, kind = ButtonKind.Text) }
            if (shared.savedAt ==
                null
            ) {
                EchoButton("Save to my library", onSave, kind = ButtonKind.Text)
            } else {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    SuccessCheck(Brand.Muted, Modifier.padding(end = Space.s1), size = 14.dp)
                    Text("Saved", style = type.labelM, color = Brand.Muted)
                }
            }
            EchoButton("Remove", onRemove, kind = ButtonKind.Text)
        }
    }
}

@Composable
internal fun ProjectNameDialog(title: String, initial: String, onDismiss: () -> Unit, onSave: (String) -> Unit) {
    var name by remember { mutableStateOf(initial) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            OutlinedTextField(name, {
                name = it.take(120)
            }, singleLine = true, label = { Text("Name") }, modifier = Modifier.fillMaxWidth())
        },
        confirmButton = {
            TextButton(onClick = { onSave(name.trim()) }, enabled = name.isNotBlank() && name.trim() != initial) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } }
    )
}

/**
 * Pick a project (indented by level) or the top row ([topLabel]: "Inbox" for recordings, "Top level" for a project);
 * [allow] greys out the ones it may not go to.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ProjectPickerSheet(
    title: String,
    all: List<ClientDto>,
    allow: (String?) -> Boolean = { true },
    topLabel: String = "Inbox",
    onDismiss: () -> Unit,
    onPick: (String?) -> Unit
) {
    val type = LocalEchoType.current
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = LocalEchoColors.current.surface) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(bottom = Space.s5)) {
            Text(
                title,
                style = type.titleS,
                color = Brand.Text,
                modifier = Modifier.padding(horizontal = Space.screen, vertical = Space.s2)
            )
            val rows = listOf<Pair<ClientDto?, Int>>(null to 0) + ProjectTree.flatten(all)
            rows.forEach { (project, depth) ->
                val id = project?.id
                val ok = allow(id)
                Row(
                    Modifier.fillMaxWidth()
                        .selectable(selected = false, enabled = ok, role = Role.Button, onClick = { onPick(id) })
                        .padding(start = Space.screen + (depth * 20).dp, end = Space.screen, top = Space.s3, bottom = Space.s3),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    androidx.compose.material3.Icon(
                        if (project == null) Icons.Rounded.Inbox else Icons.Rounded.Folder,
                        contentDescription = null,
                        tint = if (ok) Brand.AccentText else Brand.Muted
                    )
                    Spacer(Modifier.width(Space.s3))
                    Text(project?.name ?: topLabel, style = type.bodyL, color = if (ok) Brand.Text else Brand.Muted)
                }
            }
        }
    }
}

/** How long a new link works: never, a day, a week or a month. */
internal object LinkExpiry {
    val CHOICES = listOf(0 to "Never", 1 to "1 day", 7 to "7 days", 30 to "30 days")

    fun at(days: Int, now: Instant = Instant.now()): String? = if (days <= 0) null else now.plus(days.toLong(), ChronoUnit.DAYS).toString()

    /** Comma, space or newline separated emails, trimmed, at most 20, the invalid ones dropped. */
    fun emails(text: String): List<String> =
        text.split(',', ';', ' ', '\n').map { it.trim() }.filter { it.contains('@') && it.length in 3..254 }.distinct().take(20)
}

/**
 * Share a recording or a project (since v1.5): anyone with the link, a password, or only the people chosen (signed in
 * to the console with one of those emails); downloads on or off; when it ends. The link is shown once, then the list
 * of links with their views and Revoke.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ShareSheet(vm: MainViewModel, sessionId: String?, clientId: String?, title: String, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val type = LocalEchoType.current
    val scope = rememberCoroutineScope()
    var audience by remember { mutableStateOf("public") }
    var password by remember { mutableStateOf("") }
    var people by remember { mutableStateOf("") }
    var download by remember { mutableStateOf(true) }
    var days by remember { mutableIntStateOf(7) }
    var made by remember { mutableStateOf<ShareLinkDto?>(null) }
    var links by remember { mutableStateOf<List<ShareLinkDto>>(emptyList()) }
    var working by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    LaunchedEffect(reload) { links = if (clientId != null) vm.projectShares(clientId) else vm.shares(sessionId!!) }
    val ready = when (audience) {
        "password" -> password.length >= 6
        "people" -> LinkExpiry.emails(people).isNotEmpty()
        else -> true
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = LocalEchoColors.current.surface) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = Space.screen).padding(bottom = Space.s5)
        ) {
            Text("Share $title", style = type.titleS, color = Brand.Text, modifier = Modifier.padding(vertical = Space.s2))
            listOf(
                "public" to "Anyone with the link",
                "password" to "Anyone with the link and a password",
                "people" to "Only people I choose"
            ).forEach { (value, label) ->
                Row(
                    Modifier.fillMaxWidth().selectable(selected = audience == value, role = Role.RadioButton, onClick = {
                        audience = value
                    }),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    RadioButton(selected = audience == value, onClick = null)
                    Text(label, style = type.body, color = Brand.Text, modifier = Modifier.padding(start = Space.s2))
                }
            }
            if (audience == "password") {
                OutlinedTextField(password, {
                    password = it.take(100)
                }, singleLine = true, label = { Text("Password (6 or more characters)") }, modifier = Modifier.fillMaxWidth())
            }
            if (audience == "people") {
                OutlinedTextField(
                    people,
                    { people = it },
                    label = { Text("Their emails") },
                    supportingText = { Text("They open it signed in to the console with one of these emails.") },
                    modifier = Modifier.fillMaxWidth()
                )
            }
            Row(Modifier.fillMaxWidth().padding(vertical = Space.s2), verticalAlignment = Alignment.CenterVertically) {
                Text("Allow downloads (audio and transcript)", style = type.body, color = Brand.Text, modifier = Modifier.weight(1f))
                Switch(checked = download, onCheckedChange = { download = it })
            }
            Text("Link works for", style = type.labelM, color = Brand.Muted)
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                LinkExpiry.CHOICES.forEach { (d, label) ->
                    com.echoledger.mobile.ui.design.EchoFilterChip(label, active = days == d, onClick = { days = d })
                }
            }
            Spacer(Modifier.height(Space.s3))
            EchoButton(if (working) "Making the link…" else "Make link", {
                working = true
                scope.launch {
                    made =
                        vm.createShareLink(
                            sessionId,
                            clientId,
                            audience,
                            password,
                            LinkExpiry.emails(people),
                            download,
                            LinkExpiry.at(days)
                        )
                    working = false
                    reload++
                }
            }, enabled = ready && !working)
            made?.url?.let { url ->
                Spacer(Modifier.height(Space.s3))
                Text(url, style = type.labelM, color = Brand.Text)
                Text("Copy it now: it is shown only once.", style = type.caption, color = Brand.Muted)
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                    EchoButton("Copy", { copyLink(context, url) }, kind = ButtonKind.Secondary)
                    EchoButton("Share…", { shareLink(context, url) }, kind = ButtonKind.Text)
                }
            }
            if (links.isNotEmpty()) {
                SectionHeader("Links")
                links.forEach { link ->
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(ShareText.audience(link), style = type.bodyM, color = Brand.Text)
                            Text(ShareText.meta(link), style = type.caption, color = Brand.Muted)
                        }
                        if (link.revokedAt == null) {
                            EchoButton("Revoke", { scope.launch { if (vm.revokeShare(link.id)) reload++ } }, kind = ButtonKind.Text)
                        }
                    }
                }
            }
        }
    }
}

internal object ShareText {
    fun audience(link: ShareLinkDto): String = when (link.audience) {
        "password" -> "Password"
        "people" -> "Only " + link.people.joinToString(", ").ifEmpty { "chosen people" }
        else -> if (link.hasPassword) "Password" else "Anyone with the link"
    }

    fun meta(link: ShareLinkDto): String = listOfNotNull(
        if (link.revokedAt != null) "Revoked" else null,
        if (link.viewCount == 1) "1 view" else "${link.viewCount} views",
        if ((link.allowDownload ?: link.includeAudio)) "downloads on" else "no downloads",
        link.expiresAt?.let { "until ${it.take(10)}" }
    ).joinToString(" · ")
}

/** Send a recording or a project to teammates by email (since v1.5); it lands in their Shared with me. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun SendSheet(vm: MainViewModel, sessionId: String?, clientId: String?, title: String, onDismiss: () -> Unit) {
    val type = LocalEchoType.current
    val scope = rememberCoroutineScope()
    var to by remember { mutableStateOf("") }
    var note by remember { mutableStateOf("") }
    var working by remember { mutableStateOf(false) }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = LocalEchoColors.current.surface) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = Space.screen).padding(bottom = Space.s5),
            verticalArrangement = Arrangement.spacedBy(Space.s2)
        ) {
            Text("Send $title to a teammate", style = type.titleS, color = Brand.Text)
            OutlinedTextField(to, { to = it }, label = { Text("Their emails") }, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(note, { note = it.take(500) }, label = { Text("Note (optional)") }, modifier = Modifier.fillMaxWidth())
            Text("It appears in their Shared with me; they can save a copy to their library.", style = type.caption, color = Brand.Muted)
            EchoButton(if (working) "Sending…" else "Send", {
                working = true
                scope.launch {
                    if (vm.sendToTeammates(sessionId, clientId, LinkExpiry.emails(to), note)) onDismiss()
                    working = false
                }
            }, enabled = LinkExpiry.emails(to).isNotEmpty() && !working)
        }
    }
}

/** A project's filing rules (since v1.5): recordings that end with no project are filed by these. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun FilingRulesSheet(vm: MainViewModel, project: ClientDto, onDismiss: () -> Unit) {
    val type = LocalEchoType.current
    val scope = rememberCoroutineScope()
    var rules by remember { mutableStateOf<List<FilingRuleDto>?>(null) }
    var kind by remember { mutableStateOf("title") }
    var value by remember { mutableStateOf("") }
    var reload by remember { mutableIntStateOf(0) }
    LaunchedEffect(reload) { rules = vm.filingRules(project.id) }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = LocalEchoColors.current.surface) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = Space.screen).padding(bottom = Space.s5)
        ) {
            Text(
                "Filing rules for ${project.name}",
                style = type.titleS,
                color = Brand.Text,
                modifier = Modifier.padding(vertical = Space.s2)
            )
            Text(
                "A recording that ends in no project is filed here when the caller is one of its contacts, its title contains the text, or it came from the app.",
                style = type.caption,
                color = Brand.Muted
            )
            rules?.forEach { rule ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(FilingRuleText.label(rule), style = type.bodyM, color = Brand.Text, modifier = Modifier.weight(1f))
                    EchoButton("Remove", {
                        scope.launch { if (vm.deleteFilingRule(project.id, rule.id)) reload++ }
                    }, kind = ButtonKind.Text)
                }
            }
            SectionHeader("Add a rule")
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                FilingRuleText.KINDS.forEach { (k, label) ->
                    com.echoledger.mobile.ui.design.EchoFilterChip(label, active = kind == k, onClick = {
                        kind =
                            k
                    })
                }
            }
            OutlinedTextField(value, {
                value = it.take(120)
            }, singleLine = true, label = { Text(FilingRuleText.hint(kind)) }, modifier = Modifier.fillMaxWidth())
            EchoButton("Add", {
                scope.launch {
                    if (vm.addFilingRule(project.id, kind, value) != null) {
                        value = ""
                        reload++
                    }
                }
            }, enabled = value.isNotBlank())
        }
    }
}

internal object FilingRuleText {
    val KINDS = listOf("title" to "Title contains", "contact" to "Contact", "app" to "App")

    fun hint(kind: String): String = when (kind) {
        "contact" -> "Phone number or email"
        "app" -> "App (for example whatsapp_business)"
        else -> "Text in the title"
    }

    fun label(rule: FilingRuleDto): String = when (rule.kind) {
        "contact" -> "Contact ${rule.value}"
        "app" -> "From " + (SourceApp.entries.firstOrNull { it.wire == rule.value }?.label ?: rule.value)
        else -> "Title contains \"${rule.value}\""
    }
}

/** Request files (since v1.5): links anyone can use to send voice notes and files into the Inbox or a project. */
@Composable
private fun RequestLinksScreen(vm: MainViewModel, projects: List<ClientDto>, onBack: () -> Unit) {
    BackHandler(onBack = onBack)
    val context = LocalContext.current
    val type = LocalEchoType.current
    val scope = rememberCoroutineScope()
    var links by remember { mutableStateOf<List<RequestLinkDto>?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var creating by remember { mutableStateOf(false) }
    var title by remember { mutableStateOf("") }
    var projectId by remember { mutableStateOf<String?>(null) }
    var picking by remember { mutableStateOf(false) }
    var days by remember { mutableIntStateOf(30) }
    var made by remember { mutableStateOf<RequestLinkDto?>(null) }
    LaunchedEffect(reload) { links = vm.requestLinks() }
    LazyColumn(Modifier.fillMaxSize(), contentPadding = ListPadding) {
        item(key = "top") {
            EchoTopBar("Request files", large = false, navigation = {
                BarIcon(Icons.AutoMirrored.Rounded.ArrowBack, "Back", onBack, tint = Brand.Text)
            }, actions = {
                BarIcon(Icons.Rounded.Add, "New request link", { creating = true })
            })
        }
        item(key = "intro") {
            Text(
                "Anyone with a request link can send you voice notes and audio (and files, for a project). They land in the Inbox or the project.",
                style = type.bodyM,
                color = Brand.Muted,
                modifier = Modifier.padding(horizontal = Space.screen)
            )
        }
        if (creating) {
            item(key = "new") {
                Column(Modifier.fillMaxWidth().padding(Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s2)) {
                    OutlinedTextField(title, {
                        title = it.take(120)
                    }, singleLine = true, label = { Text("Title they see") }, modifier = Modifier.fillMaxWidth())
                    EchoButton(
                        "Goes to: " + (
                            projects.firstOrNull {
                                it.id == projectId
                            }?.name ?: "Inbox"
                            ),
                        { picking = true },
                        kind = ButtonKind.Secondary
                    )
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                        LinkExpiry.CHOICES.forEach { (d, label) ->
                            com.echoledger.mobile.ui.design.EchoFilterChip(label, active = days == d, onClick = {
                                days =
                                    d
                            })
                        }
                    }
                    EchoButton("Make link", {
                        scope.launch {
                            made = vm.createRequestLink(title, projectId, LinkExpiry.at(days))
                            if (made != null) {
                                creating = false
                                title = ""
                                reload++
                            }
                        }
                    }, enabled = title.isNotBlank())
                }
            }
        }
        made?.url?.let { url ->
            item(key = "made") {
                Column(Modifier.fillMaxWidth().padding(horizontal = Space.screen, vertical = Space.s2)) {
                    Text(url, style = type.labelM, color = Brand.Text)
                    Text("Copy it now: it is shown only once.", style = type.caption, color = Brand.Muted)
                    Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                        EchoButton("Copy", { copyLink(context, url) }, kind = ButtonKind.Secondary)
                        EchoButton("Share…", { shareLink(context, url) }, kind = ButtonKind.Text)
                    }
                }
            }
        }
        val list = links
        when {
            list == null -> items(2, key = { "s-$it" }) { SkeletonRow() }

            list.isEmpty() && !creating -> item(key = "empty") {
                EchoEmptyState(
                    Icons.Rounded.Link,
                    "No request links",
                    body = "Make one and send it to a client.",
                    action = "New request link",
                    onAction = { creating = true }
                )
            }

            else -> items(list, key = { it.id }) { link ->
                Row(
                    Modifier.fillMaxWidth().padding(horizontal = Space.screen, vertical = Space.s2),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(link.title ?: "Request link", style = type.bodyL, color = Brand.Text)
                        Text(RequestLinkText.meta(link, projects), style = type.caption, color = Brand.Muted)
                    }
                    if (link.revokedAt ==
                        null
                    ) {
                        EchoButton("Turn off", { scope.launch { if (vm.revokeRequestLink(link.id)) reload++ } }, kind = ButtonKind.Text)
                    }
                }
            }
        }
    }
    if (picking) {
        ProjectPickerSheet("Files go to", projects, onDismiss = { picking = false }) {
            projectId = it
            picking = false
        }
    }
}

internal object RequestLinkText {
    fun meta(link: RequestLinkDto, projects: List<ClientDto>): String = listOfNotNull(
        if (link.revokedAt != null) "Off" else null,
        projects.firstOrNull { it.id == link.clientId }?.name ?: "Inbox",
        if (link.received == 1) "1 file received" else "${link.received} files received",
        link.expiresAt?.let { "until ${it.take(10)}" }
    ).joinToString(" · ")
}

internal fun copyLink(context: Context, text: String) {
    context.getSystemService(ClipboardManager::class.java)?.setPrimaryClip(ClipData.newPlainText("EchoLedger link", text))
}

internal fun shareLink(context: Context, text: String) {
    context.startActivity(
        Intent.createChooser(
            Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, text),
            null
        ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    )
}
