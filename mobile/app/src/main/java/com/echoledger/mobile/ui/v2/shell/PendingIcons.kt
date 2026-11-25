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
 * device; before the account has been read, a stable pick from the initials, never grey), or the person icon when there
 * is no name (never a "?", which reads as Help).
 */
@Composable
fun PersonAvatar(initials: String, modifier: Modifier = Modifier) {
    if (initials.isBlank()) {
        EchoAvatar(modifier, icon = PendingIcons.Person)
    } else {
        val index = LocalAvatarColour.current
            ?: com.echoledger.mobile.ui.v2.components.contactAvatarIndex(com.echoledger.mobile.ui.v2.components.contactKey(initials))
        EchoAvatar(
            modifier,
            initials = initials,
            fill = com.echoledger.mobile.ui.v2.components.avatarFill(com.echoledger.mobile.ui.v2.theme.EchoV2.colors, index.mod(8))
        )
    }
}

/** The signed-in person's avatar colour (0-7) as the account says it ([PersonAvatar]); null until it has been read. */
val LocalAvatarColour = androidx.compose.runtime.staticCompositionLocalOf<Int?> { null }
