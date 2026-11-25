package com.echoledger.mobile.ui.v2.shell

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.PhoneCallback
import androidx.compose.material.icons.outlined.AutoDelete
import androidx.compose.material.icons.outlined.Person
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import com.echoledger.mobile.ui.v2.components.EchoAvatar

// TODO-DS: move these into components/EchoIcons (same outlined Material Symbols family) and drop this object.
/** Icons the v2 screens need that EchoIcons does not have yet. */
object PendingIcons {
    /** Allow auto clean-up: the recording may go by the retention rules again. */
    val AutoDelete: ImageVector get() = Icons.Outlined.AutoDelete

    /** The person's avatar when there is no name to take initials from. */
    val Person: ImageVector get() = Icons.Outlined.Person

    /** Auto-record calls (the Record sheet). */
    val PhoneCallback: ImageVector get() = Icons.AutoMirrored.Outlined.PhoneCallback
}

/**
 * The person's avatar: their initials in white on their colour (DES-08: the account's `avatar_color`, the same on every
 * device; before the account has been read, [personColour] of their email, as the desktop; never grey), or the person
 * icon when there is no name (never a "?", which reads as Help).
 */
@Composable
fun PersonAvatar(initials: String, modifier: Modifier = Modifier) {
    if (initials.isBlank()) {
        EchoAvatar(modifier, icon = PendingIcons.Person)
    } else {
        val index = LocalAvatarColour.current ?: personColour(initials)
        EchoAvatar(
            modifier,
            initials = initials,
            fill = com.echoledger.mobile.ui.v2.components.avatarFill(com.echoledger.mobile.ui.v2.theme.EchoV2.colors, index)
        )
    }
}

/**
 * The colour of someone's circle before the server's avatar_color is known: the desktop's rule (layout.tsx
 * avatarColour), hash × 31 + each code point of [seed] trimmed and in lower case, 32 bits unsigned, mod 8.
 */
fun personColour(seed: String): Int {
    val text = seed.trim().lowercase(java.util.Locale.ROOT)
    var hash = 0L
    var i = 0
    while (i < text.length) {
        val point = text.codePointAt(i)
        hash = (hash * 31 + point) and 0xFFFFFFFFL
        i += Character.charCount(point)
    }
    return (hash % 8).toInt()
}

/**
 * The signed-in person's avatar colour (0-7) for [PersonAvatar]: the account's `avatar_color`, else [personColour] of
 * the account's email; null outside the shell (the initials' own colour then).
 */
val LocalAvatarColour = androidx.compose.runtime.staticCompositionLocalOf<Int?> { null }
