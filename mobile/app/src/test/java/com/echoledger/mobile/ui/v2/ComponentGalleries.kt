package com.echoledger.mobile.ui.v2

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import com.echoledger.mobile.ui.v2.components.EchoActionItem
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoBottomBar
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCheckbox
import com.echoledger.mobile.ui.v2.components.EchoCountBadge
import com.echoledger.mobile.ui.v2.components.EchoDestination
import com.echoledger.mobile.ui.v2.components.EchoDialogContent
import com.echoledger.mobile.ui.v2.components.EchoDivider
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoFilter
import com.echoledger.mobile.ui.v2.components.EchoFilterBar
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoListRow
import com.echoledger.mobile.ui.v2.components.EchoPlayer
import com.echoledger.mobile.ui.v2.components.EchoRadio
import com.echoledger.mobile.ui.v2.components.EchoSearchField
import com.echoledger.mobile.ui.v2.components.EchoSegmented
import com.echoledger.mobile.ui.v2.components.EchoSelect
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSheetContent
import com.echoledger.mobile.ui.v2.components.EchoStatus
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTabs
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoToast
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.components.EchoTranscriptLine
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.samples.SampleContent
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** Every state of each v2 component, with real content in the theme's language. */
object ComponentGalleries {
    /** The galleries by name: the name is the screenshot's file name. */
    val all: Map<String, @Composable (Boolean) -> Unit> = linkedMapOf(
        "button" to { ur -> Buttons(ur) },
        "text-field" to { ur -> TextFields(ur) },
        "search-field" to { ur -> SearchFields(ur) },
        "select" to { ur -> Selects(ur) },
        "list-row" to { ur -> ListRows(ur) },
        "filter-bar" to { ur -> FilterBars(ur) },
        "toast" to { ur -> Toasts(ur) },
        "dialog" to { ur -> Dialogs(ur) },
        "bottom-sheet" to { ur -> Sheets(ur) },
        "status" to { ur -> Statuses(ur) },
        "switch" to { ur -> Switches(ur) },
        "checkbox" to { ur -> Checkboxes(ur) },
        "radio" to { ur -> Radios(ur) },
        "empty-state" to { ur -> EmptyStates(ur) },
        "tabs-segmented" to { ur -> TabsAndSegmented(ur) },
        "bars" to { ur -> Bars(ur) },
        "player-transcript" to { ur -> PlayerAndTranscript(ur) }
    )
}

private fun t(ur: Boolean, en: String, urdu: String) = if (ur) urdu else en

@Composable
fun Gallery(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(
        modifier.fillMaxWidth().background(EchoV2.colors.bgCanvas).padding(vertical = EchoTokens.Space.s16),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12),
        content = content
    )
}

private val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)

@Composable
private fun Buttons(ur: Boolean) = Gallery {
    EchoButton(t(ur, "Transcribe", "ٹرانسکرپٹ بنائیں"), {}, pad, icon = EchoIcons.Transcribe)
    EchoButton(t(ur, "Share to WhatsApp", "واٹس ایپ پر شیئر کریں"), {}, pad, kind = EchoButtonKind.Secondary, icon = EchoIcons.Share)
    EchoButton(t(ur, "Not now", "ابھی نہیں"), {}, pad, kind = EchoButtonKind.Quiet)
    EchoButton(t(ur, "Delete 3 recordings", "3 ریکارڈنگز حذف کریں"), {}, pad, kind = EchoButtonKind.Danger)
    EchoButton(t(ur, "Uploading", "اپ لوڈ ہو رہی ہے"), {}, pad, loading = true)
    EchoButton(t(ur, "Upgrade", "اپ گریڈ کریں"), {}, pad, enabled = false)
    Row(pad, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
        EchoIconButton(EchoIcons.Search, t(ur, "Search", "تلاش"), {})
        EchoIconButton(EchoIcons.More, t(ur, "More options", "مزید"), {})
        EchoActionItem(EchoIcons.Move, t(ur, "Move", "منتقل کریں"), {})
        EchoActionItem(EchoIcons.Delete, t(ur, "Delete", "حذف کریں"), {}, danger = true)
    }
}

@Composable
private fun TextFields(ur: Boolean) = Gallery {
    EchoTextField("", {}, t(ur, "Workspace name", "ورک اسپیس کا نام"), pad, placeholder = t(ur, "Bilal Traders", "بلال ٹریڈرز"))
    EchoTextField(
        t(ur, "Rehman & Sons Textiles", "رحمان اینڈ سنز ٹیکسٹائل"),
        {},
        t(ur, "Client", "کلائنٹ"),
        pad,
        supportingText = t(ur, "Shown on every recording with this client", "اس کلائنٹ کی ہر ریکارڈنگ پر نظر آئے گا")
    )
    EchoTextField("0300 12", {}, t(ur, "Phone number", "فون نمبر"), pad, error = t(ur, "Enter all 11 digits", "پورے 11 ہندسے لکھیں"))
    EchoTextField("ayesha@bilaltraders.pk", {}, t(ur, "Email", "ای میل"), pad, enabled = false)
}

@Composable
private fun SearchFields(ur: Boolean) = Gallery {
    EchoSearchField("", {}, t(ur, "Search recordings", "ریکارڈنگز تلاش کریں"), pad)
    EchoSearchField(t(ur, "basmati", "باسمتی"), {}, t(ur, "Search recordings", "ریکارڈنگز تلاش کریں"), pad)
}

@Composable
private fun Selects(ur: Boolean) = Gallery {
    val options = if (ur) {
        listOf(
            "اردو",
            "انگریزی",
            "اردو اور انگریزی",
            "پنجابی"
        )
    } else {
        listOf("Urdu", "English", "Urdu and English", "Punjabi")
    }
    var chosen by remember { mutableStateOf(options[2]) }
    EchoSelect(t(ur, "Transcript language", "ٹرانسکرپٹ کی زبان"), options, chosen, { chosen = it }, { it }, pad)
    EchoSelect(t(ur, "Keep recordings", "ریکارڈنگز کب تک رکھیں"), listOf("90"), "90", {
    }, { t(ur, "$it days", "$it دن") }, pad, enabled = false)
}

@Composable
private fun ListRows(ur: Boolean) = Gallery {
    val c = EchoV2.colors
    val content = SampleContent.of(if (ur) EchoLanguage.Urdu else EchoLanguage.English)
    content.items.take(2).forEach { item ->
        EchoListRow(
            title = item.title,
            meta = item.meta,
            titleEnd = item.time,
            metaEnd = { Text(formatClock(item.durationMs), style = EchoV2.type.mono, color = c.textSecondary) },
            status = item.status?.let { (label, kind) -> { EchoStatus(label, kind) } },
            leading = { EchoAvatar(icon = item.source.icon, contentDescription = content.sourceNames[item.source]) },
            tall = true,
            onClick = {}
        )
    }
    EchoDivider(inset = true)
    EchoSettingRow(t(ur, "Theme", "تھیم"), value = t(ur, "System", "سسٹم"), onClick = {})
    EchoSettingRow(
        t(
            ur,
            "Keep recordings",
            "ریکارڈنگز کب تک رکھیں"
        ),
        value = t(ur, "90 days", "90 دن"),
        icon = EchoIcons.Folder,
        onClick = {
        }
    )
    EchoSettingRow(
        t(ur, "Capture helper", "کیپچر ہیلپر"),
        subtitle = t(ur, "Records both sides of WhatsApp calls", "واٹس ایپ کال کی دونوں آوازیں ریکارڈ کرتا ہے"),
        trailing = { EchoStatus(t(ur, "Needs setup", "سیٹ اپ باقی"), EchoStatusKind.Warning) },
        onClick = {}
    )
}

@Composable
private fun FilterBars(ur: Boolean) = Gallery {
    val filters = listOf(
        EchoFilter("today", t(ur, "Today", "آج")),
        EchoFilter("untranscribed", t(ur, "Untranscribed", "ٹرانسکرپٹ باقی")),
        EchoFilter("starred", t(ur, "Starred", "پسندیدہ")),
        EchoFilter("voice", t(ur, "Voice notes", "وائس نوٹس"))
    )
    EchoFilterBar(filters, emptySet(), {})
    EchoFilterBar(filters, setOf("today", "untranscribed"), {})
}

@Composable
private fun Toasts(ur: Boolean) = Gallery {
    EchoToast(t(ur, "Deleted 3 recordings", "3 ریکارڈنگز حذف ہو گئیں"), EchoV2.strings.undo, {}, pad)
    EchoToast(t(ur, "Moved to Shaheen Pharma", "شاہین فارما میں منتقل ہو گئی"), null, {}, pad)
}

@Composable
private fun Dialogs(ur: Boolean) = Gallery {
    EchoDialogContent(
        t(ur, "Delete this recording?", "یہ ریکارڈنگ حذف کریں؟"),
        t(ur, "Bilal Traders, 12:08. It stays in Trash for 30 days.", "بلال ٹریڈرز، 12:08۔ یہ 30 دن تک ردی میں رہے گی۔"),
        t(ur, "Delete", "حذف کریں"),
        {},
        t(ur, "Cancel", "منسوخ"),
        {},
        destructive = true,
        modifier = pad
    )
    EchoDialogContent(
        t(ur, "Transcribe 4 recordings?", "4 ریکارڈنگز کا ٹرانسکرپٹ بنائیں؟"),
        t(ur, "This uses 41 of your 88 remaining minutes.", "اس میں آپ کے باقی 88 میں سے 41 منٹ لگیں گے۔"),
        t(ur, "Transcribe", "ٹرانسکرپٹ بنائیں"),
        {},
        t(ur, "Cancel", "منسوخ"),
        {},
        modifier = pad
    )
}

@Composable
private fun Sheets(ur: Boolean) = Gallery {
    var chosen by remember { mutableIntStateOf(0) }
    EchoSheetContent(t(ur, "Share", "شیئر"), onClose = {}) {
        listOf(
            t(ur, "WhatsApp", "واٹس ایپ"),
            t(ur, "Link to the transcript", "ٹرانسکرپٹ کا لنک"),
            t(ur, "Audio file", "آڈیو فائل")
        ).forEachIndexed { i, label -> EchoRadio(chosen == i, { chosen = i }, label) }
    }
}

@Composable
private fun Statuses(ur: Boolean) = Gallery {
    Column(pad, verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
        EchoStatus(t(ur, "Ready to record", "ریکارڈنگ کے لیے تیار"), EchoStatusKind.Success)
        EchoStatus(t(ur, "Needs setup", "سیٹ اپ باقی"), EchoStatusKind.Warning)
        EchoStatus(t(ur, "Recording 02:14", "ریکارڈنگ 02:14"), EchoStatusKind.Recording)
        EchoStatus(t(ur, "Failed", "ناکام"), EchoStatusKind.Danger)
        EchoStatus(t(ur, "Starter plan", "اسٹارٹر پلان"), EchoStatusKind.Accent)
        EchoStatus(t(ur, "Untranscribed", "ٹرانسکرپٹ باقی"), EchoStatusKind.Neutral)
        EchoCountBadge(3, t(ur, "3 untranscribed", "3 ٹرانسکرپٹ باقی"))
    }
}

@Composable
private fun Switches(ur: Boolean) = Gallery {
    var a by remember { mutableStateOf(true) }
    var b by remember { mutableStateOf(false) }
    EchoSwitch(
        a,
        {
            a = it
        },
        t(
            ur,
            "Auto-transcribe after calls",
            "کال کے بعد خودکار ٹرانسکرپٹ"
        ),
        supporting = t(ur, "Uses minutes from your plan", "آپ کے پلان کے منٹ استعمال ہوتے ہیں")
    )
    EchoSwitch(b, { b = it }, t(ur, "Record WhatsApp Business calls", "واٹس ایپ بزنس کالز ریکارڈ کریں"))
    EchoSwitch(true, {
    }, t(ur, "Live captions", "لائیو کیپشنز"), supporting = t(ur, "Available on the Pro plan", "پرو پلان میں دستیاب"), enabled = false)
}

@Composable
private fun Checkboxes(ur: Boolean) = Gallery {
    var a by remember { mutableStateOf(true) }
    var b by remember { mutableStateOf(false) }
    EchoCheckbox(a, { a = it }, t(ur, "Include the summary", "خلاصہ بھی شامل کریں"))
    EchoCheckbox(b, { b = it }, t(ur, "Include the audio file (4.2 MB)", "آڈیو فائل بھی شامل کریں (4.2 MB)"))
    EchoCheckbox(
        false,
        {
        },
        t(
            ur,
            "Include screen moments",
            "اسکرین لمحات شامل کریں"
        ),
        supporting = t(ur, "This recording has none", "اس ریکارڈنگ میں کوئی نہیں"),
        enabled = false
    )
}

@Composable
private fun Radios(ur: Boolean) = Gallery {
    var chosen by remember { mutableIntStateOf(1) }
    listOf(
        t(ur, "Do nothing", "کچھ نہ کریں"),
        t(ur, "Show the after-call card", "کال کے بعد کارڈ دکھائیں"),
        t(ur, "Transcribe at once", "فوراً ٹرانسکرپٹ بنائیں")
    ).forEachIndexed { i, label -> EchoRadio(chosen == i, { chosen = i }, label) }
}

@Composable
private fun EmptyStates(ur: Boolean) = Gallery {
    EchoEmptyState(
        t(ur, "No untranscribed recordings", "کوئی ریکارڈنگ ٹرانسکرپٹ کے بغیر نہیں"),
        t(ur, "Everything from today has a transcript.", "آج کی ہر ریکارڈنگ کا ٹرانسکرپٹ بن چکا ہے۔"),
        actionLabel = t(ur, "Show all recordings", "تمام ریکارڈنگز دکھائیں")
    )
}

@Composable
private fun TabsAndSegmented(ur: Boolean) = Gallery {
    val content = SampleContent.of(if (ur) EchoLanguage.Urdu else EchoLanguage.English)
    var tab by remember { mutableIntStateOf(0) }
    var seg by remember { mutableIntStateOf(0) }
    EchoTabs(content.tabs, tab, { tab = it })
    EchoSegmented(listOf(t(ur, "System", "سسٹم"), t(ur, "Light", "لائٹ"), t(ur, "Dark", "ڈارک")), seg, { seg = it }, pad)
}

@Composable
private fun Bars(ur: Boolean) = Gallery {
    val content = SampleContent.of(if (ur) EchoLanguage.Urdu else EchoLanguage.English)
    EchoTopBar(content.settings, onBack = {})
    EchoTopBar(content.recordings, large = true) { EchoIconButton(EchoIcons.Search, content.searchRecordings, {}) }
    EchoBottomBar(
        listOf(
            EchoDestination("home", content.home, EchoIcons.Home),
            EchoDestination("library", content.library, EchoIcons.Library),
            EchoDestination("live", content.live, EchoIcons.Live),
            EchoDestination("ask", content.ask, EchoIcons.Ask)
        ),
        "library",
        {}
    )
}

@Composable
private fun PlayerAndTranscript(ur: Boolean) = Gallery {
    val content = SampleContent.of(if (ur) EchoLanguage.Urdu else EchoLanguage.English)
    EchoPlayer(221_000, 728_000, playing = true, speed = "1.5×", labels = content.player, onPlayPause = {
    }, onSeek = {}, onSpeed = {}, modifier = pad)
    content.transcript.take(2).forEach { EchoTranscriptLine(it.speaker, it.time, it.text, isMe = it.isMe) }
    // A mixed line: English in an Urdu screen and the other way round get their own direction.
    EchoTranscriptLine(
        t(ur, "Bilal Ahmed", "بلال احمد"),
        "0:52",
        t(ur, "ٹھیک ہے، کل بات کرتے ہیں۔", "Please confirm the PO by Monday."),
        isMe = false
    )
}
