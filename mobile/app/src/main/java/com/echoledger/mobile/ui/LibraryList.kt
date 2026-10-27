package com.echoledger.mobile.ui

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.DriveFileMove
import androidx.compose.material.icons.automirrored.outlined.Label
import androidx.compose.material.icons.automirrored.outlined.Sort
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.FilterList
import androidx.compose.material.icons.outlined.MoreVert
import androidx.compose.material3.Badge
import androidx.compose.material3.Checkbox
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TriStateCheckbox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.state.ToggleableState
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.Bookmarks
import com.echoledger.mobile.core.ItemStatus
import com.echoledger.mobile.core.LibraryFilters
import com.echoledger.mobile.core.LibraryFilters.Companion.toggle
import com.echoledger.mobile.core.LibrarySort
import com.echoledger.mobile.core.Organise
import com.echoledger.mobile.core.SmartView
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.SourceDevice
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem

/**
 * One line above the list (like Google Files): the select-all box, how many recordings are shown, and the Filters and
 * Sort menus. Filters are checkboxes in groups; ticked boxes add up inside a group and groups narrow each other.
 */
@Composable
fun LibraryToolbar(
    shown: Int,
    picked: Int,
    filters: LibraryFilters,
    onFilters: (LibraryFilters) -> Unit,
    sort: LibrarySort,
    onSort: (LibrarySort) -> Unit,
    counts: Map<SmartView, Int>,
    folders: List<ClientDto>,
    labels: List<String>,
    tags: List<String>,
    onToggleAll: () -> Unit
) {
    var filterOpen by remember { mutableStateOf(false) }
    var sortOpen by remember { mutableStateOf(false) }
    val allState = when {
        picked == 0 -> ToggleableState.Off
        picked >= shown && shown > 0 -> ToggleableState.On
        else -> ToggleableState.Indeterminate
    }
    Row(Modifier.fillMaxWidth().heightIn(min = 44.dp), verticalAlignment = Alignment.CenterVertically) {
        TriStateCheckbox(
            state = allState,
            onClick = onToggleAll,
            enabled = shown > 0,
            modifier = Modifier.semantics { contentDescription = if (allState == ToggleableState.On) "Clear selection" else "Select all" }
        )
        Text(
            if (picked > 0) "$picked selected" else "$shown recording${if (shown == 1) "" else "s"}",
            color = Brand.Muted,
            fontSize = 13.sp,
            modifier = Modifier.weight(1f)
        )
        Box {
            TextButton(onClick = { filterOpen = true }, modifier = Modifier.semantics { contentDescription = "Filters" }) {
                Icon(Icons.Outlined.FilterList, contentDescription = null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(4.dp))
                Text("Filters")
                if (filters.count > 0) {
                    Spacer(Modifier.width(4.dp))
                    Badge { Text("${filters.count}") }
                }
            }
            DropdownMenu(expanded = filterOpen, onDismissRequest = { filterOpen = false }) {
                FilterGroup("Show")
                SmartView.entries.filter { it != SmartView.ALL }.forEach { view ->
                    val n = counts[view] ?: 0
                    CheckItem(if (n > 0) "${view.label} ($n)" else view.label, view in filters.views) {
                        onFilters(filters.copy(views = filters.views.toggle(view)))
                    }
                }
                FilterGroup("Recorded on")
                SourceDevice.entries.forEach { d ->
                    CheckItem(d.label, d in filters.devices) { onFilters(filters.copy(devices = filters.devices.toggle(d))) }
                }
                if (folders.isNotEmpty()) {
                    FilterGroup("Client")
                    CheckItem("No client", "" in filters.folders) { onFilters(filters.copy(folders = filters.folders.toggle(""))) }
                    folders.forEach { c ->
                        CheckItem(c.name, c.id in filters.folders) { onFilters(filters.copy(folders = filters.folders.toggle(c.id))) }
                    }
                }
                if (labels.isNotEmpty()) {
                    FilterGroup("Labels")
                    labels.forEach { l -> CheckItem(l, l in filters.labels) { onFilters(filters.copy(labels = filters.labels.toggle(l))) } }
                }
                if (tags.isNotEmpty()) {
                    FilterGroup("Bookmark tags")
                    CheckItem("Any bookmark", Bookmarks.ANY in filters.tags) {
                        onFilters(filters.copy(tags = filters.tags.toggle(Bookmarks.ANY)))
                    }
                    tags.forEach { t -> CheckItem(t, t in filters.tags) { onFilters(filters.copy(tags = filters.tags.toggle(t))) } }
                }
                HorizontalDivider()
                DropdownMenuItem(text = { Text("Clear all", color = Brand.Violet) }, enabled = filters.count > 0, onClick = {
                    onFilters(LibraryFilters())
                    filterOpen = false
                })
            }
        }
        Box {
            IconButton(onClick = { sortOpen = true }, modifier = Modifier.semantics { contentDescription = "Sort: ${sort.label}" }) {
                Icon(Icons.AutoMirrored.Outlined.Sort, contentDescription = null, tint = Brand.Muted)
            }
            DropdownMenu(expanded = sortOpen, onDismissRequest = { sortOpen = false }) {
                LibrarySort.entries.forEach { s ->
                    DropdownMenuItem(
                        text = { Text(s.label, fontWeight = if (s == sort) FontWeight.SemiBold else FontWeight.Normal) },
                        onClick = {
                            onSort(s)
                            sortOpen = false
                        }
                    )
                }
            }
        }
    }
}

@Composable
private fun FilterGroup(title: String) {
    Text(title, color = Brand.Muted, fontSize = 12.sp, modifier = Modifier.padding(start = 16.dp, top = 10.dp, bottom = 2.dp))
}

@Composable
private fun CheckItem(text: String, checked: Boolean, onToggle: () -> Unit) {
    DropdownMenuItem(
        text = { Text(text, maxLines = 1, overflow = TextOverflow.Ellipsis) },
        leadingIcon = { Checkbox(checked = checked, onCheckedChange = null) },
        onClick = onToggle,
        modifier = Modifier.heightIn(min = 40.dp).semantics { stateDescription = if (checked) "Checked" else "Not checked" }
    )
}

/**
 * A compact row (about 56 dp): the selection box, the app icon, the title and one line of facts, then icons only — the
 * device it was recorded on and a status dot (red failed, grey no audio, amber processing, green ready).
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun CompactLibraryRow(item: LibraryItem, checked: Boolean, selecting: Boolean, onCheck: () -> Unit, onOpen: () -> Unit) {
    val status = ItemStatus.of(item)
    val device = SourceDevice.from(item.sourceDevice)
    Column {
        Row(
            Modifier
                .fillMaxWidth()
                .heightIn(min = 56.dp)
                .combinedClickable(onClick = { if (selecting) onCheck() else onOpen() }, onLongClick = onCheck, onLongClickLabel = "Select")
                .semantics {
                    selected = checked
                    stateDescription = status.label
                },
            verticalAlignment = Alignment.CenterVertically
        ) {
            Checkbox(
                checked = checked,
                onCheckedChange = { onCheck() },
                modifier = Modifier.semantics { contentDescription = "Select ${item.title ?: "recording"}" }
            )
            SourceAvatar(device, SourceApp.from(item.sourceApp), size = 34)
            Column(Modifier.weight(1f).padding(start = 10.dp, end = 6.dp)) {
                Text(
                    (if (item.pinned) "★ " else "") + (item.title ?: "Untitled"),
                    fontWeight = FontWeight.SemiBold,
                    fontSize = 14.sp,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
                Text(
                    listOfNotNull(
                        formatWhen(item.startedAt),
                        formatDuration(item.durationMs).ifEmpty { null },
                        item.client?.name,
                        Bookmarks.countLabel(item.markerCount)
                    ).joinToString(" · "),
                    color = Brand.Muted,
                    fontSize = 12.sp,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
            }
            item.languageChip?.takeIf { it != "Auto" }?.let {
                Text(it, color = Brand.Muted, fontSize = 10.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(end = 6.dp))
            }
            Icon(
                device.icon(),
                contentDescription = item.sourceDeviceLabel ?: device.label,
                tint = Brand.Faint,
                modifier = Modifier.size(16.dp)
            )
            Spacer(Modifier.width(8.dp))
            Box(
                Modifier.padding(end = 8.dp).size(9.dp).clip(CircleShape).background(statusColour(status))
                    .semantics { contentDescription = status.label }
            )
        }
        HorizontalDivider(color = Brand.Border.copy(alpha = 0.6f))
    }
}

@Composable
fun statusColour(status: ItemStatus): Color = when (status) {
    ItemStatus.READY -> Brand.Success
    ItemStatus.PROCESSING -> Brand.Warning
    ItemStatus.FAILED -> Brand.Danger
    ItemStatus.NO_AUDIO -> Brand.Faint
}

/**
 * The bulk actions in one line at the bottom while recordings are ticked: Move to (clients, new client), Label (type
 * or pick, add or remove) and More (pin, keep, export in every format, delete). Delete asks once more.
 */
@Composable
fun CompactBulkBar(
    count: Int,
    folders: List<ClientDto>,
    labels: List<String>,
    onMove: (String?) -> Unit,
    onCreateClient: (String) -> Unit,
    onLabel: (String, Boolean) -> Unit,
    onPin: (Boolean) -> Unit,
    onKeep: (Boolean) -> Unit,
    onExport: (String) -> Unit,
    onDelete: () -> Unit,
    onDone: () -> Unit
) {
    var moveOpen by remember { mutableStateOf(false) }
    var labelOpen by remember { mutableStateOf(false) }
    var moreOpen by remember { mutableStateOf(false) }
    var newClient by remember { mutableStateOf<String?>(null) }
    var label by remember { mutableStateOf("") }
    var confirmDelete by remember { mutableStateOf(false) }
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(Radius.lg)).background(Brand.SurfaceAlt).padding(horizontal = 4.dp)
            .semantics { contentDescription = "Selected recordings" },
        verticalAlignment = Alignment.CenterVertically
    ) {
        IconButton(onClick = onDone) { Icon(Icons.Outlined.Close, contentDescription = "Done") }
        Text("$count", fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
        Box {
            IconButton(onClick = { moveOpen = true }) { Icon(Icons.AutoMirrored.Outlined.DriveFileMove, contentDescription = "Move to") }
            DropdownMenu(expanded = moveOpen, onDismissRequest = {
                moveOpen = false
                newClient = null
            }) {
                DropdownMenuItem(text = { Text("No client") }, onClick = {
                    moveOpen = false
                    onMove(null)
                })
                folders.forEach { c ->
                    DropdownMenuItem(text = { Text("${Organise.badge(c.name, c.avatar)} · ${c.name}", maxLines = 1) }, onClick = {
                        moveOpen = false
                        onMove(c.id)
                    })
                }
                HorizontalDivider()
                if (newClient == null) {
                    DropdownMenuItem(text = { Text("New client…", color = Brand.Violet) }, onClick = { newClient = "" })
                } else {
                    Row(Modifier.padding(horizontal = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                        OutlinedTextField(
                            newClient.orEmpty(),
                            { newClient = it.take(120) },
                            placeholder = { Text("Client name") },
                            singleLine = true,
                            modifier = Modifier.width(180.dp)
                        )
                        TextButton(enabled = !newClient.isNullOrBlank(), onClick = {
                            onCreateClient(newClient!!.trim())
                            newClient = null
                            moveOpen = false
                        }) { Text("Create") }
                    }
                }
            }
        }
        Box {
            IconButton(onClick = { labelOpen = true }) { Icon(Icons.AutoMirrored.Outlined.Label, contentDescription = "Label") }
            DropdownMenu(expanded = labelOpen, onDismissRequest = { labelOpen = false }) {
                Row(Modifier.padding(horizontal = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                    OutlinedTextField(label, {
                        label = it.take(Organise.MAX_LABEL_CHARS)
                    }, placeholder = { Text("Label") }, singleLine = true, modifier = Modifier.width(150.dp))
                    TextButton(enabled = Organise.cleanLabel(label) != null, onClick = {
                        onLabel(label, true)
                        label = ""
                        labelOpen = false
                    }) { Text("Add") }
                    TextButton(enabled = Organise.cleanLabel(label) != null, onClick = {
                        onLabel(label, false)
                        label = ""
                        labelOpen = false
                    }) { Text("Remove") }
                }
                labels.filter { label.isBlank() || it.contains(label.trim(), ignoreCase = true) }.take(8).forEach { l ->
                    DropdownMenuItem(text = { Text("+ $l") }, onClick = {
                        onLabel(l, true)
                        labelOpen = false
                    })
                }
            }
        }
        Box {
            IconButton(onClick = { moreOpen = true }) { Icon(Icons.Outlined.MoreVert, contentDescription = "More actions") }
            DropdownMenu(expanded = moreOpen, onDismissRequest = {
                moreOpen = false
                confirmDelete = false
            }) {
                fun pick(action: () -> Unit) {
                    moreOpen = false
                    action()
                }
                DropdownMenuItem(text = { Text("Pin") }, onClick = { pick { onPin(true) } })
                DropdownMenuItem(text = { Text("Unpin") }, onClick = { pick { onPin(false) } })
                DropdownMenuItem(text = { Text("Keep forever") }, onClick = { pick { onKeep(true) } })
                DropdownMenuItem(text = { Text("Stop keeping") }, onClick = { pick { onKeep(false) } })
                HorizontalDivider()
                Organise.EXPORT_FORMATS.forEach { (value, text) ->
                    DropdownMenuItem(text = { Text("Export as $text") }, onClick = { pick { onExport(value) } })
                }
                HorizontalDivider()
                if (confirmDelete) {
                    DropdownMenuItem(text = { Text("Delete $count (restorable for 48 hours)", color = Brand.Danger) }, onClick = {
                        confirmDelete = false
                        pick(onDelete)
                    })
                } else {
                    DropdownMenuItem(text = { Text("Delete", color = Brand.Danger) }, onClick = { confirmDelete = true })
                }
            }
        }
    }
}
