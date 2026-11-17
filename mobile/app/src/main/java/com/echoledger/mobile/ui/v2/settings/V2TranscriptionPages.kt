package com.echoledger.mobile.ui.v2.settings

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.R
import com.echoledger.mobile.core.Languages
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoRadio
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSelect
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/** A v2 settings page: its title, Back, and its content scrolling under them. */
@Composable
private fun SettingsPage(title: String, onBack: () -> Unit, modifier: Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar(title, onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()), content = content)
    }
}

@Composable
private fun Line(words: String, modifier: Modifier = Modifier) {
    Text(
        words,
        style = EchoV2.type.meta,
        color = EchoV2.colors.textSecondary,
        modifier = modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    )
}

/** What a main language choice means, in the screen's language. */
@Composable
private fun languageWords(code: String?): String = when (code) {
    null -> stringResource(R.string.v2_tr_lang_auto)
    "en" -> stringResource(R.string.v2_tr_lang_en)
    "ur" -> stringResource(R.string.v2_tr_lang_ur)
    "ar" -> stringResource(R.string.v2_tr_lang_ar)
    else -> Languages.menu(code)
}

/**
 * Language (Transcription & language, IA 5.2): the language of new recordings (the main ones, and the More languages in
 * a picker), and a word for the vocabulary (the selected project's, else every recording's), with Reset to defaults;
 * every control of today's page (TranscriptionPagesParityTest).
 */
@Composable
fun LanguagePage(vm: MainViewModel, settings: AppSettings, highlight: String?, onBack: () -> Unit, modifier: Modifier = Modifier) {
    val current = Languages.canonical(settings.transcriptionLanguage)
    val title = stringResource(R.string.v2_set_page_language)
    SettingsPage(title, onBack, modifier) {
        Column(Modifier.highlightedIf(highlight == "language.new")) {
            EchoSectionHeader(stringResource(R.string.v2_tr_language_title))
            Languages.MAIN.forEach { option ->
                EchoRadio(current == option.code, {
                    vm.updateSettings { it.copy(transcriptionLanguage = option.code) }
                }, languageWords(option.code))
            }
            // The More languages in one picker, so the list stays short.
            val none = stringResource(R.string.v2_tr_more_none)
            val more = listOf<Languages.Option?>(null) + Languages.MORE
            EchoSelect(
                label = stringResource(R.string.v2_tr_more),
                options = more,
                selected = Languages.MORE.firstOrNull { it.code == current },
                onSelect = { option -> option?.let { o -> vm.updateSettings { it.copy(transcriptionLanguage = o.code) } } },
                optionLabel = { it?.label ?: none },
                modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
            )
            Line(stringResource(R.string.v2_tr_lang_help))
        }
        // The field's own label names it: no section header saying the same above it.
        val vocabulary = Modifier.highlightedIf(highlight == "language.vocabulary")
        var term by remember { mutableStateOf("") }
        Row(
            vocabulary.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalAlignment = Alignment.Bottom,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            EchoTextField(
                term,
                { term = it.take(TERM_MAX) },
                label = stringResource(R.string.v2_tr_vocab),
                placeholder = stringResource(R.string.v2_tr_vocab_hint),
                modifier = Modifier.weight(1f)
            )
            EchoButton(stringResource(R.string.v2_tr_add), {
                vm.addKeyterm(term, settings.clientId)
                term = ""
            }, enabled = term.isNotBlank(), kind = EchoButtonKind.Secondary)
        }
        Line(stringResource(if (settings.clientId != null) R.string.v2_tr_vocab_project else R.string.v2_tr_vocab_all))
        PageReset("language", title, vm::updateSettings)
    }
}

/** How long a vocabulary word can be (as today). */
private const val TERM_MAX = 50

/** Custom words: whose keyterms new recordings use (no project, or one of the projects). */
@Composable
fun WordsPage(vm: MainViewModel, settings: AppSettings, highlight: String?, onBack: () -> Unit, modifier: Modifier = Modifier) {
    val clients by vm.clients.collectAsState()
    SettingsPage(stringResource(R.string.v2_set_page_words), onBack, modifier) {
        Column(Modifier.highlightedIf(highlight == "words.project")) {
            EchoSectionHeader(stringResource(R.string.v2_tr_keyterms))
            Line(stringResource(R.string.v2_tr_keyterms_help))
            EchoRadio(settings.clientId == null, {
                vm.updateSettings { it.copy(clientId = null) }
            }, stringResource(R.string.v2_tr_no_project))
            clients.forEach { client ->
                EchoRadio(settings.clientId == client.id, { vm.updateSettings { it.copy(clientId = client.id) } }, client.name)
            }
        }
    }
}

/**
 * Transcription key (the Free plan): none needed on a plan with EchoLedger's engines; else the three steps to a free
 * key with Open console.groq.com, the field and Save; with one saved, Remove key (asked first) and Replace.
 */
@Composable
fun KeyPage(vm: MainViewModel, plan: PlanState, highlight: String?, onBack: () -> Unit, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var key by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    var removing by remember { mutableStateOf(false) }
    SettingsPage(stringResource(R.string.v2_set_page_key), onBack, modifier) {
        EchoSectionHeader(stringResource(R.string.v2_tr_key_title), Modifier.highlightedIf(highlight == "key.groq"))
        if (!plan.needsOwnKey && plan.transcriptionKey?.set != true) {
            Line(stringResource(R.string.v2_tr_key_not_needed))
            return@SettingsPage
        }
        val saved = plan.transcriptionKey?.takeIf { it.set }
        Column(
            Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            if (saved != null) {
                Text(stringResource(R.string.v2_tr_key_saved, saved.last4 ?: "····"), style = EchoV2.type.body, color = c.textPrimary)
                EchoButton(stringResource(R.string.v2_tr_key_remove), { removing = true }, kind = EchoButtonKind.Quiet)
            } else {
                listOf(R.string.v2_tr_key_intro, R.string.v2_tr_key_step1, R.string.v2_tr_key_step2, R.string.v2_tr_key_step3).forEach {
                    Text(stringResource(it), style = EchoV2.type.body, color = c.textPrimary)
                }
                EchoButton(stringResource(R.string.v2_tr_key_open), {
                    runCatching {
                        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(GROQ_KEYS)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                    }
                }, kind = EchoButtonKind.Secondary)
            }
            EchoTextField(
                key,
                { key = it.trim() },
                label = stringResource(if (saved != null) R.string.v2_tr_key_replace else R.string.v2_tr_key_field),
                secret = true
            )
            EchoButton(stringResource(R.string.v2_tr_key_save), {
                saving = true
                scope.launch {
                    if (vm.saveTranscriptionKey(key)) key = ""
                    saving = false
                }
            }, Modifier.fillMaxWidth(), enabled = key.length >= KEY_MIN, loading = saving)
            Text(stringResource(R.string.v2_tr_key_private), style = EchoV2.type.meta, color = c.textSecondary)
        }
    }
    if (removing) {
        EchoDialog(
            title = stringResource(R.string.v2_tr_key_remove),
            text = stringResource(R.string.v2_tr_key_remove_question),
            confirmLabel = stringResource(R.string.v2_tr_key_remove_confirm),
            onConfirm = {
                removing = false
                scope.launch { vm.removeTranscriptionKey() }
            },
            dismissLabel = stringResource(R.string.v2_tr_key_cancel),
            onDismiss = { removing = false },
            destructive = true
        )
    }
}

private const val GROQ_KEYS = "https://console.groq.com/keys"

/** The shortest a key can be before Save (as today). */
private const val KEY_MIN = 20
