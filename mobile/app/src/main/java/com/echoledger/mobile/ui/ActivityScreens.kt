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
