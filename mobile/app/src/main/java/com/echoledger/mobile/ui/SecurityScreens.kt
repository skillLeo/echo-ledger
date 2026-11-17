package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.material3.Text
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
import com.echoledger.mobile.ui.design.TextButton
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
fun SecuritySection(
    load: suspend () -> SecurityResponse?,
    revoke: suspend (String) -> Boolean,
    rename: suspend (String, String) -> Boolean = { _, _ -> false }
) {
    var overview by remember { mutableStateOf<SecurityResponse?>(null) }
    // Since v1.6 (P3): the device being renamed and the name typed.
    var renaming by remember { mutableStateOf<String?>(null) }
    var newName by remember { mutableStateOf("") }
    var loaded by remember { mutableStateOf(false) }
    var confirming by remember { mutableStateOf<String?>(null) }
    var attempt by remember { mutableStateOf(0) }
    val scope = rememberCoroutineScope()
    LaunchedEffect(attempt) {
        overview = load()
        loaded = true
    }
    SectionCard {
        Text("Devices and activity", fontWeight = FontWeight.SemiBold)
        val current = overview
        when {
            !loaded -> Text("Loading…", color = Brand.Muted, fontSize = 12.sp)

            current == null -> RetryBanner("Couldn't load your devices. Check the connection.", retrying = false, onRetry = {
                loaded = false
                attempt++
            })

            else -> {
                current.devices.forEach { d ->
                    if (renaming == d.id) {
                        // Since v1.6 (P3): renamed in place.
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            com.echoledger.mobile.ui.design.OutlinedTextField(
                                newName,
                                { newName = it.take(60) },
                                label = { Text("Name") },
                                singleLine = true,
                                modifier = Modifier.weight(1f)
                            )
                            TextButton(onClick = {
                                renaming = null
                                scope.launch { if (rename(d.id, newName)) overview = load() }
                            }, enabled = newName.isNotBlank()) { Text("Save") }
                            TextButton(onClick = { renaming = null }) { Text("Cancel") }
                        }
                        return@forEach
                    }
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
                        TextButton(onClick = {
                            renaming = d.id
                            newName = d.name
                        }) { Text("Rename") }
                        if (!d.current) {
                            if (confirming == d.id) {
                                TextButton(onClick = {
                                    confirming = null
                                    scope.launch { if (revoke(d.id)) overview = load() }
                                }) { Text("Sign out now", color = Brand.Danger) }
                            } else {
                                TextButton(
                                    onClick = { confirming = d.id },
                                    modifier = Modifier.semantics {
                                        contentDescription =
                                            "Sign out ${d.name}"
                                    }
                                ) {
                                    Text("Sign out")
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

/**
 * Settings → Storage and retention (B17): this person's recordings, the plan's storage meter and how long recordings
 * are kept. Since v1.8.1 (bug P-023) never the server's own disk; [keepHours] is the server's Trash time (2.0.0-b).
 */
@Composable
fun StorageSection(
    load: suspend () -> com.echoledger.mobile.data.StorageDto?,
    planStorage: com.echoledger.mobile.data.LimitDto? = null,
    keepHours: Int = com.echoledger.mobile.data.TrashConfig.DEFAULT_HOURS
) {
    var info by remember { mutableStateOf<com.echoledger.mobile.data.StorageDto?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var attempt by remember { mutableStateOf(0) }
    LaunchedEffect(attempt) {
        info = load()
        loaded = true
    }
    SectionCard {
        Text("Storage and retention", fontWeight = FontWeight.SemiBold)
        val current = info
        when {
            !loaded -> Text("Loading…", color = Brand.Muted, fontSize = 12.sp)

            current == null -> RetryBanner("Couldn't load the server's storage. Check the connection.", retrying = false, onRetry = {
                loaded = false
                attempt++
            })

            else -> {
                Text(
                    "Recordings on the server: ${fileSize(
                        current.recordingsBytes
                    )} in ${current.recordingsCount} file${if (current.recordingsCount == 1) "" else "s"}.",
                    fontSize = 13.sp
                )
                val limit = planStorage?.limit
                if (planStorage != null && limit != null) {
                    com.echoledger.mobile.core.PlanFormat.fraction(planStorage.used, limit)?.let { used ->
                        androidx.compose.material3.LinearProgressIndicator(
                            progress = { used },
                            modifier = Modifier.semantics { contentDescription = "Plan storage used" }
                        )
                    }
                    Text(
                        "Your plan's storage: " + com.echoledger.mobile.core.PlanFormat.limit("storage_bytes", planStorage.used, limit),
                        color = Brand.Muted,
                        fontSize = 12.sp
                    )
                }
                Text(
                    "Recordings are deleted after ${current.retentionDays} days unless kept. Deleted recordings can be restored " +
                        "for $keepHours hours.",
                    color = Brand.Muted,
                    fontSize = 12.sp
                )
            }
        }
    }
}
