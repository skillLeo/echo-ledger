package com.echoledger.mobile.ui

import android.content.Intent
import android.widget.Toast
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
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.core.Activity
import com.echoledger.mobile.core.NotifyCategory
import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.notify.Notifications
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
        NotificationKinds(settings, update)
    }
}

/**
 * Each kind of notification on its own (since v1.6, Phase 1C B2 and B4): a switch, Test, and Android's own page for its
 * sound and vibration. A kind switched off in Android's settings says so, with the way back. Recording has no switch:
 * Android shows it while a recording runs. Call capture & readiness comes through even in quiet hours.
 */
@Composable
private fun NotificationKinds(settings: AppSettings, update: ((AppSettings) -> AppSettings) -> Unit) {
    val context = LocalContext.current
    // Android's channel switches change outside the app: read them again whenever the person comes back.
    var looked by remember { mutableIntStateOf(0) }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { looked++ }
    fun open(intent: Intent) = runCatching { context.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
    Text("Kinds of notification", fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 8.dp))
    NotifyCategory.entries.forEach { category ->
        val on = category.key !in settings.notifyOff
        val androidOn = remember(looked) { Notifications.channelOn(context, category) }
        Column(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(category.title)
                    Text(category.description, color = Brand.Muted, fontSize = 12.sp)
                }
                if (category == NotifyCategory.RECORDING) {
                    Text("Always on", color = Brand.Muted, fontSize = 12.sp)
                } else {
                    Switch(
                        checked = on,
                        onCheckedChange = { v ->
                            update { s ->
                                s.copy(notifyOff = if (v) s.notifyOff - category.key else (s.notifyOff + category.key).distinct())
                            }
                        },
                        modifier = Modifier.semantics { contentDescription = category.title }
                    )
                }
            }
            if (on && !androidOn) {
                Text("Off in Android's settings, so none of these show.", color = Brand.Warning, fontSize = 12.sp)
            }
            if (category.critical && !on) {
                Text("You will not hear when calls stop being recorded.", color = Brand.Warning, fontSize = 12.sp)
            }
            Row {
                TextButton(onClick = {
                    if (!Notifications.test(context, category)) {
                        Toast.makeText(context, "Allow notifications for EchoLedger first.", Toast.LENGTH_SHORT).show()
                    }
                }, modifier = Modifier.semantics { contentDescription = "Test ${category.title}" }) { Text("Test") }
                TextButton(
                    onClick = { open(Notifications.channelSettings(context, category)) },
                    modifier = Modifier.semantics { contentDescription = "Sound and vibration for ${category.title}" }
                ) { Text(if (on && !androidOn) "Turn on in Android" else "Sound & vibration") }
            }
        }
    }
}
