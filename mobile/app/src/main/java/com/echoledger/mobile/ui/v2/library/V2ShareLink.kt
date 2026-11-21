package com.echoledger.mobile.ui.v2.library

import android.content.res.Resources
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
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
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextDirection
import com.echoledger.mobile.R
import com.echoledger.mobile.data.SentItemDto
import com.echoledger.mobile.data.ShareLinkDto
import com.echoledger.mobile.ui.LinkExpiry
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.copyLink
import com.echoledger.mobile.ui.shareLink
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoChip
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoRadio
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.screenLocale
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlinx.coroutines.launch

/** How long a new link works: Never, a day, a week, a month (classic's [LinkExpiry.CHOICES]). */
internal val LINK_DAYS = listOf(
    0 to R.string.v2_share_never,
    1 to R.string.v2_share_1_day,
    7 to R.string.v2_share_7_days,
    30 to R.string.v2_share_month
)

/** Who a link lets in, in the screen's words: "Anyone with the link", "Password", "Only ali@…". */
fun linkAudience(res: Resources, link: ShareLinkDto): String = when (link.audience) {
    "password" -> res.getString(R.string.v2_share_aud_password)

    "people" -> link.people.orEmpty().joinToString(", ").takeIf { it.isNotEmpty() }?.let { res.getString(R.string.v2_share_aud_people, it) }
        ?: res.getString(R.string.v2_share_aud_chosen)

    else -> res.getString(if (link.hasPassword) R.string.v2_share_aud_password else R.string.v2_share_anyone)
}

/** A link's line: "Revoked · 3 views · downloads on · until 29 Nov 2026" (classic's ShareText.meta), in the screen's words. */
fun linkMeta(res: Resources, link: ShareLinkDto, locale: Locale): String = listOfNotNull(
    if (link.revokedAt != null) res.getString(R.string.v2_share_revoked) else null,
    res.getQuantityString(R.plurals.v2_share_views, link.viewCount, link.viewCount),
    res.getString(if (link.allowDownload ?: link.includeAudio) R.string.v2_share_downloads_on else R.string.v2_share_no_downloads),
    link.expiresAt?.let { res.getString(R.string.v2_share_until, shareDate(it, locale)) }
).joinToString(" · ")

/** One sent item's line: their email (when the name is shown), when it was sent, whether they saved a copy. */
fun sentMeta(res: Resources, item: SentItemDto, locale: Locale): String = listOfNotNull(
    item.to?.email.takeIf { item.to?.name != null },
    item.sentAt?.let { res.getString(R.string.v2_share_sent_on, shareDate(it, locale)) },
    if (item.savedAt != null) res.getString(R.string.v2_share_saved_copy) else null
).joinToString(" · ")

/** "29 Nov 2026" in the screen's locale, with 0-9 digits; the server's text when it is not a date. */
internal fun shareDate(at: String, locale: Locale): String = runCatching {
    LibraryModel.latinDigits(OffsetDateTime.parse(at).format(DateTimeFormatter.ofPattern("d MMM yyyy", locale)))
}.getOrElse { at.take(10) }

/**
 * Share link on the v2 components (classic's ShareSheet, the same calls): anyone with the link, a password, or only
 * the people chosen; downloads on or off; how long it works. The new link is shown once with Copy and Share…, then
 * the links with their views and Revoke, and who it was sent to with Stop sharing; both end it for everyone, so they
 * ask first. For a recording ([sessionId]) or a project ([clientId]).
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun V2ShareLinkSheet(vm: MainViewModel, sessionId: String?, clientId: String?, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val res = context.resources
    val locale = screenLocale()
    val scope = rememberCoroutineScope()
    val toasts = LocalEchoToasts.current
    val c = EchoV2.colors
    var audience by remember { mutableStateOf("public") }
    var password by remember { mutableStateOf("") }
    var people by remember { mutableStateOf("") }
    var download by remember { mutableStateOf(true) }
    var days by remember { mutableIntStateOf(7) }
    var made by remember { mutableStateOf<ShareLinkDto?>(null) }
    var links by remember { mutableStateOf<List<ShareLinkDto>>(emptyList()) }
    var sent by remember { mutableStateOf<List<SentItemDto>>(emptyList()) }
    var working by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    var revoking by remember { mutableStateOf<String?>(null) }
    var stopping by remember { mutableStateOf<SentItemDto?>(null) }
    LaunchedEffect(reload) {
        links = if (clientId != null) vm.projectShares(clientId) else vm.shares(sessionId!!)
        sent = vm.sentOf(sessionId, clientId)
    }
    val cancel = stringResource(R.string.v2_cancel)
    revoking?.let { id ->
        EchoDialog(
            title = stringResource(R.string.v2_share_revoke_title),
            text = stringResource(R.string.v2_share_revoke_question),
            confirmLabel = stringResource(R.string.v2_share_revoke),
            onConfirm = {
                revoking = null
                scope.launch { if (vm.revokeShare(id)) reload++ }
            },
            dismissLabel = cancel,
            onDismiss = { revoking = null },
            destructive = true
        )
    }
    stopping?.let { item ->
        val who = item.to?.name ?: item.to?.email ?: stringResource(R.string.v2_share_them)
        EchoDialog(
            title = stringResource(R.string.v2_share_stop),
            text = stringResource(R.string.v2_share_stop_question, who),
            confirmLabel = stringResource(R.string.v2_share_stop),
            onConfirm = {
                stopping = null
                scope.launch { if (vm.stopSharing(item.id)) reload++ }
            },
            dismissLabel = cancel,
            onDismiss = { stopping = null },
            destructive = true
        )
    }
    val emails = LinkExpiry.emails(people)
    val ready = when (audience) {
        "password" -> password.length >= 6
        "people" -> emails.isNotEmpty()
        else -> true
    }
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_rec_share_link)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState())) {
            Column(pad) {
                listOf(
                    "public" to R.string.v2_share_anyone,
                    "password" to R.string.v2_share_anyone_password,
                    "people" to R.string.v2_share_people
                ).forEach { (value, words) ->
                    EchoRadio(selected = audience == value, onClick = { audience = value }, label = stringResource(words))
                }
                if (audience == "password") {
                    EchoTextField(password, { password = it.take(100) }, stringResource(R.string.v2_share_password), secret = true)
                }
                if (audience == "people") {
                    EchoTextField(
                        people,
                        { people = it },
                        stringResource(R.string.v2_share_emails),
                        supportingText = stringResource(R.string.v2_share_emails_help),
                        singleLine = false
                    )
                }
            }
            EchoSwitch(download, { download = it }, stringResource(R.string.v2_share_downloads))
            Column(pad, verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                Text(stringResource(R.string.v2_share_works_for), style = EchoV2.type.label, color = c.textSecondary)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                    LINK_DAYS.forEach { (d, words) -> EchoChip(stringResource(words), selected = days == d, onClick = { days = d }) }
                }
                EchoButton(
                    stringResource(R.string.v2_share_make),
                    onClick = {
                        working = true
                        scope.launch {
                            made = vm.createShareLink(
                                sessionId,
                                clientId,
                                audience,
                                password.takeIf { audience == "password" },
                                if (audience == "people") emails else emptyList(),
                                download,
                                LinkExpiry.at(days)
                            )
                            working = false
                            reload++
                        }
                    },
                    enabled = ready,
                    loading = working,
                    loadingText = stringResource(R.string.v2_share_making),
                    modifier = Modifier.fillMaxWidth()
                )
                made?.url?.let { url ->
                    // A link reads left to right in every language.
                    Text(url, style = EchoV2.type.mono.copy(textDirection = TextDirection.Ltr), color = c.textPrimary)
                    Text(stringResource(R.string.v2_share_shown_once), style = EchoV2.type.meta, color = c.textSecondary)
                    val copied = stringResource(R.string.v2_share_copied)
                    Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                        EchoButton(stringResource(R.string.v2_share_copy), {
                            copyLink(context, url)
                            scope.launch { toasts.show(copied) }
                        }, kind = EchoButtonKind.Secondary)
                        EchoButton(stringResource(R.string.v2_share_share), { shareLink(context, url) }, kind = EchoButtonKind.Quiet)
                    }
                }
            }
            if (links.isNotEmpty()) {
                EchoSectionHeader(stringResource(R.string.v2_share_links))
                links.forEach { link ->
                    ShareRow(
                        linkAudience(res, link),
                        linkMeta(res, link, locale),
                        action = stringResource(R.string.v2_share_revoke).takeIf { link.revokedAt == null },
                        onAction = { revoking = link.id }
                    )
                }
            }
            if (sent.isNotEmpty()) {
                EchoSectionHeader(stringResource(R.string.v2_share_sent_to))
                sent.forEach { item ->
                    ShareRow(
                        item.to?.name ?: item.to?.email.orEmpty(),
                        sentMeta(res, item, locale),
                        action = stringResource(R.string.v2_share_stop),
                        onAction = { stopping = item }
                    )
                }
            }
        }
    }
}

@Composable
private fun ShareRow(title: String, meta: String, action: String?, onAction: () -> Unit) {
    val c = EchoV2.colors
    Row(
        Modifier.fillMaxWidth().padding(start = EchoTokens.Layout.pagePaddingMobile, end = EchoTokens.Space.s8, top = EchoTokens.Space.s4),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Column(Modifier.weight(1f)) {
            Text(title, style = EchoV2.type.content(title, EchoV2.type.body), color = c.textPrimary)
            Text(meta, style = EchoV2.type.meta, color = c.textSecondary)
        }
        if (action != null) EchoButton(action, onAction, kind = EchoButtonKind.Quiet)
    }
}
