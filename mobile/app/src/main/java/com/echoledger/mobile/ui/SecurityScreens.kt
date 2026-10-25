package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
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
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.data.SecurityEventDto
import com.echoledger.mobile.data.SecurityResponse
import kotlinx.coroutines.launch

/** "Recording deleted: Acme kickoff · Work laptop": what happened and who did it. */
fun eventLine(e: SecurityEventDto): String {
    val who = when (e.actor) {
        "device" -> e.device ?: "A device"
        "web" -> "Console"
        else -> "EchoLedger"
    }
    return "${e.summary.ifBlank { e.label }} · $who"
}

/** Settings → Devices and activity (B16): the paired devices (revoke the others) and the recent audit log. */
@Composable
fun SecuritySection(load: suspend () -> SecurityResponse?, revoke: suspend (String) -> Boolean) {
    var overview by remember { mutableStateOf<SecurityResponse?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var confirming by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    LaunchedEffect(Unit) {
        overview = load()
        loaded = true
    }
    SectionCard {
        Text("Devices and activity", fontWeight = FontWeight.SemiBold)
        val current = overview
        when {
            !loaded -> Text("Loading…", color = Brand.Muted, fontSize = 12.sp)

            current == null -> Text("Connect to see your devices and recent activity.", color = Brand.Muted, fontSize = 12.sp)

            else -> {
                current.devices.forEach { d ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(d.name + if (d.current) " · this phone" else "")
                            Text(
                                listOfNotNull(
                                    d.platform,
                                    d.lastSeenAt?.let {
                                        "seen ${formatWhen(it)}"
                                    }
                                ).joinToString(" · "),
                                color = Brand.Muted,
                                fontSize = 12.sp
                            )
                        }
                        if (!d.current) {
                            if (confirming == d.id) {
                                TextButton(onClick = {
                                    confirming = null
                                    scope.launch { if (revoke(d.id)) overview = load() }
                                }) { Text("Revoke now", color = Brand.Danger) }
                            } else {
                                TextButton(
                                    onClick = { confirming = d.id },
                                    modifier = Modifier.semantics {
                                        contentDescription =
                                            "Revoke ${d.name}"
                                    }
                                ) {
                                    Text("Revoke")
                                }
                            }
                        }
                    }
                }
                Text("Recent activity", color = Brand.Muted, fontSize = 12.sp)
                if (current.events.isEmpty()) Text("Nothing recorded yet.", color = Brand.Muted, fontSize = 12.sp)
                current.events.take(15).forEach { e ->
                    Column {
                        Text(eventLine(e), fontSize = 13.sp)
                        Text(formatWhen(e.at), color = Brand.Muted, fontSize = 11.sp)
                    }
                }
            }
        }
    }
}

/** "1.5 GB" style sizes. */
fun fileSize(bytes: Long): String {
    val units = listOf("B", "KB", "MB", "GB", "TB")
    var value = bytes.toDouble()
    var unit = 0
    while (value >= 1024 && unit < units.lastIndex) {
        value /= 1024
        unit++
    }
    return if (unit == 0) "$bytes B" else String.format(java.util.Locale.ROOT, "%.1f %s", value, units[unit])
}

/** Settings → Storage and retention (B17): the storage meter and how long recordings are kept. */
@Composable
fun StorageSection(load: suspend () -> com.echoledger.mobile.data.StorageDto?) {
    var info by remember { mutableStateOf<com.echoledger.mobile.data.StorageDto?>(null) }
    var loaded by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        info = load()
        loaded = true
    }
    SectionCard {
        Text("Storage and retention", fontWeight = FontWeight.SemiBold)
        val current = info
        when {
            !loaded -> Text("Loading…", color = Brand.Muted, fontSize = 12.sp)

            current == null -> Text("Connect to see the server's storage.", color = Brand.Muted, fontSize = 12.sp)

            else -> {
                Text(
                    "Recordings on the server: ${fileSize(
                        current.recordingsBytes
                    )} in ${current.recordingsCount} file${if (current.recordingsCount == 1) "" else "s"}.",
                    fontSize = 13.sp
                )
                val total = current.diskTotalBytes
                val free = current.diskFreeBytes
                if (total != null && free != null && total > 0) {
                    val used = 1f - free.toFloat() / total
                    androidx.compose.material3.LinearProgressIndicator(
                        progress = { used },
                        modifier = Modifier.semantics { contentDescription = "Server disk used" }
                    )
                    Text("Server disk ${(used * 100).toInt()}% used · ${fileSize(free)} free", color = Brand.Muted, fontSize = 12.sp)
                }
                Text(
                    "Recordings are deleted after ${current.retentionDays} days unless kept. Deleted recordings can be restored for 48 hours.",
                    color = Brand.Muted,
                    fontSize = 12.sp
                )
            }
        }
    }
}
