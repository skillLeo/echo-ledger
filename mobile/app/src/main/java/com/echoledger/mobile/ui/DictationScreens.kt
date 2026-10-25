package com.echoledger.mobile.ui

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.provider.Settings
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.Backspace
import androidx.compose.material.icons.automirrored.outlined.KeyboardReturn
import androidx.compose.material.icons.outlined.Keyboard
import androidx.compose.material.icons.outlined.Mic
import androidx.compose.material.icons.outlined.Stop
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.onClick
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.dictation.DictationHistory
import com.echoledger.mobile.dictation.DictationPhase
import com.echoledger.mobile.dictation.DictationText
import com.echoledger.mobile.dictation.DictationUi
import com.echoledger.mobile.dictation.HistoryEntry
import com.echoledger.mobile.dictation.Replacement
import com.echoledger.mobile.dictation.Snippet

/** The line above the microphone: the phrase so far, what is happening, or why dictation could not start. */
fun keyboardStatus(ui: DictationUi, holdToTalk: Boolean): String = when (ui.phase) {
    DictationPhase.Connecting -> "Connecting…"
    DictationPhase.Listening -> ui.preview.ifBlank { if (holdToTalk) "Listening… let go to insert" else "Listening… tap again to insert" }
    DictationPhase.Finishing -> "Inserting…"
    DictationPhase.Idle -> ui.message ?: if (holdToTalk) "Hold the microphone while you speak" else "Tap the microphone and speak"
}

/** Keys the voice keyboard sends to the focused field. */
interface KeyboardKeys {
    fun start()
    fun finish()
    fun space()
    fun backspace()
    fun enter()
    fun switchKeyboard()
    fun openApp()
}

/**
 * The voice keyboard (B14): a flow bar with the phrase and the microphone level, the microphone (tap to start and tap
 * to insert, or hold while speaking), and space, delete, enter and switch keyboard.
 */
@Composable
fun VoiceKeyboard(ui: DictationUi, holdToTalk: Boolean, needsMic: Boolean, keys: KeyboardKeys) {
    Column(
        Modifier.fillMaxWidth().background(Brand.Surface).padding(horizontal = 12.dp, vertical = 10.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Text(
            keyboardStatus(ui, holdToTalk),
            color = if (ui.message != null && !ui.active) Brand.Muted else Brand.Text,
            fontSize = 15.sp,
            maxLines = 2,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.fillMaxWidth().semantics { contentDescription = "Dictation status" }
        )
        FlowBar(if (ui.phase == DictationPhase.Listening) ui.level else 0f)
        if (needsMic) {
            TextButton(onClick = keys::openApp) { Text("Allow the microphone in EchoLedger") }
        }
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
            IconButton(onClick = keys::switchKeyboard) {
                Icon(Icons.Outlined.Keyboard, contentDescription = "Switch keyboard", tint = Brand.Muted)
            }
            TextButton(
                onClick = keys::space,
                modifier = Modifier.semantics {
                    contentDescription = "Space"
                }
            ) { Text("space", color = Brand.Muted) }
            MicButton(ui, holdToTalk, enabled = !needsMic, keys)
            IconButton(onClick = keys::backspace) {
                Icon(Icons.AutoMirrored.Outlined.Backspace, contentDescription = "Delete", tint = Brand.Muted)
            }
            IconButton(onClick = keys::enter) {
                Icon(Icons.AutoMirrored.Outlined.KeyboardReturn, contentDescription = "Enter", tint = Brand.Muted)
            }
        }
    }
}

@Composable
private fun FlowBar(level: Float) {
    val fraction = (kotlin.math.sqrt(level.coerceIn(0f, 1f)) * 2.5f).coerceIn(0f, 1f)
    Box(
        Modifier.fillMaxWidth().height(4.dp).clip(RoundedCornerShape(2.dp)).background(Brand.SurfaceAlt).semantics {
            contentDescription =
                "Microphone level"
        }
    ) {
        Box(Modifier.fillMaxWidth(fraction).height(4.dp).background(Brand.Violet))
    }
}

@Composable
private fun MicButton(ui: DictationUi, holdToTalk: Boolean, enabled: Boolean, keys: KeyboardKeys) {
    val label = when {
        ui.active -> "Stop and insert"
        holdToTalk -> "Hold to dictate"
        else -> "Start dictation"
    }
    val toggle = { if (ui.active) keys.finish() else keys.start() }
    val gesture = if (!enabled) {
        Modifier
    } else if (holdToTalk) {
        Modifier.pointerInput(Unit) {
            detectTapGestures(onPress = {
                keys.start()
                tryAwaitRelease()
                keys.finish()
            })
        }.semantics {
            onClick(label) {
                toggle()
                true
            }
        }
    } else {
        Modifier.clickable(onClickLabel = label) { toggle() }
    }
    Box(
        Modifier.size(64.dp).clip(CircleShape).background(if (ui.active) Brand.Violet else Brand.SurfaceAlt).then(gesture)
            .semantics { contentDescription = label },
        contentAlignment = Alignment.Center
    ) {
        Icon(
            if (ui.active) Icons.Outlined.Stop else Icons.Outlined.Mic,
            contentDescription = null,
            tint = if (enabled) Brand.Text else Brand.Muted
        )
    }
}

// ---- Settings → Dictation ---------------------------------------------------------------------------------------

@Composable
private fun ChipRow(options: List<Pair<String, String>>, selected: String, onSelect: (String) -> Unit) {
    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        options.forEach { (value, text) -> FilterChip(selected = value == selected, onClick = { onSelect(value) }, label = { Text(text) }) }
    }
}

@Composable
private fun SwitchRow(title: String, hint: String?, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(title)
            hint?.let { Text(it, color = Brand.Muted, fontSize = 12.sp) }
        }
        Switch(checked = checked, onCheckedChange = onChange, modifier = Modifier.semantics { contentDescription = title })
    }
}

/** Settings → Dictation: the voice keyboard, how the microphone works, clean-up, sounds, words and snippets, history. */
@Composable
fun DictationSettingsPage(vm: MainViewModel, settings: AppSettings, history: DictationHistory, onBack: () -> Unit) {
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Back") }
            Text("Dictation", fontSize = 22.sp, fontWeight = FontWeight.Bold)
        }
        DictationSettingsContent(vm, settings, history)
    }
}

/** The dictation cards without a page around them (the Dictation group of Settings). */
@Composable
fun DictationSettingsContent(vm: MainViewModel, settings: AppSettings, history: DictationHistory) {
    val context = LocalContext.current
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        SectionCard {
            Text("Voice keyboard", fontWeight = FontWeight.SemiBold)
            Text(
                "Dictate into any app: turn on the EchoLedger voice keyboard, then pick it with the keyboard button. " +
                    "Dictation is never saved as a recording.",
                color = Brand.Muted,
                fontSize = 12.sp
            )
            TextButton(onClick = {
                runCatching { context.startActivity(Intent(Settings.ACTION_INPUT_METHOD_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
            }) { Text("Turn on the voice keyboard") }
            Text("Microphone", color = Brand.Muted, fontSize = 12.sp)
            ChipRow(listOf("toggle" to "Tap to start", "push_to_talk" to "Hold to talk"), settings.dictationMode) { mode ->
                vm.updateSettings { it.copy(dictationMode = mode) }
            }
            SwitchRow("Clean up the text", "Removes “um”, adds capitals and a full stop.", settings.dictationCleanup) { v ->
                vm.updateSettings { it.copy(dictationCleanup = v) }
            }
            SwitchRow("Start and stop sounds", null, settings.dictationSounds) { v -> vm.updateSettings { it.copy(dictationSounds = v) } }
            Text("Say “new line” or “new paragraph” to break lines.", color = Brand.Muted, fontSize = 12.sp)
        }
        ReplacementsSection(settings.dictationReplacements) { list ->
            vm.updateSettings { it.copy(dictationReplacements = DictationText.cleanReplacements(list)) }
        }
        SnippetsSection(settings.dictationSnippets) { list ->
            vm.updateSettings { it.copy(dictationSnippets = DictationText.cleanSnippets(list)) }
        }
        HistorySection(history)
    }
}

@Composable
fun ReplacementsSection(list: List<Replacement>, onSave: (List<Replacement>) -> Unit) {
    var from by remember { mutableStateOf("") }
    var to by remember { mutableStateOf("") }
    SectionCard {
        Text("Dictionary and replacements", fontWeight = FontWeight.SemiBold)
        Text("Fix how names are spelled, or swap a short word for a longer one.", color = Brand.Muted, fontSize = 12.sp)
        list.forEach { r ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("${r.from} → ${r.to.ifEmpty { "(nothing)" }}", Modifier.weight(1f))
                TextButton(onClick = {
                    onSave(list - r)
                }, modifier = Modifier.semantics { contentDescription = "Remove ${r.from}" }) { Text("Remove") }
            }
        }
        OutlinedTextField(from, {
            from = it.take(DictationText.MAX_CHARS)
        }, label = { Text("When I say") }, singleLine = true, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(to, {
            to = it.take(DictationText.MAX_CHARS)
        }, label = { Text("Write") }, singleLine = true, modifier = Modifier.fillMaxWidth())
        TextButton(enabled = from.isNotBlank() && list.none { it.from.equals(from.trim(), true) }, onClick = {
            onSave(list + Replacement(from.trim(), to.trim()))
            from = ""
            to = ""
        }) { Text("Add replacement") }
    }
}

@Composable
fun SnippetsSection(list: List<Snippet>, onSave: (List<Snippet>) -> Unit) {
    var trigger by remember { mutableStateOf("") }
    var text by remember { mutableStateOf("") }
    SectionCard {
        Text("Voice snippets", fontWeight = FontWeight.SemiBold)
        Text("Say the phrase and the saved text goes in, for example “insert signature”.", color = Brand.Muted, fontSize = 12.sp)
        list.forEach { s ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(s.trigger)
                    Text(s.text, color = Brand.Muted, fontSize = 12.sp, maxLines = 3, overflow = TextOverflow.Ellipsis)
                }
                TextButton(onClick = {
                    onSave(list - s)
                }, modifier = Modifier.semantics { contentDescription = "Remove ${s.trigger}" }) { Text("Remove") }
            }
        }
        OutlinedTextField(trigger, {
            trigger = it.take(DictationText.MAX_CHARS)
        }, label = { Text("Phrase") }, singleLine = true, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(text, {
            text = it.take(DictationText.MAX_SNIPPET_CHARS)
        }, label = { Text("Text to insert") }, minLines = 2, modifier = Modifier.fillMaxWidth())
        TextButton(enabled = trigger.isNotBlank() && text.isNotBlank() && list.none { it.trigger.equals(trigger.trim(), true) }, onClick = {
            onSave(list + Snippet(trigger.trim(), text.trim()))
            trigger = ""
            text = ""
        }) { Text("Add snippet") }
    }
}

@Composable
fun HistorySection(history: DictationHistory) {
    val context = LocalContext.current
    var entries by remember { mutableStateOf<List<HistoryEntry>>(history.all()) }
    var copied by remember { mutableStateOf<Int?>(null) }
    SectionCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Recent dictations", fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            if (entries.isNotEmpty()) {
                TextButton(onClick = {
                    history.clear()
                    entries = emptyList()
                }) { Text("Clear history") }
            }
        }
        Text("The last ${DictationHistory.LIMIT}, kept on this phone only.", color = Brand.Muted, fontSize = 12.sp)
        if (entries.isEmpty()) Text("Nothing dictated yet.", color = Brand.Muted, fontSize = 13.sp)
        entries.forEachIndexed { i, entry ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(entry.text, maxLines = 4, overflow = TextOverflow.Ellipsis)
                    Text(formatWhen(entry.at), color = Brand.Muted, fontSize = 12.sp)
                }
                TextButton(onClick = {
                    copyText(context, entry.text)
                    copied = i
                }) { Text(if (copied == i) "Copied" else "Copy") }
            }
        }
    }
}

private fun copyText(context: Context, text: String) {
    (context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager)?.setPrimaryClip(ClipData.newPlainText("Dictation", text))
}
