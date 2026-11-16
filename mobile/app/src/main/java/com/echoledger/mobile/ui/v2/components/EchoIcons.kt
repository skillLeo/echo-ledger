package com.echoledger.mobile.ui.v2.components

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.Notes
import androidx.compose.material.icons.outlined.BookmarkBorder
import androidx.compose.material.icons.outlined.Call
import androidx.compose.material.icons.outlined.Check
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.DeleteOutline
import androidx.compose.material.icons.outlined.DriveFileMove
import androidx.compose.material.icons.outlined.ErrorOutline
import androidx.compose.material.icons.outlined.FolderOpen
import androidx.compose.material.icons.outlined.Forum
import androidx.compose.material.icons.outlined.Forward10
import androidx.compose.material.icons.outlined.GraphicEq
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material.icons.outlined.KeyboardArrowDown
import androidx.compose.material.icons.outlined.Mic
import androidx.compose.material.icons.outlined.MoreVert
import androidx.compose.material.icons.outlined.Pause
import androidx.compose.material.icons.outlined.PlayArrow
import androidx.compose.material.icons.outlined.QuestionAnswer
import androidx.compose.material.icons.outlined.RadioButtonChecked
import androidx.compose.material.icons.outlined.Replay10
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.outlined.Sensors
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material.icons.outlined.Share
import androidx.compose.material.icons.outlined.Storefront
import androidx.compose.material.icons.outlined.Videocam
import androidx.compose.ui.graphics.vector.ImageVector

/**
 * The one icon family of the v2 screens: Material Symbols, outlined, monochrome, mapped by the design system's names.
 * Icons take the text colour of where they sit; there are no coloured icon tiles. Only directional icons mirror in
 * right-to-left (back); media controls, checkmarks and clocks never do.
 */
object EchoIcons {
    val Search: ImageVector get() = Icons.Outlined.Search
    val Close: ImageVector get() = Icons.Outlined.Close
    val Back: ImageVector get() = Icons.AutoMirrored.Outlined.ArrowBack
    val More: ImageVector get() = Icons.Outlined.MoreVert
    val Check: ImageVector get() = Icons.Outlined.Check
    val Expand: ImageVector get() = Icons.Outlined.KeyboardArrowDown
    val Error: ImageVector get() = Icons.Outlined.ErrorOutline

    // Sources
    val PhoneCall: ImageVector get() = Icons.Outlined.Call
    val WhatsApp: ImageVector get() = Icons.Outlined.Forum
    val WhatsAppBusiness: ImageVector get() = Icons.Outlined.Storefront
    val Meeting: ImageVector get() = Icons.Outlined.Videocam
    val VoiceNote: ImageVector get() = Icons.Outlined.Mic

    // Actions
    val Record: ImageVector get() = Icons.Outlined.RadioButtonChecked
    val Play: ImageVector get() = Icons.Outlined.PlayArrow
    val Pause: ImageVector get() = Icons.Outlined.Pause
    val Back10: ImageVector get() = Icons.Outlined.Replay10
    val Forward10: ImageVector get() = Icons.Outlined.Forward10
    val Share: ImageVector get() = Icons.Outlined.Share
    val Transcribe: ImageVector get() = Icons.AutoMirrored.Outlined.Notes
    val Ask: ImageVector get() = Icons.Outlined.QuestionAnswer
    val Moment: ImageVector get() = Icons.Outlined.BookmarkBorder

    @Suppress("DEPRECATION")
    val Move: ImageVector get() = Icons.Outlined.DriveFileMove
    val Delete: ImageVector get() = Icons.Outlined.DeleteOutline
    val Folder: ImageVector get() = Icons.Outlined.FolderOpen

    // Navigation
    val Home: ImageVector get() = Icons.Outlined.Home
    val Library: ImageVector get() = Icons.Outlined.GraphicEq
    val Live: ImageVector get() = Icons.Outlined.Sensors
    val Settings: ImageVector get() = Icons.Outlined.Settings
    val Empty: ImageVector get() = Icons.Outlined.Inbox
}
