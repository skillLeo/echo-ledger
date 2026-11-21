package com.echoledger.mobile.ui.v2.settings

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.provider.Settings
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.R
import com.echoledger.mobile.core.SharedNotes
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.dictation.DictationHistory
import com.echoledger.mobile.dictation.DictationText
import com.echoledger.mobile.dictation.HistoryEntry
import com.echoledger.mobile.dictation.Replacement
import com.echoledger.mobile.dictation.Snippet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoChip
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoRadio
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/** The dictation microphone's two ways, as stored (classic's values: `toggle`, `push_to_talk`). */
const val DICTATION_TAP = "toggle"
const val DICTATION_HOLD = "push_to_talk"

/**
 * Voice notes & dictation on the v2 components (classic's After sharing a voice note and Dictation, the same settings):
 * after sharing, Open the voice note or Stay and notify me quietly; the voice keyboard (Turn on opens Android's
 * keyboards); the microphone, Tap to start or Hold to talk; Clean up the text; Start and stop sounds; Dictionary and
 * replacements and Voice snippets (add, remove); Recent dictations (Copy, Clear history, which asks); Reset to defaults.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun VoicePage(
    settings: AppSettings,
    update: ((AppSettings) -> AppSettings) -> Unit,
    history: DictationHistory,
    highlight: String?,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    val context = LocalContext.current
    val c = EchoV2.colors
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_set_page_voice), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            Column(Modifier.highlightedIf(highlight == "voice.share")) {
                EchoSectionHeader(stringResource(R.string.v2_vc_after_share))
                Text(stringResource(R.string.v2_vc_after_share_help), style = EchoV2.type.caption, color = c.textSecondary, modifier = pad)
                listOf(SharedNotes.OPEN to R.string.v2_vc_open, SharedNotes.STAY to R.string.v2_vc_stay).forEach { (value, words) ->
                    EchoRadio(
                        selected = settings.afterShare == value,
                        onClick = { update { it.copy(afterShare = value) } },
                        label = stringResource(words),
                        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
                    )
                }
            }
            Column(Modifier.highlightedIf(highlight == "voice.dictation")) {
                Column(Modifier.highlightedIf(highlight == "voice.keyboard")) {
                    EchoSectionHeader(stringResource(R.string.v2_vc_keyboard))
                    Text(stringResource(R.string.v2_vc_keyboard_help), style = EchoV2.type.caption, color = c.textSecondary, modifier = pad)
                    EchoButton(stringResource(R.string.v2_vc_keyboard_on), {
                        runCatching {
                            context.startActivity(Intent(Settings.ACTION_INPUT_METHOD_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                        }
                    }, pad, kind = EchoButtonKind.Secondary)
                }
                val mic = stringResource(R.string.v2_vc_mic)
                Column(Modifier.highlightedIf(highlight == "voice.mic")) {
                    Text(mic, style = EchoV2.type.label, color = c.textSecondary, modifier = pad)
                    FlowRow(pad, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                        listOf(
                            DICTATION_TAP to R.string.v2_set_value_tap_to_start,
                            DICTATION_HOLD to R.string.v2_set_value_hold_to_talk
                        ).forEach { (mode, words) ->
                            val label = stringResource(words)
                            EchoChip(
                                label,
                                settings.dictationMode == mode,
                                { update { it.copy(dictationMode = mode) } },
                                Modifier.semantics { contentDescription = "$mic: $label" }
                            )
                        }
                    }
                }
                EchoSwitch(
                    settings.dictationCleanup,
                    { on -> update { it.copy(dictationCleanup = on) } },
                    stringResource(R.string.v2_vc_cleanup),
                    supporting = stringResource(R.string.v2_vc_cleanup_help),
                    modifier = Modifier.highlightedIf(highlight == "voice.cleanup")
                )
                EchoSwitch(
                    settings.dictationSounds,
                    { on -> update { it.copy(dictationSounds = on) } },
                    stringResource(R.string.v2_vc_sounds),
                    modifier = Modifier.highlightedIf(highlight == "voice.sounds")
                )
                Text(stringResource(R.string.v2_vc_new_line), style = EchoV2.type.caption, color = c.textSecondary, modifier = pad)
            }
            Column(Modifier.highlightedIf(highlight == "voice.replacements")) {
                Replacements(settings.dictationReplacements) { list ->
                    update { it.copy(dictationReplacements = DictationText.cleanReplacements(list)) }
                }
            }
            Column(Modifier.highlightedIf(highlight == "voice.snippets")) {
                Snippets(settings.dictationSnippets) { list -> update { it.copy(dictationSnippets = DictationText.cleanSnippets(list)) } }
            }
            Column(Modifier.highlightedIf(highlight == "voice.history")) { History(history) }
            PageReset("voice", stringResource(R.string.v2_set_page_voice), update)
        }
    }
}

/** A saved pair's row: its words, and Remove (named for TalkBack by what it removes). */
@Composable
private fun SavedRow(title: String, detail: String?, removeName: String, onRemove: () -> Unit) {
    val c = EchoV2.colors
    Row(
        Modifier.fillMaxWidth().padding(start = EchoTokens.Layout.pagePaddingMobile, end = EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Column(Modifier.weight(1f)) {
            Text(title, style = EchoV2.type.content(title, EchoV2.type.body), color = c.textPrimary)
            detail?.let {
                Text(
                    it,
                    style = EchoV2.type.content(it, EchoV2.type.meta),
                    color = c.textSecondary,
                    maxLines = 3,
                    overflow = TextOverflow.Ellipsis
                )
            }
        }
        EchoButton(
            stringResource(R.string.v2_vc_remove),
            onRemove,
            Modifier.semantics {
                contentDescription = removeName
            },
            kind = EchoButtonKind.Quiet
        )
    }
}

/** Dictionary and replacements: "When I say" → "Write"; Add replacement (not twice for the same words), Remove. */
@Composable
private fun Replacements(list: List<Replacement>, onSave: (List<Replacement>) -> Unit) {
    var from by remember { mutableStateOf("") }
    var to by remember { mutableStateOf("") }
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    EchoSectionHeader(stringResource(R.string.v2_vc_replacements))
    Text(stringResource(R.string.v2_vc_replacements_help), style = EchoV2.type.caption, color = EchoV2.colors.textSecondary, modifier = pad)
    val nothing = stringResource(R.string.v2_vc_nothing)
    list.forEach { r ->
        SavedRow("${r.from} → ${r.to.ifEmpty { nothing }}", null, stringResource(R.string.v2_vc_remove_named, r.from)) { onSave(list - r) }
    }
    Column(pad, verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
        EchoTextField(from, { from = it.take(DictationText.MAX_CHARS) }, stringResource(R.string.v2_vc_when_i_say))
        EchoTextField(to, { to = it.take(DictationText.MAX_CHARS) }, stringResource(R.string.v2_vc_write))
        EchoButton(
            stringResource(R.string.v2_vc_add_replacement),
            {
                onSave(list + Replacement(from.trim(), to.trim()))
                from = ""
                to = ""
            },
            enabled = from.isNotBlank() && list.none { it.from.equals(from.trim(), true) },
            kind = EchoButtonKind.Secondary
        )
    }
}

/** Voice snippets: "Phrase" → "Text to insert"; Add snippet (not twice for the same phrase), Remove. */
@Composable
private fun Snippets(list: List<Snippet>, onSave: (List<Snippet>) -> Unit) {
    var trigger by remember { mutableStateOf("") }
    var text by remember { mutableStateOf("") }
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    EchoSectionHeader(stringResource(R.string.v2_vc_snippets))
    Text(stringResource(R.string.v2_vc_snippets_help), style = EchoV2.type.caption, color = EchoV2.colors.textSecondary, modifier = pad)
    list.forEach { s -> SavedRow(s.trigger, s.text, stringResource(R.string.v2_vc_remove_named, s.trigger)) { onSave(list - s) } }
    Column(pad, verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
        EchoTextField(trigger, { trigger = it.take(DictationText.MAX_CHARS) }, stringResource(R.string.v2_vc_phrase))
        EchoTextField(text, {
            text = it.take(DictationText.MAX_SNIPPET_CHARS)
        }, stringResource(R.string.v2_vc_text_to_insert), singleLine = false)
        EchoButton(
            stringResource(R.string.v2_vc_add_snippet),
            {
                onSave(list + Snippet(trigger.trim(), text.trim()))
                trigger = ""
                text = ""
            },
            enabled = trigger.isNotBlank() && text.isNotBlank() && list.none { it.trigger.equals(trigger.trim(), true) },
            kind = EchoButtonKind.Secondary
        )
    }
}

/** Recent dictations (on this phone only): each with Copy; Clear history asks first, as they can't be brought back. */
@Composable
private fun History(history: DictationHistory) {
    val context = LocalContext.current
    val c = EchoV2.colors
    val toasts = LocalEchoToasts.current
    val scope = rememberCoroutineScope()
    var entries by remember { mutableStateOf<List<HistoryEntry>>(history.all()) }
    var clearing by remember { mutableStateOf(false) }
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    if (clearing) {
        EchoDialog(
            title = stringResource(R.string.v2_vc_clear_title),
            text = stringResource(R.string.v2_vc_clear_body),
            confirmLabel = stringResource(R.string.v2_vc_clear),
            onConfirm = {
                clearing = false
                history.clear()
                entries = emptyList()
            },
            dismissLabel = stringResource(R.string.v2_cancel),
            onDismiss = { clearing = false },
            destructive = true
        )
    }
    EchoSectionHeader(stringResource(R.string.v2_vc_recent))
    Text(
        stringResource(R.string.v2_vc_recent_help, DictationHistory.LIMIT),
        style = EchoV2.type.caption,
        color = c.textSecondary,
        modifier = pad
    )
    if (entries.isEmpty()) {
        Text(stringResource(R.string.v2_vc_recent_none), style = EchoV2.type.meta, color = c.textSecondary, modifier = pad)
    } else {
        EchoButton(stringResource(R.string.v2_vc_clear_history), { clearing = true }, pad, kind = EchoButtonKind.Quiet)
    }
    val copied = stringResource(R.string.v2_vc_copied)
    entries.forEach { entry ->
        Row(
            Modifier.fillMaxWidth().padding(start = EchoTokens.Layout.pagePaddingMobile, end = EchoTokens.Space.s8),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Column(Modifier.weight(1f)) {
                Text(
                    entry.text,
                    style = EchoV2.type.content(entry.text, EchoV2.type.body),
                    color = c.textPrimary,
                    maxLines = 4,
                    overflow = TextOverflow.Ellipsis
                )
                Text(
                    com.echoledger.mobile.ui.v2.library.LibraryModel.latinDigits(com.echoledger.mobile.ui.formatWhen(entry.at)),
                    style = EchoV2.type.mono,
                    color = c.textSecondary
                )
            }
            EchoButton(stringResource(R.string.v2_vc_copy), {
                copy(context, entry.text)
                scope.launch { toasts.show(copied) }
            }, kind = EchoButtonKind.Quiet)
        }
    }
}

private fun copy(context: Context, text: String) {
    context.getSystemService(ClipboardManager::class.java)?.setPrimaryClip(ClipData.newPlainText("Dictation", text))
}
