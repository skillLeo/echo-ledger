package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.Languages
import com.echoledger.mobile.data.LibraryItem
import kotlinx.coroutines.launch

/** Which version of the transcript the recording screen shows: the newest (the default) or the previous one. */
enum class TranscriptVersion { CURRENT, PREVIOUS }

/** The previous version's transcript id, once it is ready to read; null when the recording has only one version. */
internal fun previousVersionId(item: LibraryItem): String? =
    item.transcript?.alternateTranscriptId?.takeIf { item.transcript.alternateStatus == "completed" }

/**
 * Under the player: Current / Previous (when the recording has two versions) and "Re-transcribe as…", which asks
 * for the language. The current transcript stays until the new one is ready (since v1.2).
 */
@Composable
internal fun RetranscribeRow(vm: MainViewModel, item: LibraryItem, version: TranscriptVersion, onVersion: (TranscriptVersion) -> Unit) {
    val scope = rememberCoroutineScope()
    var asking by remember(item.id) { mutableStateOf(false) }
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        if (previousVersionId(item) != null) {
            FilterChip(
                selected = version == TranscriptVersion.CURRENT,
                onClick = { onVersion(TranscriptVersion.CURRENT) },
                label = { Text("Current") }
            )
            val previous = item.transcript?.alternateLanguage?.let { " (${Languages.label(it)})" }.orEmpty()
            FilterChip(
                selected = version == TranscriptVersion.PREVIOUS,
                onClick = { onVersion(TranscriptVersion.PREVIOUS) },
                label = { Text("Previous$previous") }
            )
        }
        Spacer(Modifier.weight(1f))
        TextButton(onClick = { asking = true }) { Text("Re-transcribe as…") }
    }
    if (asking) {
        var chosen by remember(item.id) { mutableStateOf(Languages.canonical(item.language)) }
        AlertDialog(
            onDismissRequest = { asking = false },
            title = { Text("Re-transcribe as") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    LanguageChips(chosen) { chosen = it }
                    Text("The current transcript stays until the new one is ready.", color = Brand.Muted, fontSize = 13.sp)
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    asking = false
                    scope.launch { vm.retranscribe(item.id, chosen) }
                }) { Text("Re-transcribe") }
            },
            dismissButton = { TextButton(onClick = { asking = false }) { Text("Cancel") } }
        )
    }
}
