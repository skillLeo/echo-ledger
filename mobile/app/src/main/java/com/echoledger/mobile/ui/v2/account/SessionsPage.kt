package com.echoledger.mobile.ui.v2.account

import android.content.res.Resources
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.R
import com.echoledger.mobile.account.AccountFormat
import com.echoledger.mobile.account.AccountProblem
import com.echoledger.mobile.account.AccountResult
import com.echoledger.mobile.data.AccountDeviceDto
import com.echoledger.mobile.data.AccountSecurityDto
import com.echoledger.mobile.data.BrowserDto
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.settings.highlightedIf
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.util.Locale
import kotlinx.coroutines.launch

/** A device's line: its platform and app, its workspace, and when it was last active or signed out. */
fun deviceLine(res: Resources, d: AccountDeviceDto, locale: Locale): String = listOfNotNull(
    d.platform.takeIf { it.isNotBlank() }?.replaceFirstChar { it.titlecase(Locale.ROOT) },
    d.appVersion?.let { AccountFormat.ltr(it) },
    d.workspace?.name?.let { AccountFormat.isolate(it) },
    if (d.status == "signed_out") {
        res.getString(R.string.v2_acct_s_signed_out_on, AccountFormat.ltr(AccountFormat.day(d.signedOutAt, locale) ?: ""))
    } else {
        AccountFormat.dayAndTime(d.lastSeenAt, locale)?.let { res.getString(R.string.v2_acct_s_active, AccountFormat.ltr(it)) }
    }
).joinToString(" · ")

fun browserLine(res: Resources, b: BrowserDto, locale: Locale): String = listOfNotNull(
    b.country,
    AccountFormat.dayAndTime(b.lastActiveAt, locale)?.let { res.getString(R.string.v2_acct_s_active, AccountFormat.ltr(it)) }
).joinToString(" · ")

private sealed interface SessionAsk {
    data class Browser(val browser: BrowserDto) : SessionAsk
    data class SignOut(val device: AccountDeviceDto) : SessionAsk
    data class Rename(val device: AccountDeviceDto) : SessionAsk
}

/**
 * Browsers and devices (`GET /api/account/security`): the console browsers (Sign out) and the devices of every
 * workspace the person belongs to (Rename, Sign out; this phone is marked and signs out from Settings).
 */
@Composable
fun SessionsPage(
    security: AccountSecurityDto,
    onChanged: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    /** Settings › Devices: the account's recent activity under the devices (classic's Devices and activity). */
    activity: Boolean = false,
    /** A search result lit the devices. */
    lit: Boolean = false
) {
    val res = LocalContext.current.resources
    val locale = res.configuration.locales[0] ?: Locale.getDefault()
    val actions = accountActions()
    val say = rememberSay()
    val scope = rememberCoroutineScope()
    var ask by remember { mutableStateOf<SessionAsk?>(null) }
    var problem by remember { mutableStateOf<AccountProblem?>(null) }
    val signedOut = stringResource(R.string.v2_acct_s_signed_out)
    val renamed = stringResource(R.string.v2_acct_m_renamed)
    val thisPhone = stringResource(R.string.v2_acct_s_this_phone)

    fun run(call: suspend () -> AccountResult<Unit>) {
        scope.launch {
            when (val r = call()) {
                is AccountResult.Done -> {
                    say(signedOut)
                    onChanged()
                }

                is AccountResult.Failed -> problem = r.problem
            }
        }
    }

    AccountPage(stringResource(R.string.v2_acct_sessions), onBack, modifier) {
        FormColumn { ProblemBanner(problem) }
        EchoSectionHeader(stringResource(R.string.v2_acct_s_browsers))
        if (security.browsers.isEmpty()) FormColumn { HelpLine(stringResource(R.string.v2_acct_s_no_browsers)) }
        security.browsers.forEach { b ->
            val signOutOf = stringResource(R.string.v2_acct_s_sign_out_of, b.agent)
            EchoSettingRow(b.agent, subtitle = browserLine(res, b, locale), trailing = {
                EchoButton(
                    stringResource(R.string.v2_acct_s_sign_out),
                    { ask = SessionAsk.Browser(b) },
                    Modifier.semantics { contentDescription = signOutOf },
                    kind = EchoButtonKind.Quiet
                )
            })
        }
        EchoSectionHeader(stringResource(R.string.v2_acct_s_devices), Modifier.highlightedIf(lit))
        security.devices.forEach { d ->
            val title = if (d.current) "${AccountFormat.isolate(d.name)} · $thisPhone" else AccountFormat.isolate(d.name)
            EchoSettingRow(title, subtitle = deviceLine(res, d, locale))
            if (d.status != "signed_out") {
                Row(Modifier.fillMaxWidth().then(PagePad), horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                    val renameOf = stringResource(R.string.v2_acct_s_rename_of, d.name)
                    val signOutOf = stringResource(R.string.v2_acct_s_sign_out_of, d.name)
                    EchoButton(
                        stringResource(R.string.v2_acct_rename),
                        { ask = SessionAsk.Rename(d) },
                        Modifier.semantics { contentDescription = renameOf },
                        kind = EchoButtonKind.Quiet
                    )
                    if (!d.current) {
                        EchoButton(
                            stringResource(R.string.v2_acct_s_sign_out),
                            { ask = SessionAsk.SignOut(d) },
                            Modifier.semantics { contentDescription = signOutOf },
                            kind = EchoButtonKind.Quiet
                        )
                    }
                }
            }
        }
        if (activity) RecentActivity(security.events)
    }

    when (val a = ask) {
        is SessionAsk.Browser -> EchoDialog(
            title = stringResource(R.string.v2_acct_s_sign_out_title, a.browser.agent),
            text = stringResource(R.string.v2_acct_s_sign_out_body),
            confirmLabel = stringResource(R.string.v2_acct_s_sign_out),
            onConfirm = {
                ask = null
                run { actions.signOutBrowser(a.browser.id) }
            },
            dismissLabel = v2Text.cancel,
            onDismiss = { ask = null },
            destructive = true
        )

        is SessionAsk.SignOut -> EchoDialog(
            title = stringResource(R.string.v2_acct_s_sign_out_title, AccountFormat.isolate(a.device.name)),
            text = stringResource(R.string.v2_acct_s_sign_out_body),
            confirmLabel = stringResource(R.string.v2_acct_s_sign_out),
            onConfirm = {
                ask = null
                run { actions.signOutDevice(a.device.id) }
            },
            dismissLabel = v2Text.cancel,
            onDismiss = { ask = null },
            destructive = true
        )

        is SessionAsk.Rename -> RenameSheet(
            current = a.device.name,
            label = stringResource(R.string.v2_acct_s_name),
            max = 60,
            save = { name ->
                actions.renameDevice(a.device.id, name).problemOr {
                    say(renamed)
                    onChanged()
                }
            },
            onDismiss = { ask = null }
        )

        null -> Unit
    }
}

/** Recent activity (classic's): what happened and who did it, newest first, the last 15. */
@Composable
private fun RecentActivity(events: List<com.echoledger.mobile.data.SecurityEventDto>) {
    EchoSectionHeader(stringResource(R.string.v2_dev_activity))
    if (events.isEmpty()) FormColumn { HelpLine(stringResource(R.string.v2_dev_activity_none)) }
    val console = stringResource(R.string.v2_dev_by_console)
    val app = stringResource(R.string.v2_dev_by_app)
    val aDevice = stringResource(R.string.v2_dev_by_device)
    events.take(ACTIVITY_SHOWN).forEach { e ->
        val who = when (e.actor) {
            "device" -> e.device?.let(AccountFormat::isolate) ?: aDevice
            "web" -> console
            else -> app
        }
        EchoSettingRow(
            "${e.summary.ifBlank { e.label }} · $who",
            // In the app's language (its months), never the phone's.
            subtitle = com.echoledger.mobile.ui.v2.library.LibraryModel.latinDigits(
                com.echoledger.mobile.ui.formatWhen(e.at, locale = appLocale())
            ),
            content = true
        )
    }
}

private const val ACTIVITY_SHOWN = 15

/** The locale of the language the screen speaks (the app's, from the theme's resources), never the phone's. */
@androidx.compose.runtime.Composable
internal fun appLocale(): java.util.Locale =
    androidx.compose.ui.platform.LocalContext.current.resources.configuration.locales[0] ?: java.util.Locale.ENGLISH
