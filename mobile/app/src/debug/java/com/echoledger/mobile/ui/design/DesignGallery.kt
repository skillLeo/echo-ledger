package com.echoledger.mobile.ui.design

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.outlined.Folder
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.VideoLibrary
import androidx.compose.material.icons.rounded.CloudUpload
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Folder
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.MicOff
import androidx.compose.material.icons.rounded.MoreVert
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Palette
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.rounded.VideoLibrary
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.ui.Brand
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.LocalEchoColors
import com.echoledger.mobile.ui.LocalEchoType
import com.echoledger.mobile.ui.Space

/** Debug builds only: every design-system component in one page (`adb shell am start -n …/.ui.design.DesignGalleryActivity`). */
class DesignGalleryActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent { EchoLedgerTheme { DesignGallery() } }
    }
}

/** [part] 0 shows type, buttons, inputs and rows; 1 shows states, people and navigation (two screenshots). */
@Composable
fun DesignGallery(part: Int = -1) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    Column(Modifier.fillMaxSize().background(c.bg).verticalScroll(rememberScrollState())) {
        if (part != 1) {
            EchoTopBar("Library", actions = { BarIcon(Icons.Rounded.MoreVert, "More", {}) })
            Column(Modifier.padding(horizontal = Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s3)) {
                SearchPill("Search recordings and transcripts", {}, trailing = { BarIcon(Icons.Rounded.Tune, "Sort and filter", {}) })
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                    EchoFilterChip("Source", active = true, count = 2, onClick = {})
                    EchoFilterChip("Date", active = false, onClick = {})
                    EchoFilterChip("Client", active = false, onClick = {})
                    EchoFilterChip("Status", active = false, onClick = {})
                }
            }
            SectionHeader("Waiting for you")
            RecordingRow(
                "Call with Omar Khan",
                "1:29 AM",
                "2:02 · Acme · Urdu",
                tile = { IconTile(Icons.Rounded.Forum, Color(0xFF25A35A)) },
                onClick = {},
                onLongClick = {},
                unopened = true
            )
            SectionHeader("Today")
            RecordingRow(
                "Weekly sync with Northwind",
                "9:00 AM",
                "42:10 · Northwind",
                tile = { IconTile(Icons.Rounded.Videocam, Color(0xFF0D74A7)) },
                onClick = {},
                onLongClick = {},
                pinned = true
            )
            RecordingRow(
                "Voice note",
                "8:12 AM",
                "Transcribing",
                tile = { IconTile(Icons.Rounded.Mic, c.accentText) },
                onClick = {},
                onLongClick = {},
                status = { Spinner(14.dp) }
            )
            RecordingRow(
                "Invoice questions",
                "7:10 AM",
                "Uploading · 64%",
                tile = { IconTile(Icons.Rounded.Storefront, Color(0xFF128C7E)) },
                onClick = {},
                onLongClick = {},
                selecting = true,
                selected = true,
                status = { Icon(Icons.Rounded.CloudUpload, null, tint = c.textMuted, modifier = Modifier.padding(0.dp).height(16.dp)) }
            )
            RecordingRow(
                "Call with +92 300 1234567",
                "6:02 AM",
                "Transcription failed · Tap to retry",
                tile = { IconTile(Icons.Rounded.Forum, Color(0xFF25A35A)) },
                onClick = {},
                onLongClick = {},
                metaColor = c.danger,
                status = { Icon(Icons.Rounded.ErrorOutline, null, tint = c.danger, modifier = Modifier.height(16.dp)) }
            )
            RecordingRow(
                "Voice note",
                "5:40 AM",
                "No audio",
                tile = { IconTile(Icons.Rounded.Mic, c.accentText) },
                onClick = {},
                onLongClick = {},
                status = { Icon(Icons.Rounded.MicOff, null, tint = c.textMuted, modifier = Modifier.height(16.dp)) },
                divider = false
            )
            SkeletonRow()
            Column(Modifier.padding(Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s3)) {
                Text("Buttons", style = type.titleS, color = c.text)
                EchoButton("Keep", {}, Modifier.fillMaxWidth())
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                    EchoButton("Discard", {}, kind = ButtonKind.Secondary)
                    EchoButton("Retry", {}, kind = ButtonKind.Text)
                    EchoButton("Delete", {}, kind = ButtonKind.Destructive, icon = Icons.Rounded.Delete)
                }
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                    EchoButton("Saving", {}, loading = true)
                    EchoButton("Disabled", {}, enabled = false)
                }
                var view by remember { mutableIntStateOf(0) }
                SegmentedControl(listOf("Roman Urdu", "اردو", "English", "Original"), view, { view = it })
            }
        }
        if (part != 0) {
            Column(Modifier.padding(horizontal = Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s3)) {
                Text("Type", style = type.titleS, color = c.text, modifier = Modifier.padding(top = Space.s4))
                Text("Title large 24", style = type.titleL, color = c.text)
                Text("Row title 16 · body 15 · meta 13", style = type.bodyL, color = c.text)
                Text("Tabular 0:00 1:11 2:22 10:48", style = type.labelM.tabular(), color = c.textMuted)
                Text(
                    "آپ کا بل اگلے ہفتے آئے گا، invoice بھیج دیں",
                    style = type.transcriptUrdu,
                    color = c.text,
                    modifier = Modifier.fillMaxWidth()
                )
                InlineBanner("Transcription failed: the server could not be reached.", kind = BannerKind.Error, action = "Retry")
                InlineBanner("Call capture isn't ready.", kind = BannerKind.Warning, action = "Fix")
                InlineBanner("Roman Urdu is made by Claude.", onDismiss = {})
                Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                    listOf("Omar Khan", "Aria Studio", "Northwind", "Sara", "Bilal Ahmed", "Contoso", "Zara", "Me").forEach { Avatar(it) }
                }
            }
            SettingsRow(Icons.Rounded.Palette, "Appearance", summary = "System theme · Inter", onClick = {
            }, trailing = { Switch(checked = true, onCheckedChange = {}) })
            SettingsRow(Icons.Rounded.Notifications, "Notifications", summary = "Ready, problems", tint = Brand.Warning, onClick = {
            })
            EchoEmptyState(Icons.Rounded.Search, "No results", body = "Try fewer words or another filter.", action = "Clear filters")
            UndoSnackbar("Recording deleted", {})
            Spacer(Modifier.height(Space.s4))
            SelectionBar(3, {}) {
                BarIcon(Icons.Rounded.Folder, "Move", {})
                BarIcon(Icons.Rounded.Delete, "Delete", {})
                BarIcon(Icons.Rounded.MoreVert, "More", {})
            }
            EchoTopBar("Invoice questions", large = false, navigation = {
                BarIcon(Icons.AutoMirrored.Rounded.ArrowBack, "Back", {}, tint = c.text)
            })
            Spacer(Modifier.height(Space.s5))
            RecordingStrip("02:14", {}, {})
            EchoBottomBar(
                tabs = listOf(
                    NavTab("library", "Library", Icons.Outlined.VideoLibrary, Icons.Rounded.VideoLibrary),
                    NavTab("clients", "Clients", Icons.Outlined.Folder, Icons.Rounded.Folder),
                    NavTab("activity", "Activity", Icons.Outlined.Notifications, Icons.Rounded.Notifications, badge = 3),
                    NavTab("you", "You", Icons.Outlined.Person, Icons.Rounded.Person)
                ),
                current = "library",
                onSelect = {},
                onReselect = {},
                recording = false,
                onRecord = {},
                onRecordLongPress = {},
                modifier = Modifier.padding(top = Space.s5)
            )
        }
    }
}

private fun Color(argb: Long) = androidx.compose.ui.graphics.Color(argb)
