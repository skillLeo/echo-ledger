package com.echoledger.mobile.ui.v2.help

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.R
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCard
import com.echoledger.mobile.ui.v2.components.EchoCheckbox
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoErrorState
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSearchField
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoTabs
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.screenLocale
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.text.NumberFormat
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** What a help call shows meanwhile, when it failed, and what it got. */
private sealed interface Load<out T> {
    data object Loading : Load<Nothing>
    data class Failed(val failed: HelpResult.Failed) : Load<Nothing>
    data class Ready<T>(val value: T) : Load<T>
}

/** Words for a failed call: the connection sentence offline, else the server's plain words, else ours. */
@Composable
private fun failedWords(failed: HelpResult.Failed): String = when {
    failed.offline -> stringResource(R.string.v2_help_offline)
    failed.words != null -> failed.words
    failed.message != null -> stringResource(failed.message)
    else -> stringResource(R.string.v2_help_failed_generic)
}

/** Subject and message limits (contract 2.0.0-d). */
const val SUBJECT_MAX = 120
const val MESSAGE_MAX = 5000

/** How long typing settles before the guides are searched. */
private const val SEARCH_SETTLE_MS = 300L

/**
 * Help v2 (v1.8 P6.4, contract 2.0.0-d), as on the desktop: Guides (search, a guide to read, and the phone check),
 * What's new (Android's release notes) and Contact support (subject, message and, only when the phone has one, its last
 * report, said by date). Guides and notes are Markdown from the server, read by [Markdown] (bounded, no HTML, nothing
 * to tap). Everything goes through [HelpSource].
 */
@Composable
fun V2HelpScreen(source: HelpSource, onPhoneCheck: () -> Unit, onBack: () -> Unit, modifier: Modifier = Modifier, startTab: Int = 0) {
    var tab by rememberSaveable { mutableIntStateOf(startTab) }
    var openGuide by rememberSaveable { mutableStateOf<String?>(null) }
    openGuide?.let { id ->
        GuidePage(source, id, onBack = { openGuide = null }, modifier = modifier)
        return
    }
    BackHandler(onBack = onBack)
    Column(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_help_title), onBack = onBack)
        EchoTabs(
            listOf(
                stringResource(R.string.v2_help_tab_guides),
                stringResource(R.string.v2_help_tab_new),
                stringResource(R.string.v2_help_tab_contact)
            ),
            tab,
            onSelect = { tab = it }
        )
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            when (tab) {
                0 -> Guides(source, onOpen = { openGuide = it }, onPhoneCheck = onPhoneCheck)
                1 -> WhatsNew(source)
                else -> ContactSupport(source)
            }
        }
    }
}

@Composable
private fun Guides(source: HelpSource, onOpen: (String) -> Unit, onPhoneCheck: () -> Unit) {
    var query by rememberSaveable { mutableStateOf("") }
    var hits by remember { mutableStateOf<Load<List<GuideHit>>>(Load.Loading) }
    var attempt by remember { mutableIntStateOf(0) }
    LaunchedEffect(query, attempt) {
        if (query.isNotBlank()) delay(SEARCH_SETTLE_MS)
        hits = when (val r = source.guides(query.trim())) {
            is HelpResult.Ok -> Load.Ready(r.value)
            is HelpResult.Failed -> Load.Failed(r)
        }
    }
    EchoSearchField(
        query,
        { query = it },
        placeholder = stringResource(R.string.v2_help_search),
        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
    )
    when (val h = hits) {
        Load.Loading -> EchoSkeletonList(4, avatar = false)

        is Load.Failed -> EchoErrorState(
            stringResource(R.string.v2_help_guides_failed),
            failedWords(h.failed),
            actionLabel = stringResource(R.string.v2_help_try_again),
            onAction = { attempt++ }
        )

        is Load.Ready -> when {
            h.value.isEmpty() && query.isNotBlank() -> EchoEmptyState(
                stringResource(R.string.v2_help_no_match_title),
                stringResource(R.string.v2_help_no_match_body),
                icon = EchoIcons.Search
            )

            h.value.isEmpty() -> EchoEmptyState(
                stringResource(R.string.v2_help_no_guides_title),
                stringResource(R.string.v2_help_no_guides_body),
                icon = EchoIcons.Transcribe
            )

            else -> Column {
                h.value.forEach { g ->
                    EchoSettingRow(g.title, subtitle = g.excerpt?.takeIf { it.isNotBlank() }, content = true, onClick = { onOpen(g.id) })
                }
            }
        }
    }
    EchoSectionHeader(stringResource(R.string.v2_help_checks))
    EchoSettingRow(
        stringResource(R.string.v2_help_phone_check),
        subtitle = stringResource(R.string.v2_help_phone_check_help),
        icon = EchoIcons.Settings,
        onClick = onPhoneCheck
    )
}

/** One guide to read, with Back to the list. */
@Composable
private fun GuidePage(source: HelpSource, id: String, onBack: () -> Unit, modifier: Modifier) {
    BackHandler(onBack = onBack)
    var guide by remember(id) { mutableStateOf<HelpResult<Guide>?>(null) }
    var attempt by remember { mutableIntStateOf(0) }
    LaunchedEffect(id, attempt) { guide = source.guide(id) }
    val g = guide
    Column(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar((g as? HelpResult.Ok)?.value?.title.orEmpty(), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            when (g) {
                null -> EchoSkeletonList(4, avatar = false)

                is HelpResult.Failed -> EchoErrorState(
                    stringResource(R.string.v2_help_guide_failed),
                    failedWords(g),
                    actionLabel = stringResource(R.string.v2_help_try_again),
                    onAction = { attempt++ }
                )

                is HelpResult.Ok -> EchoMarkdown(
                    g.value.markdown,
                    Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12)
                )
            }
        }
    }
}

@Composable
private fun WhatsNew(source: HelpSource) {
    val c = EchoV2.colors
    var notes by remember { mutableStateOf<HelpResult<List<ReleaseNotes>>?>(null) }
    var attempt by remember { mutableIntStateOf(0) }
    LaunchedEffect(attempt) { notes = source.whatsNew() }
    val locale = screenLocale()
    when (val n = notes) {
        null -> EchoSkeletonList(3, avatar = false)

        is HelpResult.Failed -> EchoErrorState(
            stringResource(R.string.v2_help_new_failed),
            failedWords(n),
            actionLabel = stringResource(R.string.v2_help_try_again),
            onAction = { attempt++ }
        )

        is HelpResult.Ok -> if (n.value.isEmpty()) {
            EchoEmptyState(
                stringResource(R.string.v2_help_no_notes_title),
                stringResource(R.string.v2_help_no_notes_body),
                icon = EchoIcons.Transcribe
            )
        } else {
            Column(
                Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12),
                verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
            ) {
                n.value.forEach { r ->
                    EchoCard {
                        Text(stringResource(R.string.v2_help_version, r.version), style = EchoV2.type.bodyStrong, color = c.textPrimary)
                        r.releasedAt?.let { day(it, locale) }?.let { Text(it, style = EchoV2.type.caption, color = c.textSecondary) }
                        EchoMarkdown(r.notes)
                    }
                }
            }
        }
    }
}

/** "17 Nov 2026" in the phone's time zone; null when the time can't be read. */
fun day(iso: String, locale: Locale, zone: ZoneId = ZoneId.systemDefault()): String? = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(zone).format(DateTimeFormatter.ofPattern("d MMM yyyy", locale))
}.getOrNull()

/** "17 Nov, 10:42" in the phone's time zone; null when the time can't be read. */
fun dayAndTime(iso: String, locale: Locale, zone: ZoneId = ZoneId.systemDefault()): String? = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(zone).format(DateTimeFormatter.ofPattern("d MMM, HH:mm", locale))
}.getOrNull()

/** A message to support, with this phone's last report only when there is one and it's ticked. */
@Composable
private fun ContactSupport(source: HelpSource) {
    val c = EchoV2.colors
    val scope = rememberCoroutineScope()
    var subject by rememberSaveable { mutableStateOf("") }
    var message by rememberSaveable { mutableStateOf("") }
    var attach by rememberSaveable { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<HelpResult.Failed?>(null) }
    var sent by rememberSaveable { mutableStateOf(false) }
    val locale = screenLocale()
    val numbers = NumberFormat.getIntegerInstance(locale)
    Column(
        Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
    ) {
        if (sent) {
            EchoCard(Modifier.semantics { liveRegion = LiveRegionMode.Polite }) {
                Text(stringResource(R.string.v2_help_sent_title), style = EchoV2.type.bodyStrong, color = c.textPrimary)
                Text(stringResource(R.string.v2_help_sent_body), style = EchoV2.type.meta, color = c.textSecondary)
                EchoButton(stringResource(R.string.v2_help_write_another), {
                    sent = false
                    subject = ""
                    message = ""
                    attach = false
                }, kind = EchoButtonKind.Secondary)
            }
            return@Column
        }
        Text(stringResource(R.string.v2_help_contact_intro), style = EchoV2.type.meta, color = c.textSecondary)
        EchoTextField(subject, { subject = it.take(SUBJECT_MAX) }, label = stringResource(R.string.v2_help_subject))
        EchoTextField(
            message,
            { message = it.take(MESSAGE_MAX) },
            label = stringResource(R.string.v2_help_message),
            supportingText = stringResource(R.string.v2_help_count, numbers.format(message.length), numbers.format(MESSAGE_MAX)),
            singleLine = false
        )
        // Exactly which report goes with the message: this phone's last one, by date, or none (nothing to attach).
        val last = source.lastReportAt()?.let { dayAndTime(it, locale) }
        val reportLine = if (last !=
            null
        ) {
            stringResource(R.string.v2_help_report_last, last)
        } else {
            stringResource(R.string.v2_help_report_none)
        }
        EchoCheckbox(
            checked = attach && last != null,
            onCheckedChange = { attach = it },
            label = stringResource(R.string.v2_help_attach),
            supporting = if (last != null) reportLine + "\n" + stringResource(R.string.v2_help_report_what) else reportLine,
            enabled = last != null
        )
        error?.let { EchoBanner(failedWords(it), EchoBannerKind.Error) }
        EchoButton(
            stringResource(R.string.v2_help_send),
            {
                busy = true
                error = null
                scope.launch {
                    when (val r = source.send(subject.trim(), message.trim(), attach && last != null)) {
                        is HelpResult.Ok -> sent = true
                        is HelpResult.Failed -> error = r
                    }
                    busy = false
                }
            },
            Modifier.fillMaxWidth(),
            enabled = subject.isNotBlank() && message.isNotBlank(),
            loading = busy,
            loadingText = stringResource(R.string.v2_help_sending)
        )
    }
}
