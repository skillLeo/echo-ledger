package com.echoledger.mobile.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CloudOff
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.SourceDevice

/** Source device label — always icon + text, as a soft tinted chip. */
@Composable
fun SourceDeviceBadge(device: SourceDevice, label: String? = null) {
    Chip(label ?: device.label, device.tint(), filled = true) {
        Icon(device.icon(), contentDescription = null, tint = device.tint(), modifier = Modifier.size(13.dp))
    }
}

@Composable
fun SourceAppBadge(app: SourceApp, label: String? = null) {
    Chip(label ?: app.label, Brand.Muted, filled = false) {
        Icon(app.icon(), contentDescription = null, tint = Brand.Muted, modifier = Modifier.size(13.dp))
    }
}

@Composable
private fun Chip(text: String, color: Color, filled: Boolean, icon: @Composable () -> Unit) {
    Surface(
        shape = RoundedCornerShape(50),
        color = if (filled) color.copy(alpha = 0.14f) else Color.Transparent,
        border = if (filled) null else BorderStroke(1.dp, color.copy(alpha = 0.30f))
    ) {
        Row(
            Modifier.padding(horizontal = Space.s2, vertical = Space.s1),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(5.dp)
        ) {
            icon()
            Text(text, color = color, fontSize = 12.sp, fontWeight = FontWeight.Medium)
        }
    }
}

/** Card with a hairline border on the surface colour (no drop shadow): the container used across the app. */
@Composable
fun SectionCard(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(Radius.md),
        color = Brand.Surface,
        border = BorderStroke(1.dp, Brand.Border)
    ) {
        Column(Modifier.padding(PaddingValues(Space.s4)), verticalArrangement = Arrangement.spacedBy(Space.s3), content = content)
    }
}

/** Round source avatar with the source icon over its accent tint — the visual anchor of a library row. */
@Composable
fun SourceAvatar(device: SourceDevice, app: SourceApp, size: Int = 44) {
    val accent = device.tint()
    Box(
        Modifier.size(size.dp).clip(RoundedCornerShape((size / 3).dp))
            .background(accent.copy(alpha = 0.16f)),
        contentAlignment = Alignment.Center
    ) {
        Icon(app.icon(), contentDescription = null, tint = accent, modifier = Modifier.size((size * 0.45f).dp))
    }
}

/** Large page title with optional trailing actions on its row and a full-width subtitle below, on every screen. */
@Composable
fun ScreenHeader(title: String, subtitle: String? = null, trailing: (@Composable () -> Unit)? = null) {
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Row(Modifier.heightIn(min = 48.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(title, style = MaterialTheme.typography.headlineSmall, color = Brand.Text, modifier = Modifier.weight(1f))
            trailing?.invoke()
        }
        subtitle?.let { Text(it, color = Brand.Muted, style = MaterialTheme.typography.bodyMedium) }
    }
}

/** Centered empty/placeholder state with an icon in a soft circle. */
@Composable
fun EmptyState(icon: ImageVector, title: String, body: String? = null, modifier: Modifier = Modifier) {
    Column(
        modifier.fillMaxWidth().padding(top = 60.dp, start = 32.dp, end = 32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        Box(Modifier.size(64.dp).clip(CircleShape).background(Brand.SurfaceAlt), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = null, tint = Brand.Faint, modifier = Modifier.size(28.dp))
        }
        Text(title, color = Brand.Text, fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
        body?.let {
            Text(
                it,
                color = Brand.Muted,
                fontSize = 13.sp,
                modifier = Modifier.fillMaxWidth(),
                textAlign = androidx.compose.ui.text.style.TextAlign.Center
            )
        }
    }
}

/** Full-width primary button in the accent colour (the screen's single strong call to action). */
@Composable
fun GradientButton(text: String, onClick: () -> Unit, modifier: Modifier = Modifier, enabled: Boolean = true, icon: ImageVector? = null) {
    Box(
        modifier
            .fillMaxWidth()
            .height(52.dp)
            .clip(RoundedCornerShape(Radius.md))
            .background(if (enabled) Brand.Gradient else androidx.compose.ui.graphics.SolidColor(Brand.SurfaceAlt))
            .clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            icon?.let {
                Icon(
                    it,
                    contentDescription = null,
                    tint = if (enabled) LocalEchoColors.current.onAccent else Brand.Faint,
                    modifier = Modifier.size(20.dp)
                )
            }
            Text(
                text,
                color = if (enabled) LocalEchoColors.current.onAccent else Brand.Faint,
                fontWeight = FontWeight.SemiBold,
                fontSize = 16.sp
            )
        }
    }
}

fun formatDuration(ms: Long?): String {
    if (ms == null || ms <= 0) return ""
    // Rounded to the nearest second (as on the desktop) and always ASCII digits, whatever the phone language.
    val s = (ms + 500) / 1000
    return if (s >= 3600) {
        String.format(java.util.Locale.ROOT, "%d:%02d:%02d", s / 3600, (s % 3600) / 60, s % 60)
    } else {
        String.format(java.util.Locale.ROOT, "%d:%02d", s / 60, s % 60)
    }
}

/**
 * "Oct 24 · 9:32 AM" in the viewer's own time zone. The server sends UTC offsets; `toLocalDateTime()` would keep the
 * UTC wall clock, so the instant is converted to [zone] first.
 */
fun formatWhen(
    iso: String?,
    zone: java.time.ZoneId = java.time.ZoneId.systemDefault(),
    locale: java.util.Locale = java.util.Locale.getDefault()
): String = runCatching {
    java.time.OffsetDateTime.parse(iso).atZoneSameInstant(zone)
        .format(java.time.format.DateTimeFormatter.ofPattern("MMM d · h:mm a", locale))
}.getOrDefault("")

/** A quiet warning row with one action: offline, or a part of the screen that did not load (C6). */
@Composable
fun RetryBanner(text: String, retrying: Boolean, onRetry: () -> Unit, modifier: Modifier = Modifier) {
    Surface(modifier.fillMaxWidth(), shape = RoundedCornerShape(Radius.md), color = Brand.Warning.copy(alpha = 0.12f)) {
        Row(
            Modifier.padding(start = Space.s4, end = Space.s1, top = Space.s1, bottom = Space.s1),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Icon(Icons.Outlined.CloudOff, contentDescription = null, tint = Brand.Warning, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(Space.s3))
            Text(text, Modifier.weight(1f), color = Brand.Text, fontSize = 13.sp)
            TextButton(onClick = onRetry, enabled = !retrying) { Text(if (retrying) "Retrying…" else "Retry") }
        }
    }
}
