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
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.SourceDevice

/**
 * EchoLedger design system (dark, premium). Layered surfaces give depth; one violet→cyan accent is used sparingly
 * on primary actions and live states. Colours are deliberate tokens, not ad-hoc values.
 */
object Brand {
    val Background = Color(0xFF0A0B10) // app canvas
    val Surface = Color(0xFF13151F) // cards
    val SurfaceAlt = Color(0xFF191C28) // inputs, nested panels
    val Elevated = Color(0xFF202434) // raised/hover
    val Border = Color(0x12FFFFFF)
    val BorderStrong = Color(0x24FFFFFF)
    val Text = Color(0xFFF1F3F8)
    val Muted = Color(0xFF9BA3B4)
    val Faint = Color(0xFF636B7D)

    val Violet = Color(0xFF8B5CF6)
    val Indigo = Color(0xFF6366F1)
    val Cyan = Color(0xFF22D3EE)
    val Danger = Color(0xFFFB5A74)
    val Success = Color(0xFF34D399)
    val Warning = Color(0xFFFBBF24)

    val Gradient = Brush.linearGradient(listOf(Violet, Indigo, Cyan))
    val GradientSoft = Brush.linearGradient(listOf(Violet.copy(alpha = 0.18f), Cyan.copy(alpha = 0.10f)))

    /** Per-source accent used for the small device avatar and badges. */
    fun accent(device: SourceDevice): Color = when (device) {
        SourceDevice.PHONE -> Success
        SourceDevice.DESKTOP -> Cyan
        SourceDevice.WEB -> Color(0xFFC4B5FD)
    }
}

private val EchoTypography = Typography(
    headlineLarge = TextStyle(fontWeight = FontWeight.Bold, fontSize = 28.sp, letterSpacing = (-0.5).sp),
    headlineSmall = TextStyle(fontWeight = FontWeight.Bold, fontSize = 22.sp, letterSpacing = (-0.3).sp),
    titleLarge = TextStyle(fontWeight = FontWeight.SemiBold, fontSize = 18.sp),
    titleMedium = TextStyle(fontWeight = FontWeight.SemiBold, fontSize = 15.sp),
    bodyLarge = TextStyle(fontWeight = FontWeight.Normal, fontSize = 15.sp, lineHeight = 22.sp),
    bodyMedium = TextStyle(fontWeight = FontWeight.Normal, fontSize = 13.sp, lineHeight = 19.sp),
    labelLarge = TextStyle(fontWeight = FontWeight.SemiBold, fontSize = 14.sp),
    labelSmall = TextStyle(fontWeight = FontWeight.Medium, fontSize = 11.sp, letterSpacing = 0.4.sp)
)

@Composable
fun EchoLedgerTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = darkColorScheme(
            primary = Brand.Violet,
            onPrimary = Color.White,
            secondary = Brand.Cyan,
            background = Brand.Background,
            onBackground = Brand.Text,
            surface = Brand.Surface,
            onSurface = Brand.Text,
            surfaceVariant = Brand.SurfaceAlt,
            onSurfaceVariant = Brand.Muted,
            error = Brand.Danger,
            outline = Brand.Border,
            outlineVariant = Brand.BorderStrong
        ),
        typography = EchoTypography,
        content = content
    )
}

fun SourceDevice.icon(): ImageVector = when (this) {
    SourceDevice.PHONE -> Icons.Outlined.PhoneAndroid
    SourceDevice.DESKTOP -> Icons.Outlined.Computer
    SourceDevice.WEB -> Icons.Outlined.Language
}

fun SourceDevice.tint(): Color = Brand.accent(this)

fun SourceApp.icon(): ImageVector = when (this) {
    SourceApp.WHATSAPP, SourceApp.WHATSAPP_WEB -> Icons.Outlined.Forum
    SourceApp.WHATSAPP_BUSINESS -> Icons.Outlined.Storefront
    SourceApp.GOOGLE_MEET, SourceApp.ZOOM, SourceApp.MICROSOFT_TEAMS -> Icons.Outlined.Videocam
    SourceApp.VOICE_NOTE -> Icons.Outlined.Mic
    SourceApp.OTHER -> Icons.Outlined.MoreHoriz
}
