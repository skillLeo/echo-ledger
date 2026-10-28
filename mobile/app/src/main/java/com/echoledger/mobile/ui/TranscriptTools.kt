package com.echoledger.mobile.ui

import android.content.ClipData
import android.content.Context
import android.content.Intent
import android.print.PrintAttributes
import android.print.PrintManager
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.FilterChip
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalClipboard
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.toClipEntry
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import com.echoledger.mobile.core.Languages
import com.echoledger.mobile.data.SearchItemDto
import com.echoledger.mobile.data.ShareLinkDto
import java.io.File
import kotlinx.coroutines.launch

/** Speeds the phone player offers (B6). */
val PLAYER_SPEEDS = listOf(0.75f, 1f, 1.25f, 1.5f, 2f)

/** Export formats (B10): file name extension → label; `html` is printed to PDF on the phone. */
val EXPORT_FORMATS = listOf(
    "pdf" to "PDF (print)",
    "docx" to "Word",
    "txt" to "Text",
    "md" to "Markdown",
    "srt" to "Subtitles (SRT)",
    "vtt" to "Subtitles (VTT)",
    "json" to "JSON"
)

/** Share link lifetimes in seconds. */
val SHARE_LIFETIMES = listOf(3_600L to "1 hour", 86_400L to "1 day", 604_800L to "7 days")

/** The search-match highlight: the warning token at 35 % (readable under light and dark text). */
private val SNIPPET_HIGHLIGHT = androidx.compose.ui.graphics.Color(0x59F5B731)

/** A search snippet with its matching words in bold on a highlight (the server sends character ranges, not HTML). */
fun snippetText(snippet: String, highlights: List<List<Int>>): AnnotatedString = buildAnnotatedString {
    val points = snippet.codePoints().toArray()
    var pos = 0
    for (range in highlights.filter { it.size == 2 }.sortedBy { it[0] }) {
        val start = range[0]
        val end = (range[0] + range[1]).coerceAtMost(points.size)
        if (start < pos || start >= points.size) continue
        append(String(points, pos, start - pos))
        withStyle(SpanStyle(fontWeight = FontWeight.Bold, background = SNIPPET_HIGHLIGHT)) {
            append(
                String(
                    points,
                    start,
                    end - start
                )
            )
        }
        pos = end
    }
    if (pos < points.size) append(String(points, pos, points.size - pos))
}

/** The speaker label shown once per turn: a line continuing the previous speaker's turn hides it. */
fun continuesTurn(speakers: List<String>, index: Int): Boolean = index > 0 && speakers[index - 1] == speakers[index]

/** The name to show for a speaker label: the renamed one when set. */
fun speakerName(names: Map<String, String>, label: String): String = names[label]?.trim()?.takeIf { it.isNotEmpty() } ?: label

/** Speed chips and the skip-silence switch under the player. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun PlayerOptions(player: RecordingPlayerState) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            PLAYER_SPEEDS.forEach { s ->
                FilterChip(selected = player.speed == s, onClick = {
                    player.changeSpeed(s)
                }, label = { Text("${if (s % 1f == 0f) s.toInt() else s}×") })
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Skip silence", modifier = Modifier.weight(1f), fontSize = 14.sp)
            Switch(checked = player.skipSilence, onCheckedChange = { player.changeSkipSilence(it) })
        }
    }
}

/** Opens the system share sheet for an exported file. */
fun shareFile(context: Context, file: File) {
    val uri = FileProvider.getUriForFile(context, "${context.packageName}.exports", file)
    val send = Intent(Intent.ACTION_SEND)
        .setType(context.contentResolver.getType(uri) ?: "application/octet-stream")
        .putExtra(Intent.EXTRA_STREAM, uri)
        .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    send.clipData = ClipData.newRawUri(file.name, uri)
    context.startActivity(Intent.createChooser(send, "Share transcript").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
}

/** Prints the export's print page with the system print dialog ("Save as PDF" keeps Urdu and Hindi shaping right). */
fun printHtml(context: Context, file: File) {
    val webView = WebView(context)
    webView.webViewClient = object : WebViewClient() {
        override fun onPageFinished(view: WebView, url: String?) {
            val manager = context.getSystemService(PrintManager::class.java) ?: return
            val name = file.nameWithoutExtension
            manager.print(name, view.createPrintDocumentAdapter(name), PrintAttributes.Builder().build())
        }
    }
    webView.loadDataWithBaseURL(null, file.readText(), "text/html", "UTF-8", null)
}

/** Export and share buttons of a finished transcript. */
@Composable
fun ExportShareRow(vm: MainViewModel, sessionId: String, transcriptId: String?, hasAudio: Boolean, view: String? = null) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var menu by remember { mutableStateOf(false) }
    var shareDialog by remember { mutableStateOf(false) }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        if (transcriptId != null) {
            Column {
                OutlinedButton(onClick = { menu = true }) { Text("Export") }
                DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                    EXPORT_FORMATS.forEach { (format, label) ->
                        DropdownMenuItem(text = { Text(label) }, onClick = {
                            menu = false
                            scope.launch {
                                val file = vm.export(transcriptId, if (format == "pdf") "html" else format, view) ?: return@launch
                                if (format == "pdf") printHtml(context, file) else shareFile(context, file)
                            }
                        })
                    }
                }
            }
        }
        OutlinedButton(onClick = { shareDialog = true }) { Text("Share link") }
    }
    if (shareDialog) ShareLinkDialog(vm, sessionId, hasAudio) { shareDialog = false }
}

/** Make a view-only link (shown once, with Copy and the share sheet) and see or revoke the recording's links. */
@Composable
fun ShareLinkDialog(vm: MainViewModel, sessionId: String, hasAudio: Boolean, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Share a view-only link") },
        text = { ShareLinkContent(vm, sessionId, hasAudio) },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Close") } }
    )
}

/** The share dialog's body: lifetime, password, audio, Make link, the new link once, and the existing links. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ShareLinkContent(vm: MainViewModel, sessionId: String, hasAudio: Boolean) {
    val context = LocalContext.current
    val clipboard = LocalClipboard.current
    val scope = rememberCoroutineScope()
    var lifetime by remember { mutableStateOf(86_400L) }
    var password by remember { mutableStateOf("") }
    var audio by remember { mutableStateOf(false) }
    var made by remember { mutableStateOf<String?>(null) }
    var links by remember { mutableStateOf<List<ShareLinkDto>>(emptyList()) }
    var refresh by remember { mutableStateOf(0) }
    LaunchedEffect(sessionId, refresh) { links = vm.shares(sessionId) }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("Expires after", color = Brand.Muted, fontSize = 12.sp)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            SHARE_LIFETIMES.forEach { (seconds, label) ->
                FilterChip(selected = lifetime == seconds, onClick = { lifetime = seconds }, label = { Text(label) })
            }
        }
        OutlinedTextField(
            value = password,
            onValueChange = { password = it.take(100) },
            label = { Text("Password (optional, 6+ characters)") },
            visualTransformation = PasswordVisualTransformation(),
            singleLine = true,
            modifier = Modifier.fillMaxWidth()
        )
        if (hasAudio) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Include the recording", modifier = Modifier.weight(1f))
                Switch(checked = audio, onCheckedChange = { audio = it })
            }
        }
        Button(enabled = password.isEmpty() || password.length >= 6, onClick = {
            scope.launch {
                vm.createShare(sessionId, lifetime, password.ifEmpty { null }, audio)?.let {
                    made = it.url
                    password = ""
                    refresh++
                }
            }
        }) { Text("Make link") }
        made?.let { url ->
            Text("Copy it now — it is shown only once:", color = Brand.Muted, fontSize = 12.sp)
            Text(url, fontSize = 13.sp)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                TextButton(onClick = {
                    scope.launch { clipboard.setClipEntry(ClipData.newPlainText("Share link", url).toClipEntry()) }
                }) { Text("Copy") }
                TextButton(onClick = {
                    context.startActivity(
                        Intent.createChooser(
                            Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, url),
                            "Share link"
                        )
                            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    )
                }) { Text("Send") }
            }
        }
        links.forEach { link ->
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(top = 2.dp)) {
                Column(Modifier.weight(1f)) {
                    Text(
                        if (link.active) {
                            "Active"
                        } else if (link.revokedAt != null) {
                            "Revoked"
                        } else {
                            "Expired"
                        },
                        fontSize = 13.sp
                    )
                    Text(
                        listOfNotNull(
                            "${link.viewCount} view${if (link.viewCount == 1) "" else "s"}",
                            "password".takeIf {
                                link.hasPassword
                            },
                            "audio".takeIf { link.includeAudio }
                        )
                            .joinToString(" · "),
                        color = Brand.Muted,
                        fontSize = 12.sp
                    )
                }
                if (link.active) {
                    TextButton(onClick = {
                        scope.launch { if (vm.revokeShare(link.id)) refresh++ }
                    }) { Text("Revoke", color = Brand.Danger) }
                }
            }
        }
    }
}

/** Matches in transcripts above the library list; tapping one opens the recording at that moment. */
@Composable
fun SearchResultsSection(results: List<SearchItemDto>, onOpen: (SearchItemDto, Long) -> Unit) {
    if (results.isEmpty()) return
    SectionCard {
        Text("In transcripts", fontWeight = FontWeight.SemiBold)
        results.forEach { result ->
            Text(result.session.title ?: "Untitled", fontWeight = FontWeight.Medium, modifier = Modifier.padding(top = 4.dp))
            result.hits.forEach { hit ->
                Row(
                    Modifier.fillMaxWidth().clickable(onClickLabel = "Play from ${formatDuration(hit.startMs)}") {
                        onOpen(result, hit.startMs)
                    }.padding(vertical = 4.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    Text(formatDuration(hit.startMs), color = Brand.Muted, fontSize = 12.sp)
                    Text(
                        buildAnnotatedString {
                            withStyle(SpanStyle(fontWeight = FontWeight.SemiBold)) { append("${hit.speaker}: ") }
                            append(snippetText(hit.snippet, hit.highlights))
                        },
                        fontSize = 13.sp
                    )
                }
            }
        }
    }
}

/** Languages offered for new recordings: Auto, English, Urdu, Arabic, then the More languages (core/Languages). */
val LANGUAGES: List<Pair<String?, String>> = Languages.OPTIONS.map { it.code to it.label }

/** Settings → Transcription: the language of new recordings and "Add to vocabulary" (B11, B12). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun TranscriptionSection(vm: MainViewModel, settings: com.echoledger.mobile.data.AppSettings) {
    var term by remember { mutableStateOf("") }
    SectionCard {
        Text("Transcription", fontWeight = FontWeight.SemiBold)
        Text("Language of new recordings", color = Brand.Muted, fontSize = 12.sp)
        LanguageChips(settings.transcriptionLanguage) { code -> vm.updateSettings { it.copy(transcriptionLanguage = code) } }
        Text(
            "Auto picks English, Urdu or Arabic. Urdu and Arabic always allow English words inside.",
            color = Brand.Muted,
            fontSize = 12.sp
        )
        Text("Add to vocabulary", color = Brand.Muted, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedTextField(
                value = term,
                onValueChange = { term = it.take(50) },
                placeholder = { Text("A name or term the engine gets wrong") },
                singleLine = true,
                modifier = Modifier.weight(1f)
            )
            Button(enabled = term.isNotBlank(), onClick = {
                vm.addKeyterm(term, settings.clientId)
                term = ""
            }) { Text("Add") }
        }
        Text(
            if (settings.clientId != null) "Added to the selected client’s keyterms." else "Added for every recording.",
            color = Brand.Muted,
            fontSize = 12.sp
        )
    }
}
