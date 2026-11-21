package com.echoledger.mobile.ui.v2.shell

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.PhoneCallback
import androidx.compose.material.icons.outlined.AutoDelete
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.Policy
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

    /** Settings › Privacy › Privacy & data (the D3 map's page glyph, unlike the group's shield). */
    val Policy: ImageVector get() = Icons.Outlined.Policy

    /** Auto-record calls (the Record sheet). */
    val PhoneCallback: ImageVector get() = Icons.AutoMirrored.Outlined.PhoneCallback
}

/** The person's avatar: their initials, or the person icon when there is no name (never a "?", which reads as Help). */
@Composable
fun PersonAvatar(initials: String, modifier: Modifier = Modifier) {
    if (initials.isBlank()) EchoAvatar(modifier, icon = PendingIcons.Person) else EchoAvatar(modifier, initials = initials)
}
