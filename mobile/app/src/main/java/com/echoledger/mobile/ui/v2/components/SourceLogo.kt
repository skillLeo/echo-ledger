package com.echoledger.mobile.ui.v2.components

import android.content.Context
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
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
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.testTag
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
        Box(Modifier.size(size).clip(CircleShape).background(c.bgSubtle), contentAlignment = Alignment.Center) {
            if (icon != null) {
                Image(icon, null, Modifier.size(size))
            } else {
                Icon(app.glyph(), null, Modifier.size(EchoTokens.Icon.md), tint = c.textSecondary)
            }
        }
        val badge = when (device) {
            SourceDevice.DESKTOP -> EchoIcons.DeviceComputer
            SourceDevice.WEB -> EchoIcons.DeviceWeb
            else -> null
        }
        if (badge != null) {
            Box(
                Modifier
                    .align(Alignment.BottomEnd)
                    .size(EchoTokens.Icon.md)
                    .clip(CircleShape)
                    .background(c.bgSurface)
                    .border(EchoTokens.Border.hairline, c.borderDefault, CircleShape),
                contentAlignment = Alignment.Center
            ) {
                Icon(badge, null, Modifier.size(EchoTokens.Space.s12), tint = c.textSecondary)
            }
        }
    }
}

/**
 * A Library row's avatar (v2.1 UX-16): 40 dp, the person's initials on the neutral circle (a voice note a mic, a meeting
 * a camera, a caller without a name the person icon), with the source app's own mark as a 16 dp badge at its bottom
 * end (none for a voice note: the mic says it). [name] is the row's title; [kind] is the recording's kind (the server's
 * facets.kind, else its source); [label] names the source for screen readers.
 */
@Composable
fun EchoRowAvatar(
    app: SourceApp,
    name: String,
    kind: com.echoledger.mobile.core.LibraryKind,
    modifier: Modifier = Modifier,
    label: String? = null
) {
    val c = EchoV2.colors
    val context = LocalContext.current
    val badge = EchoTokens.Component.avatarBadge
    val px = with(LocalDensity.current) { badge.roundToPx() }
    val mark = rememberAppIcon("v2-badge:${app.name}:$px") { installedIcon(context, app, px) }
    val voice = kind == com.echoledger.mobile.core.LibraryKind.VOICE_NOTES
    val meeting = kind == com.echoledger.mobile.core.LibraryKind.MEETINGS
    val initials = if (voice || meeting) "" else rowInitials(name)
    val look = RowAvatarLook(
        initials = initials.ifEmpty { null },
        icon = when {
            voice -> "mic"
            meeting -> "meeting"
            initials.isEmpty() -> "person"
            else -> null
        },
        badge = !voice
    )
    Box(
        modifier
            .size(EchoTokens.Component.avatarSize)
            // One spoken name, the source's ("WhatsApp"): never the initials; what it draws is tagged for tests.
            .clearAndSetSemantics {
                testTag = ROW_AVATAR_TAG
                this[RowAvatarLookKey] = look
                label?.let { contentDescription = it }
            }
    ) {
        when {
            voice -> EchoAvatar(icon = EchoIcons.VoiceNote)
            meeting -> EchoAvatar(icon = EchoIcons.Meeting)
            initials.isNotEmpty() -> EchoAvatar(initials = initials, fill = avatarFill(c, contactAvatarIndex(contactKey(name))))
            else -> EchoAvatar(icon = EchoIcons.Person)
        }
        if (look.badge) {
            Box(
                Modifier
                    .align(Alignment.BottomEnd)
                    .size(badge)
                    .clip(CircleShape)
                    .background(c.bgSurface)
                    .border(EchoTokens.Border.hairline, c.borderDefault, CircleShape),
                contentAlignment = Alignment.Center
            ) {
                if (mark != null) {
                    Image(mark, null, Modifier.size(badge))
                } else {
                    Icon(app.glyph(), null, Modifier.size(EchoTokens.Space.s12), tint = c.textSecondary)
                }
            }
        }
    }
}

/** The test tag of a row's avatar. */
const val ROW_AVATAR_TAG = "echo-row-avatar"

/** What a row's avatar draws: its initials, else its icon ("mic", "meeting", "person"), and whether it has a badge. */
data class RowAvatarLook(val initials: String?, val icon: String?, val badge: Boolean)

/** Tests read [RowAvatarLook] here (TalkBack doesn't: it hears the source's name only). */
val RowAvatarLookKey = androidx.compose.ui.semantics.SemanticsPropertyKey<RowAvatarLook>("RowAvatarLook")

/**
 * The contact behind a row, as the avatar's colour reads it (v2.1 UX-16; the desktop hashes the same way, parity.csv
 * PH-LIB-044): the name only, trimmed, its spaces collapsed, in lower case. Not the number, even once rows carry one,
 * so a contact's colour never changes.
 */
fun contactKey(name: String): String = name.trim().split(Regex("\\s+")).joinToString(" ").lowercase(java.util.Locale.ROOT)

/** Which of the 8 avatar colours a contact has: FNV-1a (32-bit) over the key's UTF-8 bytes, its low 3 bits (mod 8). */
fun contactAvatarIndex(key: String): Int {
    var hash = FNV_OFFSET
    for (byte in key.toByteArray(Charsets.UTF_8)) {
        hash = (hash xor (byte.toInt() and 0xFF)) * FNV_PRIME
    }
    return hash and 7
}

private const val FNV_OFFSET = 0x811C9DC5.toInt()
private const val FNV_PRIME = 0x01000193

/** The avatar palette's colour [index] (0–7, role.avatar) in the theme. */
fun avatarFill(c: com.echoledger.mobile.ui.v2.theme.EchoV2Colors, index: Int): androidx.compose.ui.graphics.Color {
    val light = EchoTokens.Light.run { listOf(avatar0, avatar1, avatar2, avatar3, avatar4, avatar5, avatar6, avatar7) }
    val dark = EchoTokens.Dark.run { listOf(avatar0, avatar1, avatar2, avatar3, avatar4, avatar5, avatar6, avatar7) }
    return (if (c.isDark) dark else light)[index]
}

/** A row title's initials: none for a phone number or an unknown caller ("+92 300…"), which take the person icon. */
internal fun rowInitials(name: String): String {
    val first = name.trim().firstOrNull() ?: return ""
    if (!first.isLetter()) return ""
    return com.echoledger.mobile.ui.v2.shell.initialsOf(name)
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
