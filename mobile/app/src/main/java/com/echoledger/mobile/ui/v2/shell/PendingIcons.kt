package com.echoledger.mobile.ui.v2.shell

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Policy
import androidx.compose.ui.graphics.vector.ImageVector

// TODO-DS: move this into components/EchoIcons (same outlined Material Symbols family) and drop this object.
/** Icons the v2 screens need that EchoIcons does not have yet. */
object PendingIcons {
    /** Settings › Privacy › Privacy & data (the D3 map's page glyph, unlike the group's shield). */
    val Policy: ImageVector get() = Icons.Outlined.Policy
}
