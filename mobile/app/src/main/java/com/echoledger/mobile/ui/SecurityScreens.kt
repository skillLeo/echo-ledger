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
