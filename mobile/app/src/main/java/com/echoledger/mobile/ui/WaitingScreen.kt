package com.echoledger.mobile.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.ui.design.BarIcon
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.EchoEmptyState
import com.echoledger.mobile.ui.design.EchoTopBar
import com.echoledger.mobile.ui.design.UndoSnackbar
import com.echoledger.mobile.ui.design.tabular
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.delay

/** Calls waiting for Keep or Discard, summed up in one line (since 1.3.2: one card instead of a row per call). */
object WaitingSummary {
    /** "4 calls waiting · oldest Oct 30 · 2:18 total" ("1 call waiting · Oct 30 · 0:36"). */
    fun line(waiting: List<LocalRecording>, zone: ZoneId = ZoneId.systemDefault()): String {
        if (waiting.isEmpty()) return ""
        val oldest = waiting.mapNotNull { runCatching { Instant.parse(it.startedAt) }.getOrNull() }.minOrNull()
        val day = oldest?.atZone(zone)?.format(DateTimeFormatter.ofPattern("MMM d", Locale.ENGLISH))
        val total = formatDuration(waiting.sumOf { it.durationMs }).ifEmpty { "0:00" }
        val count = if (waiting.size == 1) "1 call waiting" else "${waiting.size} calls waiting"
        return listOfNotNull(count, day?.let { if (waiting.size == 1) it else "oldest $it" }, "$total total").joinToString(" · ")
    }
}

/** The Library's and the Calls view's one card for every call waiting for a decision, with Review. */
@Composable
fun WaitingSummaryCard(waiting: List<LocalRecording>, onReview: () -> Unit, modifier: Modifier = Modifier) {
    if (waiting.isEmpty()) return
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    Row(
        modifier.fillMaxWidth().padding(horizontal = Space.screen, vertical = Space.s2).clip(RoundedCornerShape(Radius.md))
            .background(c.accentSoft).clickable(role = Role.Button, onClickLabel = "Review the calls waiting", onClick = onReview)
            .padding(start = Space.s4, top = Space.s3, bottom = Space.s3, end = Space.s2),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon(Icons.Rounded.HourglassTop, contentDescription = null, tint = c.accentText, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(Space.s3))
        Column(Modifier.weight(1f)) {
            Text("Keep or discard?", style = type.bodyL, color = c.text)
            Text(WaitingSummary.line(waiting), style = type.labelM.tabular(), color = c.textMuted, maxLines = 2)
        }
        EchoButton("Review", onReview, kind = ButtonKind.Text)
    }
}

/**
 * Waiting for you (since 1.3.2): every call waiting for Keep or Discard, each with its title, client and language, and
 * Keep all / Discard all for the lot. Discard all can be undone for 5 seconds before anything is deleted.
 */
@Composable
fun WaitingScreen(vm: MainViewModel, onBack: () -> Unit) {
    val pending by vm.pending.collectAsState()
    val clients by vm.clients.collectAsState()
    val learn by vm.learn.collectAsState()
    val waiting = pending.filter { it.state == "pending_decision" }
    var discarding by remember { mutableStateOf<List<String>>(emptyList()) }
    LaunchedEffect(Unit) { vm.reloadLocal() }
    LaunchedEffect(discarding) {
        if (discarding.isEmpty()) return@LaunchedEffect
        delay(UNDO_MS)
        discarding.forEach { vm.decide(it, keep = false) }
        discarding = emptyList()
    }
    val shown = waiting.filterNot { it.id in discarding }
    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            EchoTopBar(
                "Waiting for you",
                large = false,
                navigation = { BarIcon(Icons.AutoMirrored.Rounded.ArrowBack, "Back", onBack, tint = LocalEchoColors.current.text) }
            )
            if (shown.isEmpty()) {
                EchoEmptyState(Icons.Rounded.HourglassTop, "Nothing waiting", body = "Calls you have not kept or discarded appear here.")
                return@Column
            }
            Text(
                WaitingSummary.line(shown),
                style = LocalEchoType.current.labelM.tabular(),
                color = LocalEchoColors.current.textMuted,
                modifier = Modifier.padding(horizontal = Space.screen)
            )
            Row(Modifier.padding(horizontal = Space.screen, vertical = Space.s3), horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                EchoButton("Keep all", { shown.forEach { vm.decide(it.id, keep = true, title = it.title) } })
                EchoButton("Discard all", { discarding = shown.map { it.id } }, kind = ButtonKind.Secondary)
            }
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(bottom = 96.dp)) {
                shown.forEach { rec ->
                    KeepDiscardCard(
                        whenText = formatWhen(rec.startedAt),
                        title = Notifications.decisionText(rec, null),
                        app = SourceApp.from(rec.sourceApp),
                        language = rec.language,
                        onLanguage = { vm.setPendingLanguage(rec.id, it) },
                        onKeep = { title -> vm.decide(rec.id, keep = true, title = title) },
                        onDiscard = { discarding = discarding + rec.id },
                        question = learn?.takeIf { it.recordingId == rec.id }?.question,
                        onAnswer = { vm.answerLearn(it) },
                        typedTitle = rec.title,
                        onTitle = { vm.setPendingTitle(rec.id, it) },
                        clients = clients,
                        clientId = rec.clientId,
                        onClient = { vm.setPendingClient(rec.id, it) }
                    )
                }
            }
        }
        if (discarding.isNotEmpty()) {
            UndoSnackbar(
                if (discarding.size == 1) "Discarding 1 call" else "Discarding ${discarding.size} calls",
                onUndo = { discarding = emptyList() },
                modifier = Modifier.align(Alignment.BottomCenter).padding(Space.screen).padding(bottom = 80.dp)
            )
        }
    }
}

private const val UNDO_MS = 5_000L
