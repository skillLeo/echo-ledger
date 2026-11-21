package com.echoledger.mobile.ui.v2.components

import android.content.Context
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.LayoutDirection
import androidx.core.graphics.drawable.toBitmap
import com.echoledger.mobile.core.CallTargets
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.SourceDevice
import com.echoledger.mobile.ui.design.rememberAppIcon
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * A recording's source as its app's own mark (PLAN_V2_PARITY 3: real logos, never generic glyphs for known sources):
 * the installed app's icon from this phone, on a neutral circle; a glyph from the one icon set when the app isn't
 * here (a SIM call, a voice note, an app used on another device). The same sources as the desktop's SourceLogos and
 * the web's source-logo partial. The colours are the apps' own, from their icons, never written here.
 * [device] adds the small badge for a recording made on a computer or on the web (none for one made on a phone).
 * [label] names the mark for screen readers; without it the mark is decorative (the row says the app).
 */
@Composable
fun EchoSourceLogo(app: SourceApp, modifier: Modifier = Modifier, device: SourceDevice? = null, label: String? = null) {
    val c = EchoV2.colors
    val context = LocalContext.current
    val size = EchoTokens.Component.avatarSize
    val px = with(LocalDensity.current) { size.roundToPx() }
    val icon = rememberAppIcon("v2-source:${app.name}:$px") { installedIcon(context, app, px) }
    Box(modifier.size(size).then(if (label != null) Modifier.semantics { contentDescription = label } else Modifier)) {
        when {
            // A voice note is EchoLedger's own: its mark is the app's calm accent with the voice glyph (D12).
            app == SourceApp.VOICE_NOTE -> Box(
                Modifier.size(size).clip(CircleShape).background(c.accentSoft),
                contentAlignment = Alignment.Center
            ) {
                Icon(EchoIcons.SettingsVoice, null, Modifier.size(EchoTokens.Icon.md), tint = c.accentText)
            }

            icon != null -> Image(icon, null, Modifier.size(size).clip(CircleShape))

            else -> Box(Modifier.size(size).clip(CircleShape).background(c.bgSubtle), contentAlignment = Alignment.Center) {
                Icon(app.glyph(), null, Modifier.size(EchoTokens.Icon.md), tint = c.textSecondary)
            }
        }
        val badge = when (device) {
            SourceDevice.PHONE -> EchoIcons.DevicePhone
            SourceDevice.DESKTOP -> EchoIcons.DeviceComputer
            SourceDevice.WEB -> EchoIcons.DeviceWeb
            else -> null
        }
        if (badge != null) {
            // 16 dp, 2 dp outside the mark's corner, ringed in the row's background so the logo's edge is never cut.
            val out = if (LocalLayoutDirection.current == LayoutDirection.Rtl) -EchoTokens.Space.s2 else EchoTokens.Space.s2
            Box(
                Modifier
                    .align(Alignment.BottomEnd)
                    .offset(x = out, y = EchoTokens.Space.s2)
                    .size(EchoTokens.Icon.sm)
                    .border(EchoTokens.Border.strong, c.bgCanvas, CircleShape)
                    .clip(CircleShape)
                    .background(c.bgSurface),
                contentAlignment = Alignment.Center
            ) {
                Icon(badge, null, Modifier.size(EchoTokens.Space.s12 - EchoTokens.Space.s2), tint = c.textSecondary)
            }
        }
    }
}

/** The installed app's own icon at [px], or null (not installed, not visible to this app, a voice note, a SIM call). */
private fun installedIcon(context: Context, app: SourceApp, px: Int): ImageBitmap? {
    val pm = context.packageManager
    return CallTargets.KNOWN.filterValues { it == app }.keys.firstNotNullOfOrNull { pkg ->
        runCatching { pm.getApplicationIcon(pkg).toBitmap(px, px).asImageBitmap() }.getOrNull()
    }
}

/** The glyph for a source whose app isn't on this phone. */
private fun SourceApp.glyph(): ImageVector = when (this) {
    SourceApp.WHATSAPP_BUSINESS -> EchoIcons.WhatsAppBusiness
    SourceApp.GOOGLE_MEET, SourceApp.ZOOM, SourceApp.MICROSOFT_TEAMS, SourceApp.SKYPE -> EchoIcons.Meeting
    SourceApp.PHONE_CALL -> EchoIcons.PhoneCall
    SourceApp.VOICE_NOTE -> EchoIcons.VoiceNote
    SourceApp.OTHER -> EchoIcons.More
    else -> EchoIcons.WhatsApp
}
