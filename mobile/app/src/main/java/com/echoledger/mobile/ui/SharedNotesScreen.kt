package com.echoledger.mobile.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.SharedNotes
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.ui.design.TextButton
import kotlinx.coroutines.delay

/** How often the page asks the server how far the shared notes are (only while one is not done). */
private const val SHARED_POLL_MS = 3_000L

/** One shared note as the page shows it: its [status], and what it was worked out from (its local copy and upload). */
internal data class SharedNoteView(
    val status: SharedNotes.Status,
    val local: com.echoledger.mobile.data.LocalRecording?,
    val upload: Pair<Int, Int>?,
    val item: LibraryItem?
)

/**
 * The shared notes' stages, live: the local copy while it uploads, then its server recording; the server is asked
 * again every few seconds until all are done. Both designs' pages read them from here.
 */
@Composable
internal fun rememberSharedNotes(vm: MainViewModel, localIds: List<String>): List<SharedNoteView> {
    val context = LocalContext.current
    val pending by vm.pending.collectAsState()
    val library by vm.library.collectAsState()
    val sending by com.echoledger.mobile.core.RowProgress.uploads.collectAsState()
    val views = localIds.map { id ->
        val session = SharedNotes.sessionFor(context, id)
        val local = pending.firstOrNull { it.id == id }
        val item = session?.let { s -> library.firstOrNull { it.id == s && !it.deleted } }
        SharedNoteView(SharedNotes.status(local, session, item, sending[id]), local, sending[id], item)
    }
    val allDone = views.all { SharedNotes.done(it.status.stage) }
    LaunchedEffect(localIds, allDone) {
        while (!allDone) {
            vm.refresh()
            delay(SHARED_POLL_MS)
        }
    }
    return views
}

/**
 * The voice notes just shared to EchoLedger from another app (since v1.3): their status live (uploading, transcribing,
 * ready), then the note itself in its default view with Copy and Copy for AI. One note opens straight on its page once
 * the transcript is ready; several are listed. Since v1.5 the arrow and Back go to the Library ([onLeave]); "Back to
 * WhatsApp" ([onBackToSource]) returns to the app the note came from.
 */
@Composable
internal fun SharedNotesScreen(
    vm: MainViewModel,
    localIds: List<String>,
    onLeave: () -> Unit,
    sourceApp: String? = null,
    onBackToSource: () -> Unit = {},
    /** The note's page: classic's, or the new design's ([backToSource] is its "Back to WhatsApp"). */
    page: @Composable (
        item: LibraryItem,
        onBack: () -> Unit,
        backToSource: Pair<String, () -> Unit>
    ) -> Unit = { item, onBack, backToSource ->
        SessionScreen(vm, item, onBack = onBack, backToSource = backToSource)
    }
) {
    val context = LocalContext.current
    val library by vm.library.collectAsState()
    val statuses = rememberSharedNotes(vm, localIds).map { it.status }
    val allDone = statuses.all { SharedNotes.done(it.stage) }

    var opened by rememberSaveable { mutableStateOf<String?>(null) }
    val single = statuses.singleOrNull()?.takeIf { it.stage == SharedNotes.Stage.READY }?.sessionId
    val showing = opened ?: single
    val item = showing?.let { s -> library.firstOrNull { it.id == s } }
    if (item != null) {
        // One note goes back to the app it came from; from a list, back returns to the list.
        page(item, { if (opened != null && localIds.size > 1) opened = null else onLeave() }, (sourceApp ?: "the app") to onBackToSource)
        return
    }

    BackHandler { onLeave() }
    // Since v1.8.1 (bug P-013): the notes scroll under the top bar, so with many notes or large text none is out of reach.
    Column(Modifier.fillMaxSize()) {
        com.echoledger.mobile.ui.design.EchoTopBar(
            if (localIds.size == 1) "Voice note" else "${localIds.size} voice notes",
            large = false,
            navigation = {
                IconButton(onClick = onLeave) { Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Back") }
            },
            actions = {
                // Since v1.6 (P5.4): while it is still being made, Continue in background goes back and a notification follows it.
                TextButton(onClick = {
                    if (!allDone) com.echoledger.mobile.share.SharedNotesWorker.enqueue(context, localIds)
                    onBackToSource()
                }) { Text(if (allDone) "Back to ${sourceApp ?: "the app"}" else "Continue in background") }
            }
        )
        LazyColumn(
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            itemsIndexed(statuses) { i, status ->
                SectionCard {
                    Row(
                        Modifier.fillMaxWidth().clickable(enabled = status.stage != SharedNotes.Stage.WAITING && status.sessionId != null) {
                            opened = status.sessionId
                        },
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        if (!SharedNotes.done(status.stage)) {
                            CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                            Spacer(Modifier.width(12.dp))
                        }
                        Column(Modifier.weight(1f)) {
                            Text(if (localIds.size == 1) "Shared voice note" else "Voice note ${i + 1}", fontWeight = FontWeight.SemiBold)
                            Text(
                                status.text,
                                color = if (status.stage == SharedNotes.Stage.FAILED) Brand.Danger else Brand.Muted,
                                fontSize = 13.sp,
                                modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite }
                            )
                        }
                    }
                }
            }
            if (localIds.size == 1 && !allDone) {
                item { Text("The transcript opens here as soon as it is ready.", color = Brand.Muted, fontSize = 12.sp) }
            }
        }
    }
}
