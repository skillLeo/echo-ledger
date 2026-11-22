package com.echoledger.mobile.ui.v2.settings

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
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
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import com.echoledger.mobile.R
import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.data.AccountExportDto
import com.echoledger.mobile.data.AccountSecurityDto
import com.echoledger.mobile.ui.v2.account.SessionsPage
import com.echoledger.mobile.ui.v2.account.accountActions
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** How often a making export is asked about again (classic's 5 s). */
private const val EXPORT_POLL_MS = 5_000L
private const val SECRET_MAX = 200

/** The account calls Your data makes (the view model's, or a test's). */
class DataActions(
    val account: suspend () -> AccountDto?,
    val startExport: suspend () -> AccountExportDto?,
    val exportStatus: suspend (String) -> AccountExportDto?,
    val signOutEverywhere: suspend () -> Unit,
    /** The password, or the authenticator code: the deletion date once asked for, or why not (a wrong password or code). */
    val deleteAccount: suspend (password: String?, code: String?) -> com.echoledger.mobile.account.AccountResult<String?>
)

/**
 * Your data on the v2 components (classic's Account page, the same calls): Export my data (one ZIP; it is made on the
 * server and looked at again every 5 s), Download the export, Try again; Sign out everywhere (asks); Delete my account
 * (the password, or the authenticator code when two-step is on, or the web page), and the deletion date while pending.
 */
@Composable
fun YourDataPage(actions: DataActions, serverUrl: String?, highlight: String?, onBack: () -> Unit, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val c = EchoV2.colors
    val scope = rememberCoroutineScope()
    var account by remember { mutableStateOf<AccountDto?>(null) }
    var export by remember { mutableStateOf<AccountExportDto?>(null) }
    var askSignOut by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { account = actions.account() }
    // While it is being made, asked about every 5 s: a check that fails (offline, the server busy) is tried again.
    LaunchedEffect(export?.id, export?.status) {
        val current = export ?: return@LaunchedEffect
        if (current.status != "making") return@LaunchedEffect
        while (true) {
            delay(EXPORT_POLL_MS)
            val next = runCatching { actions.exportStatus(current.id) }.getOrNull() ?: continue
            export = next
            break
        }
    }
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_data_title), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            account?.deletesAt?.let {
                EchoBanner(
                    stringResource(R.string.v2_data_deletes_on, com.echoledger.mobile.core.PlanFormat.dateWithYear(it) ?: it.take(10)),
                    EchoBannerKind.Warning,
                    pad
                )
            }
            Column(Modifier.highlightedIf(highlight == "account.export")) {
                EchoSectionHeader(stringResource(R.string.v2_data_title))
                Text(stringResource(R.string.v2_data_export_help), style = EchoV2.type.meta, color = c.textSecondary, modifier = pad)
                val e = export
                when {
                    e == null -> EchoButton(stringResource(R.string.v2_data_export), {
                        scope.launch { actions.startExport()?.let { export = it } }
                    }, pad, kind = EchoButtonKind.Secondary)

                    e.status == "making" -> Text(
                        stringResource(R.string.v2_data_making),
                        style = EchoV2.type.body,
                        color = c.textPrimary,
                        modifier = pad
                    )

                    e.status == "ready" && e.url != null -> EchoButton(stringResource(R.string.v2_data_download), {
                        runCatching {
                            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(e.url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                        }
                    }, pad, kind = EchoButtonKind.Secondary)

                    else -> EchoBanner(
                        stringResource(R.string.v2_data_export_failed),
                        EchoBannerKind.Error,
                        pad,
                        actionLabel = stringResource(R.string.v2_data_try_again),
                        onAction = { export = null }
                    )
                }
            }
            EchoSectionHeader(stringResource(R.string.v2_data_signin))
            Column(pad, verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                EchoButton(
                    stringResource(R.string.v2_data_everywhere),
                    { askSignOut = true },
                    Modifier.highlightedIf(highlight == "account.everywhere"),
                    kind = EchoButtonKind.Secondary
                )
                EchoButton(
                    stringResource(R.string.v2_data_delete),
                    { deleting = true },
                    Modifier.highlightedIf(highlight == "account.delete"),
                    kind = EchoButtonKind.Quiet
                )
            }
        }
    }
    if (askSignOut) {
        EchoDialog(
            title = stringResource(R.string.v2_data_everywhere_title),
            text = stringResource(R.string.v2_data_everywhere_body),
            confirmLabel = stringResource(R.string.v2_data_sign_out),
            onConfirm = {
                askSignOut = false
                scope.launch { actions.signOutEverywhere() }
            },
            dismissLabel = v2Text.cancel,
            onDismiss = { askSignOut = false },
            destructive = true
        )
    }
    if (deleting) {
        DeleteSheet(account, serverUrl, actions, onDone = { at ->
            deleting = false
            if (!at.isNullOrEmpty()) account = account?.copy(deletesAt = at)
        })
    }
}

/** Delete your account: the password (or the authenticator code), Delete; or the web page for a Google-only account. */
@Composable
private fun DeleteSheet(account: AccountDto?, serverUrl: String?, actions: DataActions, onDone: (String?) -> Unit) {
    val context = LocalContext.current
    val c = EchoV2.colors
    val scope = rememberCoroutineScope()
    val twoFactor = account?.twoFactor == true
    var secret by remember { mutableStateOf("") }
    var working by remember { mutableStateOf(false) }
    var problem by remember { mutableStateOf<String?>(null) }
    val res = context.resources
    EchoBottomSheet(onDismiss = { onDone(null) }, title = stringResource(R.string.v2_data_delete_title)) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            Text(stringResource(R.string.v2_data_delete_body), style = EchoV2.type.body, color = c.textPrimary)
            EchoTextField(
                secret,
                {
                    secret = it.take(SECRET_MAX)
                    problem = null
                },
                stringResource(if (twoFactor) R.string.v2_data_code else R.string.v2_data_password),
                error = problem,
                keyboardOptions = if (twoFactor) KeyboardOptions(keyboardType = KeyboardType.Number) else KeyboardOptions.Default,
                secret = !twoFactor
            )
            EchoButton(
                stringResource(R.string.v2_data_delete_confirm),
                {
                    working = true
                    scope.launch {
                        val answer = if (twoFactor) actions.deleteAccount(null, secret.trim()) else actions.deleteAccount(secret, null)
                        working = false
                        when (answer) {
                            // Asked for: the date shows on the page.
                            is com.echoledger.mobile.account.AccountResult.Done -> onDone(answer.value ?: "")

                            // Said under the field, in the screen's language; the sheet stays open to try again.
                            is com.echoledger.mobile.account.AccountResult.Failed ->
                                problem = com.echoledger.mobile.ui.v2.account.AccountWords.problem(res, answer.problem)
                        }
                    }
                },
                Modifier.fillMaxWidth(),
                kind = EchoButtonKind.Danger,
                enabled = secret.isNotBlank(),
                loading = working
            )
            com.echoledger.mobile.ui.AccountText.deletePage(serverUrl)?.let { page ->
                Text(
                    stringResource(if (account?.google == true) R.string.v2_data_elsewhere_google else R.string.v2_data_elsewhere),
                    style = EchoV2.type.meta,
                    color = c.textSecondary
                )
                EchoButton(stringResource(R.string.v2_data_on_web), {
                    runCatching {
                        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(page)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                    }
                    onDone(null)
                }, kind = EchoButtonKind.Quiet)
            }
        }
    }
}

/**
 * Devices on the v2 components: Sign-in & security's Browsers and devices (each signed-in phone, computer and console
 * session with when it was seen; Rename; Sign out, which asks) and the account's recent activity under them.
 */
@Composable
fun DevicesPage(highlight: String?, onBack: () -> Unit, modifier: Modifier = Modifier) {
    val actions = accountActions()
    var security by remember { mutableStateOf<AccountSecurityDto?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    LaunchedEffect(reload) {
        security = actions.security().valueOrNull ?: security
        loaded = true
    }
    val s = security
    if (s != null) {
        SessionsPage(s, onChanged = { reload++ }, onBack = onBack, modifier = modifier, activity = true, lit = highlight == "devices.list")
        return
    }
    Column(modifier.fillMaxSize().background(EchoV2.colors.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_set_page_devices), onBack = onBack)
        if (!loaded) {
            EchoSkeletonList(rows = 3)
        } else {
            EchoBanner(
                stringResource(R.string.v2_dev_load_failed),
                EchoBannerKind.Error,
                Modifier.padding(EchoTokens.Layout.pagePaddingMobile),
                actionLabel = stringResource(R.string.v2_data_try_again),
                onAction = {
                    loaded = false
                    reload++
                }
            )
        }
    }
}
