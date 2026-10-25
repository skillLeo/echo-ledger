package com.echoledger.mobile.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
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
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.Activity
import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.data.AppSettings
import kotlinx.coroutines.launch

/** The activity list (B18): tapping an item marks it read and opens its recording. */
@Composable
fun ActivityPanel(
    load: suspend () -> ActivityResponse?,
    markRead: suspend (List<String>?) -> Int?,
    onOpen: (String) -> Unit,
    onUnread: (Int) -> Unit,
    onClose: () -> Unit
) {
    var items by remember { mutableStateOf<List<ActivityItemDto>?>(null) }
    var unread by remember { mutableStateOf(0) }
    val scope = rememberCoroutineScope()
    LaunchedEffect(Unit) {
        val answer = load()
        items = answer?.data.orEmpty()
        unread = answer?.unreadCount ?: 0
        onUnread(unread)
    }
    SectionCard(Modifier.fillMaxWidth().semantics { contentDescription = "Activity" }) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Activity", fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            if (unread > 0) {
                TextButton(onClick = {
                    scope.launch {
                        markRead(null)?.let {
                            unread = it
                            onUnread(it)
                            items = items?.map { i -> i.copy(read = true) }
                        }
                    }
                }) { Text("Mark all read") }
            }
            TextButton(onClick = onClose) { Text("Close") }
        }
        val list = items
        when {
            list == null -> Text("Loading…", color = Brand.Muted, fontSize = 12.sp)

            list.isEmpty() -> Text(
                "Nothing yet. Finished transcripts, failed uploads and new devices show up here.",
                color = Brand.Muted,
                fontSize = 12.sp
            )

            else -> list.forEach { item ->
                Column(
                    Modifier.fillMaxWidth().clickable {
                        scope.launch {
                            if (!item.read) {
                                markRead(listOf(item.id))?.let {
                                    unread = it
                                    onUnread(it)
                                }
                                items = items?.map { i -> if (i.id == item.id) i.copy(read = true) else i }
                            }
                            item.sessionId?.let(onOpen)
                        }
                    }.padding(vertical = 6.dp)
                ) {
                    Text(item.title, fontWeight = if (item.read) FontWeight.Normal else FontWeight.SemiBold)
                    Text(item.body, color = Brand.Muted, fontSize = 12.sp)
                    item.at?.let { Text(formatWhen(it), color = Brand.Muted, fontSize = 11.sp) }
                }
            }
        }
    }
}

/** Settings → Notifications (B18): notifications for new activity, and quiet hours. */
@Composable
fun NotificationsSection(settings: AppSettings, update: ((AppSettings) -> AppSettings) -> Unit) {
    var from by remember(settings.quietFrom) { mutableStateOf(settings.quietFrom) }
    var to by remember(settings.quietTo) { mutableStateOf(settings.quietTo) }
    SectionCard {
        Text("Notifications", fontWeight = FontWeight.SemiBold)
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Show notifications")
                Text("Transcript ready, upload failed, a new device or the monthly limit.", color = Brand.Muted, fontSize = 12.sp)
            }
            Switch(
                checked = settings.activityNotifications,
                onCheckedChange = { v -> update { it.copy(activityNotifications = v) } },
                modifier = Modifier.semantics { contentDescription = "Show notifications" }
            )
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Quiet hours")
                Text("No notifications in this time.", color = Brand.Muted, fontSize = 12.sp)
            }
            Switch(
                checked = settings.quietHours,
                onCheckedChange = { v ->
                    update { it.copy(quietHours = v) }
                },
                modifier = Modifier.semantics {
                    contentDescription =
                        "Quiet hours"
                }
            )
        }
        if (settings.quietHours) {
            Row {
                OutlinedTextField(from, {
                    from = it.take(5)
                    if (Activity.parseHhmm(from) != null) update { s -> s.copy(quietFrom = from) }
                }, label = { Text("From") }, isError = Activity.parseHhmm(from) == null, singleLine = true, modifier = Modifier.weight(1f))
                OutlinedTextField(to, {
                    to = it.take(5)
                    if (Activity.parseHhmm(to) != null) update { s -> s.copy(quietTo = to) }
                }, label = { Text("To") }, isError = Activity.parseHhmm(to) == null, singleLine = true, modifier = Modifier.weight(1f))
            }
        }
    }
}
