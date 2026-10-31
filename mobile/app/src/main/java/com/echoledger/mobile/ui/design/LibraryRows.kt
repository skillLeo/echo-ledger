package com.echoledger.mobile.ui.design

import android.content.Context
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.LaptopMac
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.MicOff
import androidx.compose.material.icons.rounded.MoreHoriz
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.core.graphics.drawable.toBitmap
import com.echoledger.mobile.core.CallTargets
import com.echoledger.mobile.core.ItemStatus
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.SourceDevice
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.ui.LocalEchoColors
import com.echoledger.mobile.ui.Radius
import com.echoledger.mobile.ui.formatDuration
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.TextStyle
import java.util.Locale

/** Each source app's own hue: its icon sits on a 12% tint of it. */
@Composable
fun SourceApp.hue(): Color {
    val c = LocalEchoColors.current
    return when (this) {
        SourceApp.WHATSAPP, SourceApp.WHATSAPP_WEB -> Color(0xFF1DA851)
        SourceApp.WHATSAPP_BUSINESS -> Color(0xFF128C7E)
        SourceApp.GOOGLE_MEET -> Color(0xFF00897B)
        SourceApp.ZOOM -> Color(0xFF2D8CFF)
        SourceApp.MICROSOFT_TEAMS -> Color(0xFF5B5FC7)
        SourceApp.TELEGRAM -> Color(0xFF2AABEE)
        SourceApp.SIGNAL -> Color(0xFF3A76F0)
        SourceApp.MESSENGER -> Color(0xFF0084FF)
        SourceApp.INSTAGRAM -> Color(0xFFE1306C)
        SourceApp.SKYPE -> Color(0xFF00AFF0)
        SourceApp.SLACK -> Color(0xFFE01E5A)
        SourceApp.DISCORD -> Color(0xFF5865F2)
        SourceApp.PHONE_CALL -> c.success
        SourceApp.VOICE_NOTE -> c.accentText
        SourceApp.OTHER -> c.textMuted
    }
}

private fun SourceApp.glyph(): ImageVector = when (this) {
    SourceApp.WHATSAPP_BUSINESS -> Icons.Rounded.Storefront
    SourceApp.GOOGLE_MEET, SourceApp.ZOOM, SourceApp.MICROSOFT_TEAMS, SourceApp.SKYPE -> Icons.Rounded.Videocam
    SourceApp.PHONE_CALL -> Icons.Rounded.Call
    SourceApp.VOICE_NOTE -> Icons.Rounded.Mic
    SourceApp.OTHER -> Icons.Rounded.MoreHoriz
    else -> Icons.Rounded.Forum
}

/** The installed app's own icon, or null (not installed, not visible, or a voice note or SIM call). */
private fun installedIcon(context: Context, app: SourceApp, px: Int): ImageBitmap? {
    val pm = context.packageManager
    return CallTargets.KNOWN.filterValues { it == app }.keys.firstNotNullOfOrNull { pkg ->
        runCatching { pm.getApplicationIcon(pkg).toBitmap(px, px).asImageBitmap() }.getOrNull()
    }
}

/** A recording's source: the app's own icon on a 12% tint of its hue (a drawn glyph when the app is not installed). */
@Composable
fun SourceAppTile(app: SourceApp, size: Dp = 44.dp) {
    val context = LocalContext.current
    val hue = app.hue()
    val px = with(androidx.compose.ui.platform.LocalDensity.current) { (size * 0.6f).roundToPx() }
    val icon = rememberAppIcon("source:${app.name}:$px") { installedIcon(context, app, px) }
    Box(
        Modifier.size(size).clip(RoundedCornerShape(Radius.field)).background(hue.copy(alpha = 0.12f)).semantics {
            contentDescription =
                app.label
        },
        contentAlignment = Alignment.Center
    ) {
        if (icon != null) {
            Image(icon, contentDescription = null, modifier = Modifier.size(size * 0.6f))
        } else {
            Icon(app.glyph(), contentDescription = null, tint = hue, modifier = Modifier.size(size * 0.5f))
        }
    }
}

/** The 16 dp corner badge for recordings made on a computer or on the web (phone recordings have none). */
@Composable
fun DeviceBadge(device: SourceDevice) {
    if (device == SourceDevice.PHONE) return
    val c = LocalEchoColors.current
    Box(
        Modifier.size(18.dp).clip(CircleShape).background(c.surface).border(1.dp, c.border, CircleShape)
            .semantics { contentDescription = device.label },
        contentAlignment = Alignment.Center
    ) {
        Icon(
            if (device ==
                SourceDevice.DESKTOP
            ) {
                Icons.Rounded.LaptopMac
            } else {
                Icons.Rounded.Language
            },
            null,
            tint = c.textMuted,
            modifier = Modifier.size(12.dp)
        )
    }
}

/** List groups by day: Today · Yesterday · This week · then one per month ("October", "October 2025"). */
fun dateGroup(iso: String?, today: LocalDate, zone: ZoneId = ZoneId.systemDefault(), locale: Locale = Locale.getDefault()): String {
    val day = runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(zone).toLocalDate() }.getOrNull() ?: return "Earlier"
    return when {
        day == today -> "Today"
        day == today.minusDays(1) -> "Yesterday"
        day.isAfter(today.minusDays(7)) && !day.isAfter(today) -> "This week"
        day.year == today.year -> day.month.getDisplayName(TextStyle.FULL_STANDALONE, locale)
        else -> day.month.getDisplayName(TextStyle.FULL_STANDALONE, locale) + " " + day.year
    }
}

/** The time at the end of a row: "1:29 AM" today and yesterday, "Mon" this week, "Oct 22" before. */
fun rowTime(iso: String?, today: LocalDate, zone: ZoneId = ZoneId.systemDefault(), locale: Locale = Locale.getDefault()): String {
    val at = runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(zone) }.getOrNull() ?: return ""
    val day = at.toLocalDate()
    val pattern = when {
        !day.isBefore(today.minusDays(1)) -> "h:mm a"
        day.isAfter(today.minusDays(7)) -> "EEE"
        else -> "MMM d"
    }
    return at.format(DateTimeFormatter.ofPattern(pattern, locale))
}

/**
 * "2:02 · Acme": only the parts that exist. The language is not on the row (since 1.3.2): it is a Library filter and is
 * on the recording page.
 */
fun rowMeta(item: LibraryItem): String = listOfNotNull(
    formatDuration(item.durationMs).ifEmpty { null },
    item.client?.name?.takeIf { it.isNotBlank() }
).joinToString(" · ")

/**
 * A library recording as a [RecordingRow]. Status shows only when it needs attention: a spinner while transcribing,
 * a red alert when it failed (the meta line says "Tap to retry"), mic-off for no audio.
 */
@Composable
fun LibraryItemRow(
    item: LibraryItem,
    today: LocalDate,
    onOpen: () -> Unit,
    onToggle: () -> Unit,
    selecting: Boolean = false,
    selected: Boolean = false,
    divider: Boolean = true
) {
    val c = LocalEchoColors.current
    val status = ItemStatus.of(item)
    val app = SourceApp.from(item.sourceApp)
    val meta = when (status) {
        ItemStatus.FAILED -> "Transcription failed · Tap to retry"
        ItemStatus.NO_AUDIO -> "No audio"
        ItemStatus.PROCESSING -> listOf("Transcribing", rowMeta(item)).filter { it.isNotEmpty() }.joinToString(" · ")
        ItemStatus.READY -> rowMeta(item)
    }
    RecordingRow(
        title = item.title?.takeIf { it.isNotBlank() } ?: untitled(app),
        time = rowTime(item.startedAt, today),
        meta = meta,
        tile = { SourceAppTile(app) },
        onClick = { if (selecting) onToggle() else onOpen() },
        onLongClick = onToggle,
        onTileClick = onToggle,
        selecting = selecting,
        selected = selected,
        pinned = item.pinned,
        unopened = item.opened == false,
        sharedKey = item.id,
        metaColor = if (status == ItemStatus.FAILED) c.danger else c.textMuted,
        status = when (status) {
            ItemStatus.PROCESSING -> ({ Spinner(14.dp) })
            ItemStatus.FAILED -> ({ Icon(Icons.Rounded.ErrorOutline, null, tint = c.danger, modifier = Modifier.size(16.dp)) })
            ItemStatus.NO_AUDIO -> ({ Icon(Icons.Rounded.MicOff, null, tint = c.textMuted, modifier = Modifier.size(16.dp)) })
            ItemStatus.READY -> null
        },
        badge = { DeviceBadge(SourceDevice.from(item.sourceDevice)) },
        stateLabel = status.label,
        divider = divider
    )
}

/** A recording without a title: "WhatsApp call", "Voice note", "Phone call". */
fun untitled(app: SourceApp): String = when (app) {
    SourceApp.VOICE_NOTE, SourceApp.PHONE_CALL -> app.label
    SourceApp.OTHER -> "Recording"
    else -> "${app.label} call"
}
