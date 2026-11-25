package com.echoledger.mobile.ui.v2.components

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.HelpOutline
import androidx.compose.material.icons.automirrored.outlined.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.outlined.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.outlined.Label
import androidx.compose.material.icons.automirrored.outlined.Logout
import androidx.compose.material.icons.automirrored.outlined.Notes
import androidx.compose.material.icons.automirrored.outlined.PhoneCallback
import androidx.compose.material.icons.automirrored.outlined.Sort
import androidx.compose.material.icons.outlined.AccountCircle
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.AllInclusive
import androidx.compose.material.icons.outlined.ArrowUpward
import androidx.compose.material.icons.outlined.AutoAwesome
import androidx.compose.material.icons.outlined.AutoDelete
import androidx.compose.material.icons.outlined.BookmarkBorder
import androidx.compose.material.icons.outlined.Call
import androidx.compose.material.icons.outlined.Check
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.ClosedCaption
import androidx.compose.material.icons.outlined.ContentCopy
import androidx.compose.material.icons.outlined.CreditCard
import androidx.compose.material.icons.outlined.DataUsage
import androidx.compose.material.icons.outlined.DeleteOutline
import androidx.compose.material.icons.outlined.Description
import androidx.compose.material.icons.outlined.Devices
import androidx.compose.material.icons.outlined.DriveFileMove
import androidx.compose.material.icons.outlined.Edit
import androidx.compose.material.icons.outlined.ErrorOutline
import androidx.compose.material.icons.outlined.FileDownload
import androidx.compose.material.icons.outlined.FileUpload
import androidx.compose.material.icons.outlined.FilterAlt
import androidx.compose.material.icons.outlined.FilterList
import androidx.compose.material.icons.outlined.Flag
import androidx.compose.material.icons.outlined.FolderOpen
import androidx.compose.material.icons.outlined.FolderShared
import androidx.compose.material.icons.outlined.FormatSize
import androidx.compose.material.icons.outlined.Forum
import androidx.compose.material.icons.outlined.Forward10
import androidx.compose.material.icons.outlined.GraphicEq
import androidx.compose.material.icons.outlined.Groups
import androidx.compose.material.icons.outlined.HealthAndSafety
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Image
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material.icons.outlined.Info
import androidx.compose.material.icons.outlined.Key
import androidx.compose.material.icons.outlined.KeyboardArrowDown
import androidx.compose.material.icons.outlined.KeyboardArrowUp
import androidx.compose.material.icons.outlined.KeyboardVoice
import androidx.compose.material.icons.outlined.Language
import androidx.compose.material.icons.outlined.Laptop
import androidx.compose.material.icons.outlined.Link
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material.icons.outlined.ManageAccounts
import androidx.compose.material.icons.outlined.MarkChatUnread
import androidx.compose.material.icons.outlined.Mic
import androidx.compose.material.icons.outlined.MicOff
import androidx.compose.material.icons.outlined.MoreVert
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Palette
import androidx.compose.material.icons.outlined.Pause
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.PhoneMissed
import androidx.compose.material.icons.outlined.PlayArrow
import androidx.compose.material.icons.outlined.Policy
import androidx.compose.material.icons.outlined.PushPin
import androidx.compose.material.icons.outlined.QuestionAnswer
import androidx.compose.material.icons.outlined.RadioButtonChecked
import androidx.compose.material.icons.outlined.RadioButtonUnchecked
import androidx.compose.material.icons.outlined.RemoveCircleOutline
import androidx.compose.material.icons.outlined.Replay10
import androidx.compose.material.icons.outlined.RestoreFromTrash
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.outlined.SelectAll
import androidx.compose.material.icons.outlined.Sensors
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material.icons.outlined.Share
import androidx.compose.material.icons.outlined.Shield
import androidx.compose.material.icons.outlined.Spellcheck
import androidx.compose.material.icons.outlined.SportsEsports
import androidx.compose.material.icons.outlined.Stop
import androidx.compose.material.icons.outlined.StopScreenShare
import androidx.compose.material.icons.outlined.Storage
import androidx.compose.material.icons.outlined.Storefront
import androidx.compose.material.icons.outlined.Support
import androidx.compose.material.icons.outlined.Sync
import androidx.compose.material.icons.outlined.Translate
import androidx.compose.material.icons.outlined.Tv
import androidx.compose.material.icons.outlined.Videocam
import androidx.compose.material.icons.outlined.Wallpaper
import androidx.compose.material.icons.outlined.WorkspacePremium
import androidx.compose.ui.graphics.vector.ImageVector

/**
 * The one icon family of the v2 screens: Material Symbols, outlined, monochrome, mapped by the design system's names.
 * Icons take the text colour of where they sit; there are no coloured icon tiles. Only directional icons mirror in
 * right-to-left (back); media controls, checkmarks and clocks never do.
 */
object EchoIcons {
    val Search: ImageVector get() = Icons.Outlined.Search

    /** Back to top (v2.1 UX-25). */
    val BackToTop: ImageVector get() = Icons.Outlined.ArrowUpward
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
    val Label: ImageVector get() = Icons.AutoMirrored.Outlined.Label
    val Sort: ImageVector get() = Icons.AutoMirrored.Outlined.Sort
    val Keep: ImageVector get() = Icons.Outlined.AllInclusive
    val Rename: ImageVector get() = Icons.Outlined.Edit
    val Sync: ImageVector get() = Icons.Outlined.Sync
    val Export: ImageVector get() = Icons.Outlined.FileDownload
    val SelectAll: ImageVector get() = Icons.Outlined.SelectAll
    val Unpin: ImageVector get() = Icons.Outlined.PushPin
    val Unfold: ImageVector get() = Icons.AutoMirrored.Outlined.KeyboardArrowRight
    val SignOut: ImageVector get() = Icons.AutoMirrored.Outlined.Logout
    val Captions: ImageVector get() = Icons.Outlined.ClosedCaption

    /** A recording's screen moments (the Screens strip, a Library row's count). */
    val Screens: ImageVector get() = Icons.Outlined.Image
    val Previous: ImageVector get() = Icons.AutoMirrored.Outlined.KeyboardArrowLeft
    val Next: ImageVector get() = Icons.AutoMirrored.Outlined.KeyboardArrowRight
    val Add: ImageVector get() = Icons.Outlined.Add
    val Pin: ImageVector get() = Icons.Outlined.PushPin
    val Todo: ImageVector get() = Icons.Outlined.RadioButtonUnchecked
    val Restore: ImageVector get() = Icons.Outlined.RestoreFromTrash

    // Navigation
    val Home: ImageVector get() = Icons.Outlined.Home
    val Library: ImageVector get() = Icons.Outlined.GraphicEq
    val Live: ImageVector get() = Icons.Outlined.Sensors
    val Settings: ImageVector get() = Icons.Outlined.Settings
    val Empty: ImageVector get() = Icons.Outlined.Inbox
    val Projects: ImageVector get() = Icons.Outlined.FolderOpen
    val Bell: ImageVector get() = Icons.Outlined.Notifications
    val Stop: ImageVector get() = Icons.Outlined.Stop
    val Mic: ImageVector get() = Icons.Outlined.Mic
    val MicOff: ImageVector get() = Icons.Outlined.MicOff
    val Important: ImageVector get() = Icons.Outlined.Flag
    val Dictation: ImageVector get() = Icons.Outlined.KeyboardVoice
    val Translate: ImageVector get() = Icons.Outlined.Translate
    val Assist: ImageVector get() = Icons.Outlined.AutoAwesome
    val Upload: ImageVector get() = Icons.Outlined.FileUpload
    val Missed: ImageVector get() = Icons.Outlined.PhoneMissed

    // Settings groups
    val Palette: ImageVector get() = Icons.Outlined.Palette
    val Language: ImageVector get() = Icons.Outlined.Language
    val TextSize: ImageVector get() = Icons.Outlined.FormatSize
    val Wallpaper: ImageVector get() = Icons.Outlined.Wallpaper
    val Lock: ImageVector get() = Icons.Outlined.Lock
    val Privacy: ImageVector get() = Icons.Outlined.Shield
    val Account: ImageVector get() = Icons.Outlined.AccountCircle
    val Help: ImageVector get() = Icons.AutoMirrored.Outlined.HelpOutline
    val Info: ImageVector get() = Icons.Outlined.Info
    val Health: ImageVector get() = Icons.Outlined.HealthAndSafety
    val Devices: ImageVector get() = Icons.Outlined.Devices
    val Storage: ImageVector get() = Icons.Outlined.Storage
    val Plan: ImageVector get() = Icons.Outlined.WorkspacePremium

    /** This month's minutes (the usage meter, v2.1 UX-16). */
    val Usage: ImageVector get() = Icons.Outlined.DataUsage
    val Words: ImageVector get() = Icons.Outlined.Spellcheck
    val Key: ImageVector get() = Icons.Outlined.Key
    val Games: ImageVector get() = Icons.Outlined.SportsEsports
    val Videos: ImageVector get() = Icons.Outlined.Tv

    // Settings (docs/v2/DESIGN_SYSTEM.md "Settings icons", tokens/icons/settings.json): one glyph per meaning, so a group
    // never repeats its pages' glyphs (Recording vs Microphone & audio vs Voice notes).
    val SettingsRecording: ImageVector get() = Icons.Outlined.RadioButtonChecked
    val SettingsTranscription: ImageVector get() = Icons.Outlined.Description
    val SettingsAccount: ImageVector get() = Icons.Outlined.Person
    val SettingsSetup: ImageVector get() = Icons.Outlined.CheckCircle
    val SettingsAbout: ImageVector get() = Icons.Outlined.Support
    val SettingsVoice: ImageVector get() = Icons.Outlined.GraphicEq
    val SettingsLanguage: ImageVector get() = Icons.Outlined.Translate
    val SettingsProfile: ImageVector get() = Icons.Outlined.ManageAccounts
    val SettingsPlan: ImageVector get() = Icons.Outlined.CreditCard
    val SettingsPolicy: ImageVector get() = Icons.Outlined.Policy

    /** A person's avatar when there is no name to take initials from (never a "?"). */
    val Person: ImageVector get() = Icons.Outlined.Person

    /** Allow auto clean-up: the recording may go by the retention rules again. */
    val AutoClean: ImageVector get() = Icons.Outlined.AutoDelete

    /** Auto-record calls (the Record sheet); mirrors in right to left. */
    val AutoRecordCalls: ImageVector get() = Icons.AutoMirrored.Outlined.PhoneCallback

    /** A recording made on a computer (the source mark's device badge). */
    val DeviceComputer: ImageVector get() = Icons.Outlined.Laptop

    /** A recording made on the web (the source mark's device badge). */
    val DeviceWeb: ImageVector get() = Icons.Outlined.Language

    /** The Inbox smart folder: recordings in no project. */
    val Inbox: ImageVector get() = Icons.Outlined.Inbox

    /** The Imported smart folder. */
    val Imported: ImageVector get() = Icons.Outlined.FileDownload

    /** Shared with me: what teammates sent. */
    val SharedWithMe: ImageVector get() = Icons.Outlined.FolderShared

    /** A request link (Request files). */
    val Link: ImageVector get() = Icons.Outlined.Link

    /** A group meeting app (Teams). */
    val Groups: ImageVector get() = Icons.Outlined.Groups

    /** Shrink the recording pill to a dot. */
    val Shrink: ImageVector get() = Icons.Outlined.RemoveCircleOutline

    /** Hide EchoLedger's floating windows (before a screen share). */
    val HideWindows: ImageVector get() = Icons.Outlined.StopScreenShare

    /** Copy one detail (a payment detail, a reference). */
    val Copy: ImageVector get() = Icons.Outlined.ContentCopy

    /** Library's [Filters n]. */
    val Filters: ImageVector get() = Icons.Outlined.FilterList

    /** One of My views (a saved filter set). */
    val SavedView: ImageVector get() = Icons.Outlined.FilterAlt

    /** Library › Unread. */
    val Unread: ImageVector get() = Icons.Outlined.MarkChatUnread

    /** An open group folds up again. */
    val Collapse: ImageVector get() = Icons.Outlined.KeyboardArrowUp
}
