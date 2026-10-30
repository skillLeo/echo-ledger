package com.echoledger.mobile.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.DriveFileMove
import androidx.compose.material.icons.automirrored.rounded.Label
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.MoreVert
import androidx.compose.material.icons.rounded.SelectAll
import androidx.compose.material3.Checkbox
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.ModalBottomSheet
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
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.core.Bookmarks
import com.echoledger.mobile.core.LibraryFilters
import com.echoledger.mobile.core.LibraryFilters.Companion.toggle
import com.echoledger.mobile.core.Organise
import com.echoledger.mobile.core.SmartView
import com.echoledger.mobile.core.SourceDevice
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.ui.design.BarIcon
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.EchoFilterChip

/** The Library's filter facets (since v1.3): one chip each; a chip opens a sheet of choices with Clear and Apply. */
enum class Facet(val label: String) {
    SOURCE("Source"),
    DATE("Date"),
    CLIENT("Client"),
    LANGUAGE("Language"),
    STATUS("Status"),
    LABELS("Labels")
}

private val DATE_VIEWS = listOf(SmartView.TODAY, SmartView.THIS_WEEK)
private val STATUS_VIEWS = listOf(SmartView.WAITING, SmartView.FAILED, SmartView.PINNED, SmartView.BOOKMARKED)

/** How many choices of [facet] are ticked in [f]. */
fun facetCount(f: LibraryFilters, facet: Facet): Int = when (facet) {
    Facet.SOURCE -> f.devices.size
    Facet.DATE -> f.views.count { it in DATE_VIEWS }
    Facet.CLIENT -> f.folders.size
    Facet.LANGUAGE -> f.languages.size
    Facet.STATUS -> f.views.count { it in STATUS_VIEWS } + f.tags.size
    Facet.LABELS -> f.labels.size
}

/** One row of filter chips under the search pill (Labels only when there are labels). */
@Composable
fun FilterChips(
    filters: LibraryFilters,
    onFilters: (LibraryFilters) -> Unit,
    items: List<LibraryItem>,
    folders: List<ClientDto>,
    labels: List<String>,
    tags: List<String>,
    counts: Map<SmartView, Int>,
    modifier: Modifier = Modifier
) {
    var open by remember { mutableStateOf<Facet?>(null) }
    val facets = Facet.entries.filter { it != Facet.LABELS || labels.isNotEmpty() }
    Row(
        modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = Space.screen),
        horizontalArrangement = Arrangement.spacedBy(Space.s2)
    ) {
        facets.forEach { facet ->
            val n = facetCount(filters, facet)
            EchoFilterChip(facet.label, active = n > 0, onClick = { open = facet }, count = n)
        }
    }
    open?.let { facet ->
        FacetSheet(facet, filters, items, folders, labels, tags, counts, onApply = {
            onFilters(it)
            open = null
        }, onDismiss = { open = null })
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun FacetSheet(
    facet: Facet,
    filters: LibraryFilters,
    items: List<LibraryItem>,
    folders: List<ClientDto>,
    labels: List<String>,
    tags: List<String>,
    counts: Map<SmartView, Int>,
    onApply: (LibraryFilters) -> Unit,
    onDismiss: () -> Unit
) {
    var draft by remember { mutableStateOf(filters) }
    fun viewRow(v: SmartView) = Triple(counts[v]?.let { "${v.label} ($it)" } ?: v.label, v in draft.views) {
        draft =
            draft.copy(views = draft.views.toggle(v))
    }
    val rows: List<Triple<String, Boolean, () -> Unit>> = when (facet) {
        Facet.SOURCE -> SourceDevice.entries.map { d ->
            Triple(d.label, d in draft.devices) {
                draft =
                    draft.copy(devices = draft.devices.toggle(d))
            }
        }

        Facet.DATE -> DATE_VIEWS.map { viewRow(it) }

        Facet.CLIENT -> (listOf("" to "No client") + folders.map { it.id to it.name }).map { (id, name) ->
            Triple(name, id in draft.folders) { draft = draft.copy(folders = draft.folders.toggle(id)) }
        }

        Facet.LANGUAGE -> items.mapNotNull { it.languageChip?.takeIf { l -> l.isNotBlank() } }.distinct().sorted().map { l ->
            Triple(l, l in draft.languages) { draft = draft.copy(languages = draft.languages.toggle(l)) }
        }

        Facet.STATUS -> STATUS_VIEWS.map { viewRow(it) } + (if (tags.isEmpty()) emptyList() else listOf(Bookmarks.ANY) + tags).map { t ->
            Triple(
                if (t ==
                    Bookmarks.ANY
                ) {
                    "Any bookmark"
                } else {
                    "Bookmark: $t"
                },
                t in draft.tags
            ) { draft = draft.copy(tags = draft.tags.toggle(t)) }
        }

        Facet.LABELS -> labels.map { l -> Triple(l, l in draft.labels) { draft = draft.copy(labels = draft.labels.toggle(l)) } }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Brand.Surface) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding()) {
            Text(
                facet.label,
                style = LocalEchoType.current.titleS,
                color = Brand.Text,
                modifier = Modifier.padding(horizontal = Space.screen, vertical = Space.s2)
            )
            Column(Modifier.weight(1f, fill = false).verticalScroll(rememberScrollState())) {
                if (rows.isEmpty()) Text("Nothing to choose yet.", color = Brand.Muted, modifier = Modifier.padding(Space.screen))
                rows.forEach { (text, checked, toggle) ->
                    Row(
                        Modifier.fillMaxWidth().heightIn(
                            min = 48.dp
                        ).clickable(role = Role.Checkbox, onClick = toggle).padding(horizontal = Space.s2),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Checkbox(checked = checked, onCheckedChange = null, modifier = Modifier.padding(horizontal = Space.s2))
                        Text(text, style = LocalEchoType.current.bodyM, color = Brand.Text)
                    }
                }
            }
            Row(Modifier.fillMaxWidth().padding(Space.screen), horizontalArrangement = Arrangement.spacedBy(Space.s3)) {
                EchoButton("Clear", { draft = draft.clear(facet) }, Modifier.weight(1f), kind = ButtonKind.Secondary)
                EchoButton("Apply", { onApply(draft) }, Modifier.weight(1f))
            }
        }
    }
}

private fun LibraryFilters.clear(facet: Facet): LibraryFilters = when (facet) {
    Facet.SOURCE -> copy(devices = emptySet())
    Facet.DATE -> copy(views = views - DATE_VIEWS.toSet())
    Facet.CLIENT -> copy(folders = emptySet())
    Facet.LANGUAGE -> copy(languages = emptySet())
    Facet.STATUS -> copy(views = views - STATUS_VIEWS.toSet(), tags = emptySet())
    Facet.LABELS -> copy(labels = emptySet())
}

/**
 * The actions of the selection bar: Select all · Move · Label · Delete (asks once more; restorable for 48 hours) ·
 * More (Pin, Unpin, Keep forever, Stop keeping, Export as …).
 */
@Composable
fun RowScope.SelectionActions(
    count: Int,
    folders: List<ClientDto>,
    labels: List<String>,
    onSelectAll: () -> Unit,
    onMove: (String?) -> Unit,
    onCreateClient: (String) -> Unit,
    onLabel: (String, Boolean) -> Unit,
    onPin: (Boolean) -> Unit,
    onKeep: (Boolean) -> Unit,
    onExport: (String) -> Unit,
    onDelete: () -> Unit
) {
    var moveOpen by remember { mutableStateOf(false) }
    var labelOpen by remember { mutableStateOf(false) }
    var deleteOpen by remember { mutableStateOf(false) }
    var moreOpen by remember { mutableStateOf(false) }
    var newClient by remember { mutableStateOf<String?>(null) }
    var label by remember { mutableStateOf("") }
    BarIcon(Icons.Rounded.SelectAll, "Select all", onSelectAll)
    Box {
        BarIcon(Icons.AutoMirrored.Rounded.DriveFileMove, "Move to", { moveOpen = true })
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
                DropdownMenuItem(text = { Text("New client…", color = Brand.AccentText) }, onClick = { newClient = "" })
            } else {
                Row(Modifier.padding(horizontal = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                    OutlinedTextField(newClient.orEmpty(), {
                        newClient = it.take(120)
                    }, placeholder = { Text("Client name") }, singleLine = true, modifier = Modifier.width(180.dp))
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
        BarIcon(Icons.AutoMirrored.Rounded.Label, "Label", { labelOpen = true })
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
        BarIcon(Icons.Rounded.Delete, "Delete", { deleteOpen = true })
        DropdownMenu(expanded = deleteOpen, onDismissRequest = { deleteOpen = false }) {
            DropdownMenuItem(text = { Text("Delete $count (restorable for 48 hours)", color = Brand.Danger) }, onClick = {
                deleteOpen = false
                onDelete()
            })
        }
    }
    Box {
        BarIcon(Icons.Rounded.MoreVert, "More actions", { moreOpen = true })
        DropdownMenu(expanded = moreOpen, onDismissRequest = { moreOpen = false }) {
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
        }
    }
    Spacer(Modifier.width(Space.s1))
}
