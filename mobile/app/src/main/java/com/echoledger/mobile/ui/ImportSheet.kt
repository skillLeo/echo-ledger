package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.share.FileImport
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.Spinner
import com.echoledger.mobile.ui.design.tabular
import java.util.Locale
import kotlinx.coroutines.launch

/** "Import 3 files": the picked files, one client and one language for all of them (since 1.4). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ImportSheet(vm: MainViewModel, files: List<FileImport.Picked>, onDone: () -> Unit) {
    val clients by vm.clients.collectAsState()
    val type = LocalEchoType.current
    val c = LocalEchoColors.current
    var clientId by remember { mutableStateOf<String?>(null) }
    var language by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    ModalBottomSheet(onDismissRequest = { if (!busy) onDone() }, containerColor = c.surface) {
        Column(
            Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = Space.screen).padding(bottom = Space.s5),
            verticalArrangement = Arrangement.spacedBy(Space.s3)
        ) {
            Text(ImportCopy.heading(files.size), style = type.titleM, color = c.text)
            Column(Modifier.heightIn(max = 220.dp).verticalScroll(rememberScrollState())) {
                files.forEach { file ->
                    Row(Modifier.fillMaxWidth().padding(vertical = Space.s1), verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            FileImport.titleOf(file.name),
                            style = type.bodyM,
                            color = c.text,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                            modifier = Modifier.weight(1f)
                        )
                        file.size?.let { Text(ImportCopy.size(it), style = type.labelM.tabular(), color = c.textMuted) }
                    }
                }
            }
            Text("For all of them", style = type.labelM, color = c.textMuted)
            if (clients.isNotEmpty()) ClientChip(clients, clientId) { clientId = it }
            LanguageChips(language) { language = it }
            Text(
                "Each file becomes its own recording, named after the file. Files already in your Library are skipped.",
                style = type.caption,
                color = c.textMuted
            )
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (busy) Spinner(18.dp)
                EchoButton(
                    if (busy) "Importing…" else ImportCopy.action(files.size),
                    {
                        busy = true
                        scope.launch {
                            vm.importFiles(files, clientId, language)
                            busy = false
                            onDone()
                        }
                    },
                    Modifier.fillMaxWidth().padding(start = if (busy) Space.s2 else 0.dp),
                    enabled = !busy && files.isNotEmpty()
                )
            }
        }
    }
}

/** The words of the import sheet. */
object ImportCopy {
    fun heading(count: Int): String = if (count == 1) "Import 1 file" else "Import $count files"

    fun action(count: Int): String = if (count == 1) "Import" else "Import all $count"

    /** 734 KB, 12.4 MB. */
    fun size(bytes: Long): String = when {
        bytes >= 1_000_000 -> String.format(Locale.ENGLISH, "%.1f MB", bytes / 1_000_000.0)
        else -> "${maxOf(1, bytes / 1_000)} KB"
    }
}
