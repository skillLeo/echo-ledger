package com.echoledger.mobile.ui

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material3.FilterChip
import androidx.compose.material3.InputChip
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.Organise
import com.echoledger.mobile.core.SmartView
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem

/** The smart views with how many recordings each holds (B15). */
@Composable
fun SmartViewChips(counts: Map<SmartView, Int>, selected: SmartView, onSelect: (SmartView) -> Unit) {
    Row(Modifier.horizontalScroll(rememberScrollState()).padding(bottom = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        SmartView.entries.forEach { view ->
            val count = counts[view] ?: 0
            FilterChip(
                selected = view == selected,
                onClick = { onSelect(view) },
                label = { Text(if (view != SmartView.ALL && count > 0) "${view.label} $count" else view.label) }
            )
        }
    }
}

/** Client-folder and label filters: `null` = any, `""` folder = no client. */
@Composable
fun FolderLabelFilters(
    folders: List<ClientDto>,
    labels: List<String>,
    folder: String?,
    label: String?,
    onFolder: (String?) -> Unit,
    onLabel: (String?) -> Unit
) {
    if (folders.isEmpty() && labels.isEmpty() && folder == null && label == null) return
    Row(Modifier.horizontalScroll(rememberScrollState()).padding(bottom = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        if (folders.isNotEmpty() || folder != null) {
            FilterChip(selected = folder == "", onClick = { onFolder(if (folder == "") null else "") }, label = { Text("No client") })
            folders.forEach { c ->
                FilterChip(selected = folder == c.id, onClick = {
                    onFolder(
                        if (folder ==
                            c.id
                        ) {
                            null
                        } else {
                            c.id
                        }
                    )
                }, label = { Text("${Organise.badge(c.name, c.avatar)} · ${c.name}") })
            }
        }
        labels.forEach { l ->
            FilterChip(selected = label == l, onClick = {
                onLabel(
                    if (label ==
                        l
                    ) {
                        null
                    } else {
                        l
                    }
                )
            }, label = { Text("# $l") })
        }
    }
}

/** Client folder chips with "No client" and a field to create a new folder. */
@Composable
private fun FolderChooser(
    title: String,
    folders: List<ClientDto>,
    selected: String?,
    onPick: (String?) -> Unit,
    onCreate: (String) -> Unit
) {
    var creating by remember { mutableStateOf(false) }
    var name by remember { mutableStateOf("") }
    Text(title, color = Brand.Muted, fontSize = 12.sp)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        FilterChip(selected = selected == null, onClick = { onPick(null) }, label = { Text("No client") })
        folders.forEach { c ->
            FilterChip(selected = selected == c.id, onClick = {
                onPick(c.id)
            }, label = { Text("${Organise.badge(c.name, c.avatar)} · ${c.name}") })
        }
        FilterChip(selected = creating, onClick = { creating = !creating }, label = { Text("New client…") })
    }
    if (creating) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            OutlinedTextField(name, {
                name = it.take(120)
            }, label = { Text("Client name") }, singleLine = true, modifier = Modifier.weight(1f))
            TextButton(enabled = name.isNotBlank(), onClick = {
                onCreate(name.trim())
                name = ""
                creating = false
            }) { Text("Create") }
        }
    }
}

/** Pin, client folder and labels of the open recording. */
@Composable
fun OrganiseSection(
    item: LibraryItem,
    folders: List<ClientDto>,
    knownLabels: List<String>,
    onPin: (Boolean) -> Unit,
    onMove: (String?) -> Unit,
    onCreateClient: (String) -> Unit,
    onLabels: (List<String>) -> Unit
) {
    var label by remember { mutableStateOf("") }
    SectionCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Organise", fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            TextButton(
                onClick = { onPin(!item.pinned) },
                modifier = Modifier.semantics {
                    contentDescription =
                        if (item.pinned) "Unpin" else "Pin"
                }
            ) {
                Text(if (item.pinned) "★ Pinned" else "☆ Pin", color = if (item.pinned) Brand.Warning else Brand.Muted)
            }
        }
        FolderChooser("Folder", folders, item.client?.id, onMove, onCreateClient)
        Text("Labels", color = Brand.Muted, fontSize = 12.sp)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            item.labels.forEach { l ->
                InputChip(
                    selected = false,
                    onClick = { onLabels(item.labels - l) },
                    label = { Text("$l  ×") },
                    modifier = Modifier.semantics { contentDescription = "Remove label $l" }
                )
            }
        }
        val suggestions = knownLabels.filter { k ->
            item.labels.none { it.equals(k, true) } &&
                (label.isBlank() || k.contains(label.trim(), true))
        }.take(6)
        if (suggestions.isNotEmpty()) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                suggestions.forEach { s ->
                    FilterChip(selected = false, onClick = { Organise.withLabel(item.labels, s)?.let(onLabels) }, label = { Text("+ $s") })
                }
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            OutlinedTextField(label, {
                label = it.take(Organise.MAX_LABEL_CHARS)
            }, label = { Text("Add a label") }, singleLine = true, modifier = Modifier.weight(1f))
            TextButton(enabled = Organise.withLabel(item.labels, label) != null, onClick = {
                Organise.withLabel(item.labels, label)?.let(onLabels)
                label = ""
            }) { Text("Add") }
        }
    }
}

/** Actions for the selected recordings. Delete asks once more before it runs. */
@Composable
fun BulkPanel(
    count: Int,
    folders: List<ClientDto>,
    onMove: (String?) -> Unit,
    onCreateClient: (String) -> Unit,
    onLabel: (String, Boolean) -> Unit,
    onPin: (Boolean) -> Unit,
    onExport: (String) -> Unit,
    onDelete: () -> Unit,
    onDone: () -> Unit
) {
    var label by remember { mutableStateOf("") }
    var format by remember { mutableStateOf("docx") }
    var confirmDelete by remember { mutableStateOf(false) }
    SectionCard(Modifier.fillMaxWidth().semantics { contentDescription = "Selected recordings" }) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                if (count ==
                    0
                ) {
                    "Select recordings"
                } else {
                    "$count selected"
                },
                fontWeight = FontWeight.SemiBold,
                modifier = Modifier.weight(1f)
            )
            TextButton(onClick = onDone) { Text("Done") }
        }
        if (count == 0) {
            Text("Tap recordings to select them.", color = Brand.Muted, fontSize = 12.sp)
            return@SectionCard
        }
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            FolderChooser("Move to", folders, selected = "", onPick = onMove, onCreate = onCreateClient)
            Row(verticalAlignment = Alignment.CenterVertically) {
                OutlinedTextField(label, {
                    label = it.take(Organise.MAX_LABEL_CHARS)
                }, label = { Text("Label") }, singleLine = true, modifier = Modifier.weight(1f))
                TextButton(enabled = Organise.cleanLabel(label) != null, onClick = { onLabel(label, true) }) { Text("Add label") }
                TextButton(enabled = Organise.cleanLabel(label) != null, onClick = { onLabel(label, false) }) { Text("Remove") }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                TextButton(onClick = { onPin(true) }) { Text("Pin") }
                TextButton(onClick = { onPin(false) }) { Text("Unpin") }
            }
            Text("Export transcripts", color = Brand.Muted, fontSize = 12.sp)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Organise.EXPORT_FORMATS.forEach { (value, text) ->
                    FilterChip(selected = format == value, onClick = { format = value }, label = { Text(text) })
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                TextButton(onClick = { onExport(format) }) { Text("Export as zip") }
                if (confirmDelete) {
                    TextButton(onClick = {
                        confirmDelete = false
                        onDelete()
                    }) { Text("Delete $count on every device", color = Brand.Danger) }
                } else {
                    TextButton(onClick = { confirmDelete = true }) { Text("Delete", color = Brand.Danger) }
                }
            }
        }
    }
}
