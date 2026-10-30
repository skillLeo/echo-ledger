package com.echoledger.mobile.ui

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material.icons.rounded.PushPin
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
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
import kotlinx.coroutines.launch

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
    onLabels: (List<String>) -> Unit,
    onKeep: (Boolean) -> Unit = {}
) {
    var label by remember { mutableStateOf("") }
    SectionCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Organise", fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            TextButton(
                onClick = { onKeep(!item.keep) },
                modifier = Modifier.semantics {
                    contentDescription =
                        if (item.keep) "Stop keeping" else "Keep forever"
                }
            ) {
                Text(if (item.keep) "Kept forever" else "Keep forever", color = if (item.keep) Brand.Success else Brand.Muted)
            }
            TextButton(
                onClick = { onPin(!item.pinned) },
                modifier = Modifier.semantics {
                    contentDescription =
                        if (item.pinned) "Unpin" else "Pin"
                }
            ) {
                Icon(
                    androidx.compose.material.icons.Icons.Rounded.PushPin,
                    contentDescription = null,
                    tint = if (item.pinned) Brand.Warning else Brand.Muted,
                    modifier = Modifier.size(16.dp)
                )
                Spacer(Modifier.width(4.dp))
                Text(if (item.pinned) "Pinned" else "Pin", color = if (item.pinned) Brand.Warning else Brand.Muted)
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

/** "erased in 5 hours" until the 48-hour bin ends. */
fun eraseIn(eraseAt: String?, now: java.time.Instant = java.time.Instant.now()): String {
    val at = eraseAt?.let { runCatching { java.time.OffsetDateTime.parse(it).toInstant() }.getOrNull() } ?: return "erased soon"
    val hours = java.time.Duration.between(now, at).toHours()
    return if (hours >= 1) "erased in $hours hour${if (hours == 1L) "" else "s"}" else "erased soon"
}

/** Recently deleted (B17): the 48-hour undo bin with Restore, and Delete now after a second tap. */
@Composable
fun BinSection(
    load: suspend () -> List<com.echoledger.mobile.data.BinItemDto>,
    restore: suspend (String) -> Boolean,
    erase: suspend (String) -> Boolean,
    onClose: () -> Unit
) {
    var items by remember { mutableStateOf<List<com.echoledger.mobile.data.BinItemDto>?>(null) }
    var confirming by remember { mutableStateOf<String?>(null) }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    androidx.compose.runtime.LaunchedEffect(Unit) { items = load() }
    SectionCard(Modifier.fillMaxWidth().semantics { contentDescription = "Recently deleted" }) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Recently deleted", fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            TextButton(onClick = onClose) { Text("Close") }
        }
        Text(
            "Deleted recordings stay here for 48 hours, then their audio and transcripts are erased.",
            color = Brand.Muted,
            fontSize = 12.sp
        )
        val list = items
        when {
            list == null -> Text("Loading…", color = Brand.Muted, fontSize = 12.sp)

            list.isEmpty() -> Text("Nothing deleted in the last 48 hours.", color = Brand.Muted, fontSize = 12.sp)

            else -> list.forEach { b ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(b.title)
                        Text(listOfNotNull(b.client?.name, eraseIn(b.eraseAt)).joinToString(" · "), color = Brand.Muted, fontSize = 12.sp)
                    }
                    TextButton(onClick = { scope.launch { if (restore(b.id)) items = load() } }) { Text("Restore") }
                    if (confirming == b.id) {
                        TextButton(onClick = {
                            confirming = null
                            scope.launch { if (erase(b.id)) items = load() }
                        }) { Text("Erase now", color = Brand.Danger) }
                    } else {
                        TextButton(onClick = { confirming = b.id }) { Text("Delete now", color = Brand.Danger) }
                    }
                }
            }
        }
    }
}
