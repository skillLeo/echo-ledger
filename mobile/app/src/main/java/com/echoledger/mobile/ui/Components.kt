package com.echoledger.mobile.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.SourceDevice

/** Source device label — always icon + text. */
@Composable
fun SourceDeviceBadge(device: SourceDevice, label: String? = null) {
    Badge(label ?: device.label, device.tint()) {
        Icon(device.icon(), contentDescription = null, tint = device.tint(), modifier = Modifier.size(14.dp))
    }
}

@Composable
fun SourceAppBadge(app: SourceApp, label: String? = null) {
    Badge(label ?: app.label, Brand.Muted) {
        Icon(app.icon(), contentDescription = null, tint = Brand.Muted, modifier = Modifier.size(14.dp))
    }
}

@Composable
private fun Badge(text: String, color: Color, icon: @Composable () -> Unit) {
    Surface(shape = RoundedCornerShape(50), color = Color.Transparent, border = BorderStroke(1.dp, color.copy(alpha = 0.35f))) {
        Row(
            Modifier.padding(horizontal = 8.dp, vertical = 3.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(5.dp)
        ) {
            icon()
            Text(text, color = color, fontSize = 12.sp)
        }
    }
}

@Composable
fun SectionCard(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Card(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(containerColor = Brand.Surface),
        border = BorderStroke(1.dp, Brand.Border)
    ) {
        Column(Modifier.padding(PaddingValues(16.dp)), verticalArrangement = Arrangement.spacedBy(10.dp), content = content)
    }
}

fun formatDuration(ms: Long?): String {
    if (ms == null || ms <= 0) return ""
    val s = ms / 1000
    return if (s >= 3600) "%d:%02d:%02d".format(s / 3600, (s % 3600) / 60, s % 60) else "%d:%02d".format(s / 60, s % 60)
}

fun formatWhen(iso: String?): String = runCatching {
    val instant = java.time.OffsetDateTime.parse(iso).toLocalDateTime()
    instant.format(java.time.format.DateTimeFormatter.ofPattern("MMM d · HH:mm"))
}.getOrDefault("")
