package com.echoledger.mobile.ui.v2.live

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.R
import com.echoledger.mobile.core.RowProgress
import com.echoledger.mobile.core.SharedNotes
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.SharedNoteView
import com.echoledger.mobile.ui.rememberSharedNotes
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoListRow
import com.echoledger.mobile.ui.v2.components.EchoProgressRing
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.library.V2RecordingScreen
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** What a shared note's line says, in the screen's language: "Uploading… 40%", "Transcribing…", "Transcript ready". */
@Composable
internal fun sharedNoteWords(note: SharedNoteView): String {
    val res = LocalContext.current.resources
    return when (note.status.stage) {
        SharedNotes.Stage.WAITING -> res.getString(R.string.v2_shared_preparing)

        SharedNotes.Stage.UPLOADING -> note.upload?.let { (sent, total) -> RowProgress.uploading(sent, total).percent }
            ?.let { res.getString(R.string.v2_shared_uploading_percent, it) }
            ?: res.getString(if (note.local?.state == "uploading") R.string.v2_shared_uploading else R.string.v2_shared_waiting)

        SharedNotes.Stage.TRANSCRIBING -> RowProgress.transcribing(note.item?.transcript?.finalProgress).percent
            ?.let { res.getString(R.string.v2_shared_transcribing_percent, it) }
            ?: res.getString(R.string.v2_shared_transcribing)

        SharedNotes.Stage.READY -> res.getString(R.string.v2_shared_ready)

        SharedNotes.Stage.NO_AUDIO -> res.getString(R.string.v2_shared_no_audio)

        SharedNotes.Stage.FAILED -> res.getString(if (note.item != null) R.string.v2_shared_failed else R.string.v2_shared_upload_failed)
    }
}

/**
 * The voice notes just shared to EchoLedger from another app, on the new components (classic's SharedNotesScreen, the
 * same rules): each one's stage live (preparing, uploading, transcribing, ready); one note opens straight on its
 * recording page once the transcript is ready, with ⋮ › Back to <app> and Copy and Copy for AI there; several are
 * listed and each opens its page. Continue in background goes back to the app and a notification follows the notes;
 * once all are done it says Back to <app>. Back goes to the Library ([onLeave]).
 */
@Composable
fun V2SharedNotesScreen(
    vm: MainViewModel,
    localIds: List<String>,
    onLeave: () -> Unit,
    sourceApp: String? = null,
    onBackToSource: () -> Unit = {}
) {
    val context = LocalContext.current
    val c = EchoV2.colors
    val notes = rememberSharedNotes(vm, localIds)
    val allDone = notes.all { SharedNotes.done(it.status.stage) }
    val app = sourceApp ?: stringResource(R.string.v2_shared_the_app)
    var opened by rememberSaveable { mutableStateOf<String?>(null) }
    val single = notes.singleOrNull()?.takeIf { it.status.stage == SharedNotes.Stage.READY }?.status?.sessionId
    val showing = (opened ?: single)?.let { s -> notes.firstOrNull { it.item?.id == s }?.item }
    if (showing != null) {
        // One note goes back to the app it came from; from a list, Back returns to the list.
        val back = { if (opened != null && localIds.size > 1) opened = null else onLeave() }
        BackHandler(onBack = back)
        V2RecordingScreen(
            vm,
            showing,
            onBack = back,
            backToSource = app to onBackToSource
        )
        return
    }
    BackHandler { onLeave() }
    Column(Modifier.fillMaxSize()) {
        EchoTopBar(
            if (localIds.size == 1) stringResource(R.string.v2_shared_one) else stringResource(R.string.v2_shared_many, localIds.size),
            onBack = onLeave
        ) {
            EchoButton(
                if (allDone) stringResource(R.string.v2_shared_back_to, app) else stringResource(R.string.v2_shared_continue),
                {
                    if (!allDone) com.echoledger.mobile.share.SharedNotesWorker.enqueue(context, localIds)
                    onBackToSource()
                },
                kind = EchoButtonKind.Quiet
            )
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            notes.forEachIndexed { i, note ->
                val words = sharedNoteWords(note)
                val canOpen = note.status.stage != SharedNotes.Stage.WAITING && note.item != null
                EchoListRow(
                    title = if (localIds.size ==
                        1
                    ) {
                        stringResource(R.string.v2_shared_note)
                    } else {
                        stringResource(R.string.v2_shared_note_n, i + 1)
                    },
                    meta = words,
                    leading = { EchoAvatar(icon = EchoIcons.VoiceNote) },
                    status = if (!SharedNotes.done(note.status.stage)) ({ EchoProgressRing(null) }) else null,
                    onClick = if (canOpen) ({ opened = note.item?.id }) else null
                )
                // TalkBack hears the new stage when it changes ("Transcribing…"), not the whole row again.
                val view = LocalView.current
                var said by remember { mutableStateOf(words) }
                LaunchedEffect(words) {
                    if (words != said) {
                        said = words
                        @Suppress("DEPRECATION")
                        view.announceForAccessibility(words)
                    }
                }
            }
            if (localIds.size == 1 && !allDone) {
                Text(
                    stringResource(R.string.v2_shared_opens_here),
                    style = EchoV2.type.meta,
                    color = c.textSecondary,
                    modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
                )
            }
        }
    }
}
