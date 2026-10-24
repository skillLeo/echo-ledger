package com.echoledger.mobile.ui

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Computer
import androidx.compose.material.icons.outlined.Forum
import androidx.compose.material.icons.outlined.Language
import androidx.compose.material.icons.outlined.Mic
import androidx.compose.material.icons.outlined.MoreHoriz
import androidx.compose.material.icons.outlined.PhoneAndroid
import androidx.compose.material.icons.outlined.Storefront
import androidx.compose.material.icons.outlined.Videocam
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.SourceDevice

object Brand {
    val Background = Color(0xFF0B0D12)
    val Surface = Color(0xFF12141C)
    val Surface2 = Color(0xFF181B25)
    val Border = Color(0x14FFFFFF)
    val Text = Color(0xFFE6E8EE)
    val Muted = Color(0xFF9AA3B2)
    val Violet = Color(0xFF8B5CF6)
    val Cyan = Color(0xFF22D3EE)
    val Danger = Color(0xFFF43F5E)
    val Success = Color(0xFF10B981)
    val Gradient = Brush.linearGradient(listOf(Violet, Cyan))
}

@Composable
fun EchoLedgerTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = darkColorScheme(
            primary = Brand.Violet,
            secondary = Brand.Cyan,
            background = Brand.Background,
            surface = Brand.Surface,
            surfaceVariant = Brand.Surface2,
            onBackground = Brand.Text,
            onSurface = Brand.Text,
            onSurfaceVariant = Brand.Muted,
            error = Brand.Danger,
            outline = Brand.Border
        ),
        content = content
    )
}

fun SourceDevice.icon(): ImageVector = when (this) {
    SourceDevice.PHONE -> Icons.Outlined.PhoneAndroid
    SourceDevice.DESKTOP -> Icons.Outlined.Computer
    SourceDevice.WEB -> Icons.Outlined.Language
}

fun SourceDevice.tint(): Color = when (this) {
    SourceDevice.PHONE -> Brand.Success
    SourceDevice.DESKTOP -> Brand.Cyan
    SourceDevice.WEB -> Color(0xFFC4B5FD)
}

fun SourceApp.icon(): ImageVector = when (this) {
    SourceApp.WHATSAPP -> Icons.Outlined.Forum
    SourceApp.WHATSAPP_BUSINESS -> Icons.Outlined.Storefront
    SourceApp.GOOGLE_MEET, SourceApp.ZOOM -> Icons.Outlined.Videocam
    SourceApp.VOICE_NOTE -> Icons.Outlined.Mic
    SourceApp.OTHER -> Icons.Outlined.MoreHoriz
}
