package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.ui.design.OutlinedTextField
import com.echoledger.mobile.ui.design.TextButton

/**
 * Whether [item]'s title is one the person chose (2.0.0-k title_source "own"), and so theirs to edit in Rename. An older
 * server, which doesn't say, counts as theirs: the box keeps the title, as before.
 */
fun ownTitle(item: LibraryItem): Boolean = item.titleSource == null || item.titleSource == "own"

/**
 * What Rename's box holds and when Save does something (U19, 2.0.0-k, as on the desktop). A title the person chose
 * ([own]) is in the box, and emptying it gives back EchoLedger's; any other ("Call with …", an automatic title, the
 * app's name) starts the box empty with that title as its placeholder, and leaving it empty saves nothing.
 */
class RenameBox(val current: String, val own: Boolean = true) {
    var text by mutableStateOf(if (own) current else "")
    val canSave: Boolean get() = text.trim() != (if (own) current else "").trim()
    val hint: String get() =
        if (own) "Leave it empty for the title EchoLedger gives it." else "Type a title of your own, or leave it empty to keep this one."
    val placeholder: String? get() = current.takeUnless { own || it.isBlank() }
}

/** Rename's title box (in [RenameDialog]). */
@Composable
fun RenameField(box: RenameBox, modifier: Modifier = Modifier) {
    OutlinedTextField(
        value = box.text,
        onValueChange = { box.text = it.take(200) },
        singleLine = true,
        label = { Text("Title") },
        placeholder = box.placeholder?.let { words -> { Text(words) } },
        supportingText = { Text(box.hint) },
        modifier = modifier.fillMaxWidth()
    )
}

/**
 * Rename a recording (since 1.4): from the Library's selection menu or by tapping the title on its page. [own]: the
 * title is one the person chose ([ownTitle]); see [RenameBox]. The dialog gives the box the focus when it opens.
 */
@Composable
fun RenameDialog(current: String, onRename: (String) -> Unit, onDismiss: () -> Unit, own: Boolean = true) {
    val box = remember { RenameBox(current, own) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Rename") },
        text = { RenameField(box) },
        confirmButton = { TextButton(onClick = { onRename(box.text.trim()) }, enabled = box.canSave) { Text("Save") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } }
    )
}
