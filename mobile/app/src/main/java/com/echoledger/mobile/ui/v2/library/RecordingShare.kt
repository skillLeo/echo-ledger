package com.echoledger.mobile.ui.v2.library

import android.content.Context
import android.content.Intent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.R
import com.echoledger.mobile.core.Languages
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.TranscriptText
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.share.WhatsAppShare
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.SendSheet
import com.echoledger.mobile.ui.ShareSheet
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.launch

/** The Copy ▾ menu's choices. */
enum class CopyKind { Text, WithTimestamps, WithoutTimestamps, ForAi }

/**
 * What Copy puts on the clipboard: classic's copy ([TranscriptText.copied]: the view on screen, unsure words marked in
 * the console's style, Copy for AI's note first and its screens after their lines), and for Copy for AI a short header
 * the plan asks for (title, date, people, language), in English like the note under it.
 */
object RecordingCopy {
    fun text(
        kind: CopyKind,
        item: LibraryItem,
        segments: List<SegmentDto>,
        shown: List<String?>?,
        settings: AppSettings,
        screens: List<ScreenDto>,
        speakerNames: Map<String, String> = emptyMap()
    ): String {
        val body = TranscriptText.copied(
            segments,
            { i -> shown?.getOrNull(i) },
            withTimestamps = kind == CopyKind.WithTimestamps,
            style = settings.unsureStyle,
            threshold = settings.unsureThreshold,
            noteOn = settings.unsureNote,
            forAi = kind == CopyKind.ForAi,
            screens = screens
        )
        return if (kind == CopyKind.ForAi) aiHeader(item, segments, speakerNames) + "\n\n" + body else body
    }

    /** "Title: …", "Date: …", "People: …", "Language: …": only the lines the recording has. */
    fun aiHeader(item: LibraryItem, segments: List<SegmentDto>, speakerNames: Map<String, String> = emptyMap()): String {
        val title = item.title?.takeIf { it.isNotBlank() }
        val date = item.startedAt?.let { runCatching { OffsetDateTime.parse(it) }.getOrNull() }
            ?.format(DateTimeFormatter.ofPattern("d MMM yyyy, h:mm a", Locale.ENGLISH))
        val people = segments.map { it.speaker }.distinct().map { label ->
            com.echoledger.mobile.ui.speakerName(speakerNames, label).let { if (it == "Me") "You" else it }
        }.ifEmpty { listOfNotNull("You", item.client?.name) }
        val language = item.transcript?.finalLanguage?.let(Languages::label) ?: item.languageChip
        return listOfNotNull(
            title?.let { "Title: $it" },
            date?.let { "Date: $it" },
            people.takeIf { it.isNotEmpty() }?.let { "People: ${it.joinToString(", ")}" },
            language?.takeIf { it.isNotBlank() }?.let { "Language: $it" }
        ).joinToString("\n")
    }
}

/** Copy ▾: Copy text, Copy with timestamps, Copy without timestamps, Copy for AI (with what its header holds). */
@Composable
fun CopyMenuSheet(onCopy: (CopyKind) -> Unit, onDismiss: () -> Unit) {
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_rec_copy)) {
        CopyMenuContent(onCopy = {
            onDismiss()
            onCopy(it)
        })
    }
}

/** The Copy menu without the sheet (screenshots and tests). */
@Composable
fun CopyMenuContent(onCopy: (CopyKind) -> Unit) {
    Column(Modifier.fillMaxWidth()) {
        MenuRow(RecordingIcons.Copy, stringResource(R.string.v2_rec_copy_text), { onCopy(CopyKind.Text) })
        MenuRow(null, stringResource(R.string.v2_rec_copy_with), { onCopy(CopyKind.WithTimestamps) })
        MenuRow(null, stringResource(R.string.v2_rec_copy_without), { onCopy(CopyKind.WithoutTimestamps) })
        MenuRow(null, stringResource(R.string.v2_rec_copy_ai), { onCopy(CopyKind.ForAi) })
        Text(
            stringResource(R.string.v2_rec_copy_ai_help),
            style = EchoV2.type.meta,
            color = EchoV2.colors.textSecondary,
            modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
        )
    }
}

/** The toast after a copy, in the screen's words. */
@Composable
fun copiedWords(kind: CopyKind): String = stringResource(
    when (kind) {
        CopyKind.WithTimestamps -> R.string.v2_rec_copied_with
        CopyKind.ForAi -> R.string.v2_rec_copied_ai
        else -> R.string.v2_rec_copied
    }
)

/**
 * Share: the transcript's text to any app; Share link (who may open it, downloads, how long: classic's sheet); Send to a
 * teammate (classic's sheet); and, once the transcript is ready, Send to WhatsApp (or WhatsApp Business): Text only,
 * Text and voice (when the recording has audio), or a Link that works for 7 days. The logic is classic's.
 */
@Composable
fun RecordingShareSheet(
    vm: MainViewModel,
    item: LibraryItem,
    shareText: () -> String,
    transcriptId: String?,
    hasAudio: Boolean,
    view: String?,
    onDismiss: () -> Unit
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var linkSheet by remember { mutableStateOf(false) }
    var sendSheet by remember { mutableStateOf(false) }
    if (linkSheet) {
        ShareSheet(vm, sessionId = item.id, clientId = null, title = "this recording", onDismiss = onDismiss)
        return
    }
    if (sendSheet) {
        SendSheet(vm, sessionId = item.id, clientId = null, title = "this recording", onDismiss = onDismiss)
        return
    }
    val target = remember { WhatsAppShare.target(WhatsAppShare.installed(context)) }
    val copiedNote = stringResource(R.string.v2_rec_wa_text_copied)
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_rec_share)) {
        Column(Modifier.fillMaxWidth()) {
            MenuRow(EchoIcons.Share, stringResource(R.string.v2_rec_share_text), {
                onDismiss()
                shareTranscript(context, item.title.orEmpty(), shareText())
            })
            MenuRow(RecordingIcons.Link, stringResource(R.string.v2_rec_share_link), { linkSheet = true })
            MenuRow(RecordingIcons.Send, stringResource(R.string.v2_rec_send), { sendSheet = true })
            if (transcriptId != null) {
                EchoSectionHeader(
                    stringResource(if (target == WhatsAppShare.BUSINESS) R.string.v2_rec_whatsapp_business else R.string.v2_rec_whatsapp)
                )
                suspend fun transcriptText(): String? = vm.export(transcriptId, "txt", view)?.readText()
                MenuRow(EchoIcons.WhatsApp, stringResource(R.string.v2_rec_wa_text), {
                    onDismiss()
                    scope.launch { transcriptText()?.let { WhatsAppShare.start(context, WhatsAppShare.textIntent(it, target)) } }
                })
                if (hasAudio) {
                    MenuRow(EchoIcons.VoiceNote, stringResource(R.string.v2_rec_wa_voice), {
                        onDismiss()
                        scope.launch {
                            val text = transcriptText() ?: return@launch
                            val audio = vm.downloadAudio(item.id, "EchoLedger recording") ?: return@launch
                            val uri = androidx.core.content.FileProvider.getUriForFile(context, "${context.packageName}.exports", audio)
                            // WhatsApp may drop text sent with audio: it is on the clipboard too.
                            copyToClipboard(context, "Transcript", WhatsAppShare.clip(text))
                            vm.showMessage(copiedNote)
                            WhatsAppShare.start(context, WhatsAppShare.voiceIntent(uri, text, target))
                        }
                    })
                }
                MenuRow(RecordingIcons.Link, stringResource(R.string.v2_rec_wa_link), {
                    onDismiss()
                    scope.launch {
                        val link = vm.createShare(item.id, 7L * 24 * 3600, null, includeAudio = hasAudio)?.url ?: return@launch
                        WhatsAppShare.start(context, WhatsAppShare.textIntent(link, target))
                    }
                })
            }
        }
    }
}

/** The transcript's text to any app through Android's share sheet. */
internal fun shareTranscript(context: Context, title: String, text: String) {
    val share = Intent(Intent.ACTION_SEND).setType("text/plain")
        .putExtra(Intent.EXTRA_SUBJECT, title)
        .putExtra(Intent.EXTRA_TEXT, text.ifBlank { title })
    context.startActivity(Intent.createChooser(share, null).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
}

/** A call's source in a sentence ("WhatsApp"), for "Back to WhatsApp". */
fun sourceLabel(item: LibraryItem): String = item.sourceAppLabel ?: SourceApp.from(item.sourceApp).label
