package com.echoledger.mobile.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
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
import kotlinx.coroutines.delay

/** How often the page asks the server how far the shared notes are (only while one is not done). */
private const val SHARED_POLL_MS = 3_000L

/**
 * The voice notes just shared to EchoLedger from another app (since v1.3): their status live (uploading, transcribing,
 * ready), then the note itself in its default view with Copy and Copy for AI. One note opens straight on its page once
 * the transcript is ready; several are listed. Back leaves for the app the note was shared from ([onLeave]).
 */
@Composable
internal fun SharedNotesScreen(vm: MainViewModel, localIds: List<String>, onLeave: () -> Unit) {
    val context = LocalContext.current
    val pending by vm.pending.collectAsState()
    val library by vm.library.collectAsState()
    val statuses = localIds.map { id ->
        val session = SharedNotes.sessionFor(context, id)
        SharedNotes.status(
            pending.firstOrNull {
                it.id == id
            },
            session,
            session?.let { s -> library.firstOrNull { it.id == s && !it.deleted } }
        )
    }
    val allDone = statuses.all { SharedNotes.done(it.stage) }
    LaunchedEffect(localIds, allDone) {
        while (!allDone) {
            vm.refresh()
            delay(SHARED_POLL_MS)
        }
    }

    var opened by rememberSaveable { mutableStateOf<String?>(null) }
    val single = statuses.singleOrNull()?.takeIf { it.stage == SharedNotes.Stage.READY }?.sessionId
    val showing = opened ?: single
    val item = showing?.let { s -> library.firstOrNull { it.id == s } }
    if (item != null) {
        // One note goes back to the app it came from; from a list, back returns to the list.
        SessionScreen(vm, item, onBack = { if (opened != null && localIds.size > 1) opened = null else onLeave() })
        return
    }

    BackHandler { onLeave() }
    Column(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onLeave) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Back") }
            Text(if (localIds.size == 1) "Voice note" else "${localIds.size} voice notes", fontSize = 22.sp, fontWeight = FontWeight.Bold)
        }
        statuses.forEachIndexed { i, status ->
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
            Text("The transcript opens here as soon as it is ready.", color = Brand.Muted, fontSize = 12.sp)
        }
    }
}
