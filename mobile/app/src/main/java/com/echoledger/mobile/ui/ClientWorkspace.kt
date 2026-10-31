package com.echoledger.mobile.ui

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Group
import androidx.compose.material.icons.rounded.PushPin
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
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
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.data.AskAnswerDto
import com.echoledger.mobile.data.BriefSections
import com.echoledger.mobile.data.CallNotesDto
import com.echoledger.mobile.data.CitationDto
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.MemoryItemDto
import com.echoledger.mobile.data.WorkspaceDto
import com.echoledger.mobile.ui.design.Avatar
import com.echoledger.mobile.ui.design.BannerKind
import com.echoledger.mobile.ui.design.BarIcon
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.EchoEmptyState
import com.echoledger.mobile.ui.design.EchoTopBar
import com.echoledger.mobile.ui.design.InlineBanner
import com.echoledger.mobile.ui.design.LibraryItemRow
import com.echoledger.mobile.ui.design.ListPadding
import com.echoledger.mobile.ui.design.SectionHeader
import com.echoledger.mobile.ui.design.SegmentedControl
import com.echoledger.mobile.ui.design.SkeletonRow
import com.echoledger.mobile.ui.design.Spinner
import java.time.LocalDate
import java.util.Locale
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** The words and small rules of a client workspace (since 1.4). */
object ClientFiles {
    /** The type a picked file is sent as: PDF, Word, text or Markdown (the server says no to anything else). */
    fun mime(mime: String, name: String): String = when (name.substringAfterLast('.', "").lowercase()) {
        "pdf" -> "application/pdf"
        "docx" -> "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
        "md", "markdown" -> "text/markdown"
        "txt" -> "text/plain"
        else -> mime
    }

    /** "AI this month: $0.42 of $5.00 · this client $0.12". */
    fun budget(spent: Double, budget: Double, client: Double): String =
        String.format(Locale.ENGLISH, "AI this month: $%.2f of $%.2f · this client $%.2f", spent, budget, client)

    /** Memory kinds, in the order they are shown, with their headings. */
    val KINDS = listOf("decision" to "Decisions", "question" to "Open questions", "change" to "Changes", "scope" to "Scope")

    /** 754 000 ms → "12:34". */
    fun clock(ms: Long): String {
        val s = (ms / 1000).coerceAtLeast(0)
        return if (s >=
            3600
        ) {
            String.format(Locale.ENGLISH, "%d:%02d:%02d", s / 3600, s % 3600 / 60, s % 60)
        } else {
            String.format(
                Locale.ENGLISH,
                "%d:%02d",
                s / 60,
                s % 60
            )
        }
    }

    /** "Answer in English" / "Roman Urdu" choice → the API's value. */
    val LANGUAGES = listOf("en" to "English", "roman_urdu" to "Roman Urdu")
}

private val TABS = listOf("Overview", "Recordings", "Files", "Ask")

/**
 * A client's page (since 1.4): Overview (AI notes switch, brief, memory, latest call notes, Copy for AI), Recordings,
 * Files and Ask. [onOpenAt] opens a recording at a moment (a note's or an answer's citation).
 */
@Composable
fun ClientWorkspaceScreen(
    vm: MainViewModel,
    client: ClientDto,
    recordings: List<LibraryItem>,
    today: LocalDate,
    onOpenItem: (LibraryItem) -> Unit,
    onOpenAt: (String, Long) -> Unit,
    onBack: () -> Unit
) {
    BackHandler(onBack = onBack)
    var tab by rememberSaveable(client.id) { mutableIntStateOf(0) }
    var workspace by remember(client.id) { mutableStateOf<WorkspaceDto?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    LaunchedEffect(client.id, reload) { vm.workspace(client.id)?.let { workspace = it } }
    // While the brief or notes are being written on the server, look again every 5 s; stop once they are done.
    val working =
        workspace?.let { w ->
            w.brief.status == "working" || w.notes.any { it.status == "pending" } ||
                w.files.any { it.status == "extracting" }
        } ==
            true
    LaunchedEffect(working) {
        while (working) {
            delay(5_000)
            reload++
        }
    }
    LazyColumn(Modifier.fillMaxSize(), contentPadding = ListPadding) {
        item(key = "top") {
            EchoTopBar(client.name, large = false, navigation = {
                BarIcon(Icons.AutoMirrored.Rounded.ArrowBack, "Back", onBack, tint = Brand.Text)
            })
        }
        item(key = "head") {
            Row(
                Modifier.fillMaxWidth().padding(horizontal = Space.screen, vertical = Space.s2),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Avatar(client.name, size = 48.dp)
                Column(Modifier.padding(start = Space.s3)) {
                    Text(client.name, style = LocalEchoType.current.titleM, color = Brand.Text)
                    Text(countLabel(recordings.size), style = LocalEchoType.current.labelM, color = Brand.Muted)
                }
            }
        }
        item(key = "tabs") { SegmentedControl(TABS, tab, { tab = it }, Modifier.padding(horizontal = Space.screen, vertical = Space.s2)) }
        val w = workspace
        when {
            tab == 1 -> {
                if (recordings.isEmpty()) {
                    item(key = "empty") {
                        EchoEmptyState(
                            Icons.Rounded.Group,
                            "No recordings yet",
                            body = "Calls and notes you move to ${client.name} show up here."
                        )
                    }
                }
                items(recordings, key = {
                    it.id
                }) { item -> LibraryItemRow(item, today, onOpen = { onOpenItem(item) }, onToggle = { onOpenItem(item) }) }
            }

            w == null -> items(3, key = { "skeleton-$it" }) { SkeletonRow() }

            tab == 0 -> item(key = "overview") {
                OverviewTab(vm, client, w, onOpenAt, onChanged = { reload++ }, onWorkspace = { workspace = it })
            }

            tab == 2 -> item(key = "files") { FilesTab(vm, client, w, onChanged = { reload++ }) }

            else -> item(key = "ask") { AskTab(vm, client, onOpenAt) }
        }
    }
}

@Composable
private fun OverviewTab(
    vm: MainViewModel,
    client: ClientDto,
    w: WorkspaceDto,
    onOpenAt: (String, Long) -> Unit,
    onChanged: () -> Unit,
    onWorkspace: (WorkspaceDto) -> Unit
) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var editing by remember { mutableStateOf(false) }
    var adding by remember { mutableStateOf<String?>(null) }
    Column(Modifier.padding(horizontal = Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s3)) {
        if (!w.ai.configured) {
            InlineBanner(
                "Add your Anthropic key in the console's API Credentials to get call notes, the brief and Ask.",
                kind = BannerKind.Warning
            )
        }
        SectionCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("AI notes", style = type.bodyL, color = c.text)
                    Text("Notes and memory after every call with ${client.name}", style = type.labelM, color = c.textMuted)
                }
                Switch(
                    checked = w.client.aiNotes,
                    onCheckedChange = { on ->
                        onWorkspace(w.copy(client = w.client.copy(aiNotes = on)))
                        scope.launch { vm.setAiNotes(client.id, on) }
                    },
                    modifier = Modifier.semantics { contentDescription = "AI notes" }
                )
            }
            Text(ClientFiles.budget(w.ai.spentUsd, w.ai.budgetUsd, w.ai.clientSpentUsd), style = type.caption, color = c.textMuted)
        }
        // The brief: one page, made from the files (or the calls); a person's edit is kept.
        SectionCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Brief", style = type.titleM, color = c.text, modifier = Modifier.weight(1f))
                if (w.brief.status == "working") Spinner(16.dp)
                EchoButton("Edit", { editing = true }, kind = ButtonKind.Text)
                EchoButton("Make again", { scope.launch { if (vm.refreshBrief(client.id) != null) onChanged() } }, kind = ButtonKind.Text)
            }
            w.brief.error?.let { Text(it, style = type.labelM, color = c.danger) }
            if (w.brief.sections.isEmpty) {
                Text(
                    "Add files or keep a call with ${client.name}: the brief writes itself from them.",
                    style = type.labelM,
                    color = c.textMuted
                )
            } else {
                if (w.brief.sections.goal.isNotBlank()) Text(w.brief.sections.goal, style = type.bodyL, color = c.text)
                w.brief.sections.lists().filter { it.second.isNotEmpty() }.forEach { (heading, lines) ->
                    Text(heading, style = type.labelL, color = c.textMuted, modifier = Modifier.padding(top = Space.s1))
                    lines.forEach { Text("• $it", style = type.bodyM, color = c.text) }
                }
            }
        }
        // Memory: decisions, open questions, changes; each line cites its call (tap to hear it).
        SectionCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Memory", style = type.titleM, color = c.text, modifier = Modifier.weight(1f))
                EchoButton("Add", { adding = "decision" }, kind = ButtonKind.Text)
            }
            if (w.memory.isEmpty()) {
                Text(
                    "Decisions, open questions and changes from the calls collect here.",
                    style = type.labelM,
                    color = c.textMuted
                )
            }
            ClientFiles.KINDS.forEach { (kind, heading) ->
                val lines = w.memory.filter { it.kind == kind }
                if (lines.isNotEmpty()) {
                    Text(heading, style = type.labelL, color = c.textMuted, modifier = Modifier.padding(top = Space.s1))
                    lines.forEach { line ->
                        MemoryLine(line, onOpenAt, onPin = {
                            scope.launch { if (vm.updateMemory(client.id, line.id, pinned = !line.pinned) != null) onChanged() }
                        }, onDelete = {
                            scope.launch { if (vm.deleteMemory(client.id, line.id) != null) onChanged() }
                        })
                    }
                }
            }
        }
        if (w.notes.isNotEmpty()) {
            Text("Call notes", style = type.titleM, color = c.text, modifier = Modifier.padding(top = Space.s2))
            w.notes.forEach { note -> NotesCard(note, onOpenAt) }
        }
        EchoButton("Copy for AI", {
            scope.launch {
                vm.bundle(client.id)?.let { text ->
                    copyText(context, "${client.name} project context", text)
                    vm.showMessage("Copied: brief, memory and the latest call notes")
                }
            }
        }, Modifier.fillMaxWidth(), kind = ButtonKind.Secondary, icon = Icons.Rounded.ContentCopy)
    }
    if (editing) {
        BriefEditor(w.brief.sections, onSave = { sections ->
            editing = false
            scope.launch { vm.saveBrief(client.id, sections)?.let { onWorkspace(w.copy(brief = it)) } }
        }, onDismiss = { editing = false })
    }
    adding?.let { kind ->
        MemoryAdder(kind, onAdd = { k, text ->
            adding = null
            scope.launch { if (vm.addMemory(client.id, k, text) != null) onChanged() }
        }, onDismiss = { adding = null })
    }
}

@Composable
private fun MemoryLine(line: MemoryItemDto, onOpenAt: (String, Long) -> Unit, onPin: () -> Unit, onDelete: () -> Unit) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    var menu by remember { mutableStateOf(false) }
    val call = line.source.type == "call" && line.source.id != null
    Row(
        Modifier.fillMaxWidth().heightIn(min = 40.dp).combinedClickable(
            onClick = { if (call) onOpenAt(line.source.id!!, line.source.atMs ?: 0) },
            onLongClick = { menu = true }
        ).padding(vertical = Space.s1),
        verticalAlignment = Alignment.CenterVertically
    ) {
        if (line.pinned) {
            Icon(
                Icons.Rounded.PushPin,
                contentDescription = "Pinned",
                tint = c.accentText,
                modifier = Modifier.padding(end = Space.s1)
            )
        }
        Column(Modifier.weight(1f)) {
            Text(line.text, style = type.bodyM, color = c.text)
            Text(
                listOfNotNull(
                    line.happenedOn,
                    if (call) {
                        "from a call · ${ClientFiles.clock(line.source.atMs ?: 0)}"
                    } else if (line.source.type ==
                        "user"
                    ) {
                        "added by you"
                    } else {
                        "from a file"
                    }
                )
                    .joinToString(" · "),
                style = type.caption,
                color = c.textMuted
            )
        }
        DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
            DropdownMenuItem(text = { Text(if (line.pinned) "Unpin" else "Pin") }, onClick = {
                menu = false
                onPin()
            })
            DropdownMenuItem(text = { Text("Remove", color = Brand.Danger) }, onClick = {
                menu = false
                onDelete()
            })
        }
    }
}

/** One call's notes: only the sections with content; tap a line to hear that moment. */
@Composable
fun NotesCard(note: CallNotesDto, onOpenAt: (String, Long) -> Unit, showTitle: Boolean = true) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    SectionCard {
        if (showTitle) {
            Text(note.session?.title ?: "Call", style = type.bodyL, color = c.text)
            note.session?.startedAt?.let { Text(formatWhen(it), style = type.caption, color = c.textMuted) }
        }
        when (note.status) {
            "pending" -> Row(verticalAlignment = Alignment.CenterVertically) {
                Spinner(14.dp)
                Text("  Writing the notes…", style = type.labelM, color = c.textMuted)
            }

            "failed", "skipped" -> Text(note.error ?: "No notes for this one.", style = type.labelM, color = c.textMuted)

            else -> if (note.sections.isEmpty()) {
                Text("Nothing to note in this one.", style = type.labelM, color = c.textMuted)
            }
        }
        note.sections.forEach { section ->
            Text(section.heading, style = type.labelL, color = c.textMuted, modifier = Modifier.padding(top = Space.s1))
            section.items.forEach { item ->
                Row(Modifier.fillMaxWidth().clickable { onOpenAt(note.sessionId, item.atMs) }.padding(vertical = 2.dp)) {
                    Text(
                        "• " + listOfNotNull(item.kind?.replaceFirstChar { it.uppercase() }?.let { "$it:" }, item.text).joinToString(" "),
                        style = type.bodyM,
                        color = c.text,
                        modifier = Modifier.weight(1f)
                    )
                    Text(
                        ClientFiles.clock(item.atMs) + if (item.verify) " · verify" else "",
                        style = type.caption,
                        color = if (item.verify) c.warning else c.accentText
                    )
                }
            }
        }
    }
}

@Composable
private fun FilesTab(vm: MainViewModel, client: ClientDto, w: WorkspaceDto, onChanged: () -> Unit) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var pasting by remember { mutableStateOf(false) }
    var removing by remember { mutableStateOf<String?>(null) }
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
        if (uris.isNotEmpty()) {
            busy = true
            scope.launch {
                val sent = vm.addClientFiles(client.id, uris)
                busy = false
                if (sent > 0) onChanged()
            }
        }
    }
    Column(Modifier.padding(horizontal = Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s2)) {
        Text(
            "PDF, Word, text and Markdown files and pasted instructions go into the brief and into Ask. They stay encrypted on your server.",
            style = type.labelM,
            color = c.textMuted
        )
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
            EchoButton(if (busy) "Adding…" else "Add files", {
                pick.launch(
                    arrayOf(
                        "application/pdf",
                        "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
                        "text/plain",
                        "text/markdown"
                    )
                )
            }, enabled = !busy, loading = busy)
            EchoButton("Paste instructions", { pasting = true }, kind = ButtonKind.Secondary)
        }
        if (w.files.isEmpty()) {
            Text(
                "No files yet.",
                style = type.bodyM,
                color = c.textMuted,
                modifier = Modifier.padding(vertical = Space.s3)
            )
        }
        w.files.forEach { file ->
            Row(
                Modifier.fillMaxWidth().combinedClickable(onClick = {}, onLongClick = { removing = file.id }).padding(vertical = Space.s2),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Icon(Icons.Rounded.Description, contentDescription = null, tint = c.textMuted)
                Column(Modifier.weight(1f).padding(start = Space.s3)) {
                    Text(file.name, style = type.bodyL, color = c.text, maxLines = 1)
                    Text(
                        when (file.status) {
                            "extracting" -> "Reading…"

                            "failed" -> file.error ?: "Could not be read"

                            else -> listOfNotNull(
                                if (file.instructions) "Instructions" else ImportCopy.size(file.sizeBytes),
                                file.pages?.let {
                                    "$it pages"
                                }
                            ).joinToString(" · ")
                        },
                        style = type.caption,
                        color = if (file.status == "failed") c.danger else c.textMuted
                    )
                }
                if (file.status == "extracting") Spinner(14.dp)
            }
        }
    }
    if (pasting) {
        InstructionsDialog(onSave = { name, text ->
            pasting = false
            scope.launch { if (vm.addInstructions(client.id, name, text) != null) onChanged() }
        }, onDismiss = { pasting = false })
    }
    removing?.let { id ->
        AlertDialog(
            onDismissRequest = { removing = null },
            text = { Text("Remove this file from ${client.name}? It is deleted from your server.") },
            confirmButton = {
                TextButton(onClick = {
                    removing = null
                    scope.launch { if (vm.deleteClientFile(client.id, id) != null) onChanged() }
                }) { Text("Remove", color = Brand.Danger) }
            },
            dismissButton = { TextButton(onClick = { removing = null }) { Text("Cancel") } }
        )
    }
}

@Composable
private fun AskTab(vm: MainViewModel, client: ClientDto, onOpenAt: (String, Long) -> Unit) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    val scope = rememberCoroutineScope()
    var question by rememberSaveable(client.id) { mutableStateOf("") }
    var language by rememberSaveable { mutableStateOf("en") }
    var asking by remember { mutableStateOf(false) }
    var answer by remember(client.id) { mutableStateOf<AskAnswerDto?>(null) }
    Column(Modifier.padding(horizontal = Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s3)) {
        OutlinedTextField(
            value = question,
            onValueChange = { question = it.take(500) },
            label = { Text("Ask about ${client.name}") },
            placeholder = { Text("When did we agree to launch?") },
            modifier = Modifier.fillMaxWidth(),
            minLines = 2
        )
        Row(verticalAlignment = Alignment.CenterVertically) {
            SegmentedControl(ClientFiles.LANGUAGES.map { it.second }, ClientFiles.LANGUAGES.indexOfFirst { it.first == language }, {
                language = ClientFiles.LANGUAGES[it].first
            }, Modifier.weight(1f))
        }
        EchoButton(if (asking) "Asking…" else "Ask", {
            asking = true
            scope.launch {
                answer = vm.ask(client.id, question.trim(), language)
                asking = false
            }
        }, Modifier.fillMaxWidth(), enabled = !asking && question.trim().length >= 3, loading = asking)
        answer?.let { a ->
            SectionCard {
                Text(a.answer, style = type.bodyL, color = c.text)
                if (a.citations.isNotEmpty()) {
                    Text("Sources", style = type.labelL, color = c.textMuted, modifier = Modifier.padding(top = Space.s2))
                    @OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s2), verticalArrangement = Arrangement.spacedBy(Space.s1)) {
                        a.citations.forEach { citation -> CitationChip(citation, onOpenAt) }
                    }
                }
            }
        }
        Text(
            "Answers use only ${client.name}'s brief, memory, files and transcripts. Nothing is used to train a model.",
            style = type.caption,
            color = c.textMuted
        )
    }
}

@Composable
private fun CitationChip(citation: CitationDto, onOpenAt: (String, Long) -> Unit) {
    val label = when (citation.type) {
        "call" -> "${citation.title} · ${ClientFiles.clock(citation.atMs ?: 0)}"
        else -> "${citation.title} · page ${citation.page ?: 1}"
    }
    com.echoledger.mobile.ui.design.EchoFilterChip(label, active = false, onClick = {
        if (citation.type == "call" && citation.sessionId != null) onOpenAt(citation.sessionId, citation.atMs ?: 0)
    })
}

@Composable
private fun BriefEditor(sections: BriefSections, onSave: (BriefSections) -> Unit, onDismiss: () -> Unit) {
    var goal by remember { mutableStateOf(sections.goal) }
    val texts = remember { sections.lists().map { (heading, lines) -> heading to mutableStateOf(lines.joinToString("\n")) } }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Edit the brief") },
        text = {
            Column(
                Modifier.heightIn(max = 480.dp).verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(Space.s2)
            ) {
                OutlinedTextField(goal, { goal = it.take(300) }, label = { Text("Goal") }, modifier = Modifier.fillMaxWidth())
                texts.forEach { (heading, state) ->
                    OutlinedTextField(state.value, {
                        state.value = it
                    }, label = { Text("$heading (one per line)") }, modifier = Modifier.fillMaxWidth(), minLines = 2)
                }
            }
        },
        confirmButton = {
            TextButton(onClick = {
                val lines = texts.associate { (heading, state) ->
                    heading to
                        state.value.lines().map { it.trim().removePrefix("• ").removePrefix("- ") }.filter { it.isNotEmpty() }
                }
                onSave(
                    BriefSections(
                        goal = goal.trim(),
                        scope = lines["Scope"].orEmpty(),
                        constraints = lines["Constraints"].orEmpty(),
                        deadlines = lines["Deadlines"].orEmpty(),
                        budget = lines["Budget"].orEmpty(),
                        people = lines["People"].orEmpty(),
                        glossary = lines["Glossary"].orEmpty()
                    )
                )
            }) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } }
    )
}

@Composable
private fun MemoryAdder(kind: String, onAdd: (String, String) -> Unit, onDismiss: () -> Unit) {
    var text by remember { mutableStateOf("") }
    var chosen by remember { mutableStateOf(kind) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Add to memory") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(Space.s2)) {
                @OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                    ClientFiles.KINDS.forEach { (k, heading) ->
                        com.echoledger.mobile.ui.design.EchoFilterChip(heading, active = chosen == k, onClick = {
                            chosen =
                                k
                        })
                    }
                }
                OutlinedTextField(text, { text = it.take(500) }, label = { Text("Line") }, modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = { TextButton(onClick = { onAdd(chosen, text.trim()) }, enabled = text.isNotBlank()) { Text("Add") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } }
    )
}

@Composable
private fun InstructionsDialog(onSave: (String, String) -> Unit, onDismiss: () -> Unit) {
    var name by remember { mutableStateOf("Instructions") }
    var text by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Paste instructions") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(Space.s2)) {
                OutlinedTextField(name, {
                    name = it.take(200)
                }, label = { Text("Name") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                OutlinedTextField(text, {
                    text = it.take(100_000)
                }, label = { Text("Text") }, modifier = Modifier.fillMaxWidth(), minLines = 4)
            }
        },
        confirmButton = { TextButton(onClick = { onSave(name.trim(), text) }, enabled = text.isNotBlank()) { Text("Save") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } }
    )
}

private fun copyText(context: Context, label: String, text: String) {
    context.getSystemService(ClipboardManager::class.java)?.setPrimaryClip(ClipData.newPlainText(label, text))
}

/**
 * A recording's call notes on its page (since 1.4): shown when written; otherwise "Write call notes" (English or
 * Roman Urdu). Tapping a line plays that moment ([onSeek]).
 */
@Composable
fun CallNotesSection(vm: MainViewModel, sessionId: String, onSeek: (Long) -> Unit) {
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    val scope = rememberCoroutineScope()
    var notes by remember(sessionId) { mutableStateOf<CallNotesDto?>(null) }
    var loaded by remember(sessionId) { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    LaunchedEffect(sessionId, reload) {
        notes = vm.sessionNotes(sessionId)
        loaded = true
    }
    LaunchedEffect(notes?.status) {
        if (notes?.status == "pending") {
            delay(5_000)
            reload++
        }
    }
    if (!loaded) return
    Column(verticalArrangement = Arrangement.spacedBy(Space.s2)) {
        Text("Call notes", style = type.titleM, color = c.text)
        notes?.let { NotesCard(it, onOpenAt = { _, ms -> onSeek(ms) }, showTitle = false) }
        if (notes == null || notes?.status in setOf("failed", "skipped")) {
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                ClientFiles.LANGUAGES.forEach { (code, label) ->
                    EchoButton(if (code == "en") "Write call notes" else "In $label", {
                        scope.launch { vm.makeNotes(sessionId, code)?.let { notes = it } }
                    }, kind = if (code == "en") ButtonKind.Secondary else ButtonKind.Text)
                }
            }
        }
    }
}
