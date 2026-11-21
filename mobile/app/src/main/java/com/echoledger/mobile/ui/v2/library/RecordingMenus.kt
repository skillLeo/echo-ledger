package com.echoledger.mobile.ui.v2.library

import android.content.ClipData
import android.content.Context
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.DriveFileMove
import androidx.compose.material.icons.automirrored.outlined.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.outlined.Label
import androidx.compose.material.icons.automirrored.outlined.Send
import androidx.compose.material.icons.outlined.AllInclusive
import androidx.compose.material.icons.outlined.ContentCopy
import androidx.compose.material.icons.outlined.DriveFileRenameOutline
import androidx.compose.material.icons.outlined.FastForward
import androidx.compose.material.icons.outlined.FileDownload
import androidx.compose.material.icons.outlined.Link
import androidx.compose.material.icons.outlined.PushPin
import androidx.compose.material.icons.outlined.Translate
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.disabled
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.R
import com.echoledger.mobile.core.Languages
import com.echoledger.mobile.core.Organise
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.ui.ProjectTree
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoChip
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** The icons the recording page adds to the v2 set, from the same family (Material Symbols, outlined). */
object RecordingIcons {
    val Rename: ImageVector get() = Icons.Outlined.DriveFileRenameOutline
    val Move: ImageVector get() = Icons.AutoMirrored.Outlined.DriveFileMove
    val Label: ImageVector get() = Icons.AutoMirrored.Outlined.Label
    val Pin: ImageVector get() = Icons.Outlined.PushPin
    val Keep: ImageVector get() = Icons.Outlined.AllInclusive
    val Language: ImageVector get() = Icons.Outlined.Translate
    val SkipSilence: ImageVector get() = Icons.Outlined.FastForward
    val Export: ImageVector get() = Icons.Outlined.FileDownload
    val Copy: ImageVector get() = Icons.Outlined.ContentCopy
    val Link: ImageVector get() = Icons.Outlined.Link
    val Send: ImageVector get() = Icons.AutoMirrored.Outlined.Send
    val Open: ImageVector get() = Icons.AutoMirrored.Outlined.KeyboardArrowRight
}

/** The export choices, in the menu's order: what each asks the server for, and its words. */
enum class ExportFormat(val label: Int, val pack: Boolean = false) {
    Pdf(R.string.v2_rec_export_pdf),
    Docx(R.string.v2_rec_export_docx),
    Txt(R.string.v2_rec_export_txt),
    Md(R.string.v2_rec_export_md),
    Srt(R.string.v2_rec_export_srt),
    Vtt(R.string.v2_rec_export_vtt),
    Json(R.string.v2_rec_export_json),
    Html(R.string.v2_rec_export_html),
    PackPdf(R.string.v2_rec_export_pack_pdf, pack = true),
    PackZip(R.string.v2_rec_export_pack_zip, pack = true);

    /** The format the server writes ([Pdf] and the PDF meeting pack are its print page, printed on the phone). */
    val wire: String get() = when (this) {
        Pdf, Html, PackPdf -> "html"
        Docx -> "docx"
        Txt -> "txt"
        Md -> "md"
        Srt -> "srt"
        Vtt -> "vtt"
        Json -> "json"
        PackZip -> "zip"
    }

    /** Printed with the system print dialog ("Save as PDF" keeps Urdu shaping right), not shared as a file. */
    val printed: Boolean get() = this == Pdf || this == PackPdf
}

/**
 * One line of a menu: its icon and words, 48 dp or more; [danger] draws it in the danger colour. While [busy] (the task
 * in words: "Saving the voice…") those words replace the label and the row waits, like a loading button. [enabled]
 * false (another row is working) keeps its look but says disabled; a tap still reaches [onClick] (to say "wait").
 */
@Composable
internal fun MenuRow(
    icon: ImageVector?,
    label: String,
    onClick: () -> Unit,
    danger: Boolean = false,
    trailing: ImageVector? = null,
    busy: String? = null,
    enabled: Boolean = true
) {
    val c = EchoV2.colors
    val tint = if (danger) c.danger else c.textPrimary
    Row(
        Modifier
            .fillMaxWidth()
            .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
            .clickable(enabled = busy == null, role = Role.Button, onClick = onClick)
            .semantics { if (!enabled) disabled() }
            .padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
    ) {
        icon?.let { Icon(it, null, Modifier.size(EchoTokens.Icon.lg), tint = if (danger) c.danger else c.textSecondary) }
        Text(busy ?: label, style = EchoV2.type.body, color = if (busy != null) c.textSecondary else tint, modifier = Modifier.weight(1f))
        trailing?.let { Icon(it, null, Modifier.size(EchoTokens.Icon.lg), tint = c.textSecondary) }
    }
}

/** A small group header inside a menu (Organise, Transcript, Export). */
@Composable
private fun MenuGroup(title: String) = EchoSectionHeader(title, Modifier.semantics { heading() })

/** What the ⋮ menu can ask the page to do. */
interface RecordingMenuActions {
    fun rename()
    fun move()
    fun labels()
    fun pin(on: Boolean)
    fun keep(on: Boolean)
    fun retranscribe()
    fun skipSilences(on: Boolean)
    fun export(format: ExportFormat)
    fun delete()
}

/**
 * The recording's ⋮ menu (parity plan 4 and 7): Organise (Rename, Move to project, Labels, Pin, Keep forever), Transcript
 * (Change language & re-transcribe, Skip silences), Export ▸ (a second page: every format), Back to the app a voice note
 * came from, and Delete last, in red. Grouped with small headers, nothing deeper than two taps.
 */
@Composable
fun RecordingMenuSheet(
    item: LibraryItem,
    hasAudio: Boolean,
    skipSilence: Boolean,
    canExport: Boolean,
    actions: RecordingMenuActions,
    onDismiss: () -> Unit,
    backToSource: Pair<String, () -> Unit>? = null,
    canRetranscribe: Boolean = true
) {
    var exporting by remember { mutableStateOf(false) }
    EchoBottomSheet(onDismiss = onDismiss) {
        RecordingMenuContent(item, hasAudio, skipSilence, canExport, exporting, {
            exporting = it
        }, actions, onDismiss, backToSource, canRetranscribe)
    }
}

/** The menu's two pages (the main one, and Export ▸), without the sheet (screenshots and tests). */
@Composable
fun RecordingMenuContent(
    item: LibraryItem,
    hasAudio: Boolean,
    skipSilence: Boolean,
    canExport: Boolean,
    exporting: Boolean,
    onExporting: (Boolean) -> Unit,
    actions: RecordingMenuActions,
    onDone: () -> Unit,
    backToSource: Pair<String, () -> Unit>? = null,
    /** Change language & re-transcribe: only for a recording with a final transcript and audio (classic). */
    canRetranscribe: Boolean = true
) {
    fun pick(action: () -> Unit) {
        onDone()
        action()
    }
    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState())) {
        if (exporting) {
            MenuRow(EchoIcons.Back, stringResource(R.string.v2_rec_menu_export), { onExporting(false) })
            if (!canExport) {
                Text(
                    stringResource(R.string.v2_rec_export_not_ready),
                    style = EchoV2.type.meta,
                    color = EchoV2.colors.textSecondary,
                    modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
                )
            } else {
                ExportFormat.entries.forEach { f ->
                    MenuRow(null, stringResource(f.label), { pick { actions.export(f) } })
                }
            }
            return@Column
        }
        MenuGroup(stringResource(R.string.v2_rec_menu_organise))
        MenuRow(RecordingIcons.Rename, stringResource(R.string.v2_rec_rename), { pick(actions::rename) })
        MenuRow(RecordingIcons.Move, stringResource(R.string.v2_rec_move), { pick(actions::move) })
        MenuRow(RecordingIcons.Label, stringResource(R.string.v2_rec_labels), { pick(actions::labels) })
        MenuRow(
            RecordingIcons.Pin,
            stringResource(if (item.pinned) R.string.v2_rec_unpin else R.string.v2_rec_pin),
            { pick { actions.pin(!item.pinned) } }
        )
        MenuRow(
            RecordingIcons.Keep,
            stringResource(if (item.keep) R.string.v2_rec_stop_keeping else R.string.v2_rec_keep),
            { pick { actions.keep(!item.keep) } }
        )
        if (canRetranscribe || hasAudio) MenuGroup(stringResource(R.string.v2_rec_menu_transcript))
        if (canRetranscribe) MenuRow(RecordingIcons.Language, stringResource(R.string.v2_rec_retranscribe), { pick(actions::retranscribe) })
        if (hasAudio) {
            MenuRow(
                RecordingIcons.SkipSilence,
                stringResource(if (skipSilence) R.string.v2_rec_play_silences else R.string.v2_rec_skip_silences),
                { pick { actions.skipSilences(!skipSilence) } }
            )
        }
        MenuGroup(stringResource(R.string.v2_rec_menu_export))
        MenuRow(RecordingIcons.Export, stringResource(R.string.v2_rec_menu_export), { onExporting(true) }, trailing = RecordingIcons.Open)
        backToSource?.let { (label, go) ->
            MenuRow(EchoIcons.Back, stringResource(R.string.v2_rec_back_to, label), { pick(go) })
        }
        MenuRow(EchoIcons.Delete, stringResource(R.string.v2_rec_delete), { pick(actions::delete) }, danger = true)
    }
}

/** Delete, asked first (the same question as classic's): it goes to Trash on every device. */
@Composable
fun DeleteRecordingDialog(onDelete: () -> Unit, onDismiss: () -> Unit) = EchoDialog(
    title = stringResource(R.string.v2_rec_delete_title),
    text = stringResource(R.string.v2_rec_delete_body),
    confirmLabel = stringResource(R.string.v2_rec_delete),
    onConfirm = onDelete,
    dismissLabel = stringResource(R.string.v2_rec_cancel),
    onDismiss = onDismiss,
    destructive = true
)

/** Rename: the title as it is, Save (an empty title clears it, as in classic). */
@Composable
fun RenameSheet(current: String, onRename: (String) -> Unit, onDismiss: () -> Unit) {
    var title by remember { mutableStateOf(current) }
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_rec_rename)) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            EchoTextField(title, { title = it.take(MAX_TITLE) }, stringResource(R.string.v2_rec_title_hint))
            EchoButton(stringResource(R.string.v2_rec_save), { onRename(title.trim()) }, Modifier.fillMaxWidth())
        }
    }
}

/** Move to project: No project, every project as a tree (subfolders indented), and New project (name, Create). */
@Composable
fun MoveSheet(
    item: LibraryItem,
    projects: List<ClientDto>,
    onMove: (ClientDto?) -> Unit,
    onCreate: (String) -> Unit,
    onDismiss: () -> Unit
) {
    var creating by remember { mutableStateOf<String?>(null) }
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_rec_move)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState())) {
            MenuRow(
                EchoIcons.Folder,
                stringResource(R.string.v2_rec_no_project),
                { onMove(null) },
                trailing = EchoIcons.Check.takeIf { item.client == null }
            )
            ProjectTree.flatten(projects).forEach { (project, depth) ->
                Row(Modifier.padding(start = EchoTokens.Space.s16 * depth)) {
                    MenuRow(
                        EchoIcons.Folder,
                        project.name,
                        { onMove(project) },
                        trailing = EchoIcons.Check.takeIf { item.client?.id == project.id }
                    )
                }
            }
            val name = creating
            if (name == null) {
                MenuRow(EchoIcons.Add, stringResource(R.string.v2_rec_new_project), { creating = "" })
            } else {
                Column(
                    Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
                    verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
                ) {
                    EchoTextField(name, { creating = it.take(MAX_PROJECT_NAME) }, stringResource(R.string.v2_rec_project_name))
                    EchoButton(stringResource(R.string.v2_rec_create), { onCreate(name.trim()) }, enabled = name.isNotBlank())
                }
            }
        }
    }
}

/** Labels: the recording's labels (each one removable), a field to add one, and the labels used elsewhere to pick. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun LabelsSheet(labels: List<String>, known: List<String>, onLabels: (List<String>) -> Unit, onDismiss: () -> Unit) {
    var typed by remember { mutableStateOf("") }
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_rec_labels)) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            if (labels.isEmpty()) {
                Text(stringResource(R.string.v2_rec_no_labels), style = EchoV2.type.meta, color = EchoV2.colors.textSecondary)
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                labels.forEach { l ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(l, style = EchoV2.type.content(l, EchoV2.type.body), color = EchoV2.colors.textPrimary)
                        EchoIconButton(EchoIcons.Close, stringResource(R.string.v2_rec_remove_label, l), { onLabels(labels - l) })
                    }
                }
            }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                EchoTextField(typed, {
                    typed = it.take(Organise.MAX_LABEL_CHARS)
                }, stringResource(R.string.v2_rec_add_label), Modifier.weight(1f))
                val clean = Organise.cleanLabel(typed)
                EchoButton(stringResource(R.string.v2_rec_add), {
                    if (clean != null && clean !in labels) onLabels(labels + clean)
                    typed = ""
                }, kind = EchoButtonKind.Secondary, enabled = clean != null && labels.size < Organise.MAX_LABELS)
            }
            val suggestions = known.filter { it !in labels && (typed.isBlank() || it.contains(typed.trim(), ignoreCase = true)) }.take(8)
            if (suggestions.isNotEmpty()) {
                FlowRow(
                    horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8),
                    verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
                ) {
                    suggestions.forEach { l -> EchoChip("+ $l", selected = false, onClick = { onLabels(labels + l) }) }
                }
            }
        }
    }
}

/**
 * Change language & re-transcribe (classic's "Re-transcribe as…"): the main languages and the more ones, the warning when
 * English only would translate the Urdu (said once, then "Re-transcribe anyway"), and that the current transcript stays.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun RetranscribeSheet(item: LibraryItem, onRetranscribe: (String?) -> Unit, onDismiss: () -> Unit) {
    var chosen by remember(item.id) { mutableStateOf(Languages.canonical(item.language)) }
    var warned by remember(item.id) { mutableStateOf(false) }
    var more by remember(item.id) { mutableStateOf(Languages.MORE.any { it.code == chosen }) }
    val risk = chosen == "en" && Languages.englishOnlyRisk(item.transcript?.finalLanguage, item.transcript?.preview)
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_rec_retranscribe_title)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState())
                .padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            FlowRow(
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8),
                verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
            ) {
                Languages.MAIN.forEach { option ->
                    EchoChip(languageWords(option.code), selected = chosen == option.code, onClick = {
                        chosen = option.code
                        warned = false
                    })
                }
                EchoChip(stringResource(R.string.v2_rec_more_languages), selected = more, onClick = { more = !more })
            }
            if (more) {
                FlowRow(
                    horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8),
                    verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
                ) {
                    Languages.MORE.forEach { option ->
                        EchoChip(option.menu, selected = chosen == option.code, onClick = { chosen = option.code })
                    }
                }
            }
            if (risk && warned) {
                Text(stringResource(R.string.v2_rec_english_only_warning), style = EchoV2.type.meta, color = EchoV2.colors.warning)
            }
            Text(stringResource(R.string.v2_rec_retranscribe_keeps), style = EchoV2.type.meta, color = EchoV2.colors.textSecondary)
            EchoButton(
                stringResource(if (risk && warned) R.string.v2_rec_retranscribe_anyway else R.string.v2_rec_retranscribe_go),
                {
                    if (risk && !warned) {
                        warned = true
                    } else {
                        onRetranscribe(chosen)
                    }
                },
                Modifier.fillMaxWidth()
            )
        }
    }
}

/** A main language choice in the screen's language (the v2 Language page's words). */
@Composable
internal fun languageWords(code: String?): String = when (code) {
    null -> stringResource(R.string.v2_tr_lang_auto)
    "en" -> stringResource(R.string.v2_tr_lang_en)
    "ur" -> stringResource(R.string.v2_tr_lang_ur)
    "ar" -> stringResource(R.string.v2_tr_lang_ar)
    else -> Languages.menu(code)
}

/** Puts [text] on the clipboard under [label] (classic's copy, as plain text). */
internal fun copyToClipboard(context: Context, label: String, text: String) {
    context.getSystemService(android.content.ClipboardManager::class.java)?.setPrimaryClip(ClipData.newPlainText(label, text))
}

private const val MAX_TITLE = 200
private const val MAX_PROJECT_NAME = 120
