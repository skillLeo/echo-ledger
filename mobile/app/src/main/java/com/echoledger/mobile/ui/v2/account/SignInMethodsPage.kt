package com.echoledger.mobile.ui.v2.account

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import android.content.Intent
import android.net.Uri
import android.os.Build
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
import com.echoledger.mobile.account.PasskeyMaker
import com.echoledger.mobile.account.PasskeyOutcome
import com.echoledger.mobile.account.PhonePasskeyMaker
import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.data.AccountSecurityDto
import com.echoledger.mobile.data.PasskeyDto
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.util.Locale
import kotlinx.coroutines.launch

/** The console's stable passkeys link (docs/API.md section 8.4), or null before a server is known. */
fun passkeysPage(serverUrl: String?): String? =
    serverUrl?.trimEnd('/')?.takeIf { it.isNotBlank() }?.let { "$it/app/settings/security/passkeys" }

/** The activity under [context] (Credential Manager shows its sheet over it). */
tailrec fun Context.activity(): Activity? = when (this) {
    is Activity -> this
    is ContextWrapper -> baseContext.activity()
    else -> null
}

/** A passkey's line: when it was added and last used, in the screen's language. */
fun passkeyLine(res: android.content.res.Resources, p: PasskeyDto, locale: Locale): String = listOfNotNull(
    AccountFormat.day(p.createdAt, locale)?.let { res.getString(R.string.v2_acct_m_created, AccountFormat.ltr(it)) },
    AccountFormat.day(p.lastUsedAt, locale)?.let { res.getString(R.string.v2_acct_m_used, AccountFormat.ltr(it)) }
        ?: res.getString(R.string.v2_acct_m_never_used)
).joinToString(" · ")

/** What the page is asking about. */
private sealed interface MethodsAsk {
    data object Unlink : MethodsAsk
    data object Add : MethodsAsk
    data class Remove(val passkey: PasskeyDto) : MethodsAsk
    data class Rename(val passkey: PasskeyDto) : MethodsAsk
}

/**
 * Google and passkeys: Google's link with Unlink (proof), each passkey with Rename and Remove (proof), and Add a
 * passkey made with the phone's Credential Manager (proof, options, the phone's sheet, saved). On a server that isn't
 * set up for phone passkeys, "Manage passkeys on the website" instead.
 */
@Composable
fun SignInMethodsPage(
    account: AccountDto,
    security: AccountSecurityDto,
    serverUrl: String?,
    onChanged: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    maker: PasskeyMaker = PhonePasskeyMaker
) {
    val context = LocalContext.current
    val res = context.resources
    val locale = res.configuration.locales[0] ?: Locale.getDefault()
    val actions = accountActions()
    val say = rememberSay()
    val scope = rememberCoroutineScope()
    var ask by remember { mutableStateOf<MethodsAsk?>(null) }
    var problem by remember { mutableStateOf<AccountProblem?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var web by remember { mutableStateOf(!account.passkeysAvailable) }
    val proof = ProofAccount(account.hasPassword, account.twoFactor, account.email)
    val unlinked = stringResource(R.string.v2_acct_m_unlinked)
    val added = stringResource(R.string.v2_acct_m_added)
    val removed = stringResource(R.string.v2_acct_m_removed)
    val renamed = stringResource(R.string.v2_acct_m_renamed)

    fun openWeb() {
        passkeysPage(serverUrl)?.let { page ->
            runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(page)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
        }
    }

    AccountPage(stringResource(R.string.v2_acct_methods), onBack, modifier) {
        FormColumn {
            ProblemBanner(problem)
            notice?.let { EchoBanner(it, EchoBannerKind.Info) }
        }
        EchoSectionHeader(stringResource(R.string.v2_acct_m_google))
        EchoSettingRow(
            stringResource(R.string.v2_acct_m_google),
            value = stringResource(if (security.google.linked) R.string.v2_acct_m_linked else R.string.v2_acct_m_not_linked)
        )
        if (security.google.linked) {
            FormColumn {
                EchoButton(stringResource(R.string.v2_acct_m_unlink), { ask = MethodsAsk.Unlink }, kind = EchoButtonKind.Secondary)
            }
        }
        EchoSectionHeader(stringResource(R.string.v2_acct_m_passkeys))
        FormColumn { HelpLine(stringResource(R.string.v2_acct_m_passkeys_help)) }
        if (security.passkeys.isEmpty()) FormColumn { HelpLine(stringResource(R.string.v2_acct_m_no_passkeys)) }
        security.passkeys.forEach { p ->
            val name = p.name.ifBlank { stringResource(R.string.v2_acct_m_passkeys) }
            EchoSettingRow(name, subtitle = passkeyLine(res, p, locale), content = true)
            Row(
                Modifier.fillMaxWidth().then(PagePad),
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
            ) {
                val renameOf = stringResource(R.string.v2_acct_s_rename_of, name)
                val removeOf = stringResource(R.string.v2_acct_m_remove_title, name)
                EchoButton(
                    stringResource(R.string.v2_acct_rename),
                    { ask = MethodsAsk.Rename(p) },
                    Modifier.semantics { contentDescription = renameOf },
                    kind = EchoButtonKind.Quiet
                )
                EchoButton(
                    stringResource(R.string.v2_acct_remove),
                    { ask = MethodsAsk.Remove(p) },
                    Modifier.semantics { contentDescription = removeOf },
                    kind = EchoButtonKind.Quiet
                )
            }
        }
        FormColumn {
            if (web) {
                EchoButton(
                    stringResource(R.string.v2_acct_m_web),
                    ::openWeb,
                    Modifier.fillMaxWidth(),
                    kind = EchoButtonKind.Secondary,
                    enabled =
                        serverUrl != null
                )
            } else {
                EchoButton(stringResource(R.string.v2_acct_m_add), { ask = MethodsAsk.Add }, Modifier.fillMaxWidth())
            }
        }
    }

    when (val a = ask) {
        MethodsAsk.Unlink -> ProofSheet(
            account = proof,
            confirmLabel = stringResource(R.string.v2_acct_m_unlink),
            title = stringResource(R.string.v2_acct_m_unlink),
            lead = stringResource(R.string.v2_acct_m_unlink_body),
            send = { p ->
                when (val r = actions.unlinkGoogle(p)) {
                    is AccountResult.Done -> {
                        say(unlinked)
                        onChanged()
                        null
                    }

                    is AccountResult.Failed -> pageOrSheet(r.problem) { problem = it }
                }
            },
            onDone = { ask = null },
            onDismiss = { ask = null }
        )

        is MethodsAsk.Remove -> ProofSheet(
            account = proof,
            confirmLabel = stringResource(R.string.v2_acct_remove),
            title = stringResource(R.string.v2_acct_m_remove_title, a.passkey.name),
            lead = stringResource(R.string.v2_acct_m_remove_body),
            send = { p ->
                when (val r = actions.removePasskey(a.passkey.id, p)) {
                    is AccountResult.Done -> {
                        say(removed)
                        onChanged()
                        null
                    }

                    is AccountResult.Failed -> pageOrSheet(r.problem) { problem = it }
                }
            },
            onDone = { ask = null },
            onDismiss = { ask = null }
        )

        MethodsAsk.Add -> ProofSheet(
            account = proof,
            confirmLabel = stringResource(R.string.v2_acct_continue),
            title = stringResource(R.string.v2_acct_m_add),
            send = { p ->
                when (val r = actions.passkeyOptions(p)) {
                    is AccountResult.Failed -> pageOrSheet(r.problem) {
                        problem = it
                        if (it == AccountProblem.PasskeysUnavailable) web = true
                    }

                    is AccountResult.Done -> {
                        ask = null
                        scope.launch {
                            problem = null
                            notice = null
                            when (val made = maker.create(context.activity() ?: context, r.value.options.toString())) {
                                is PasskeyOutcome.Made -> when (
                                    val saved = actions.savePasskey(
                                        r.value.ceremony,
                                        Build.MODEL.orEmpty().take(80),
                                        made.credential
                                    )
                                ) {
                                    is AccountResult.Done -> {
                                        say(added)
                                        onChanged()
                                    }

                                    is AccountResult.Failed -> problem = saved.problem
                                }

                                PasskeyOutcome.Cancelled -> Unit

                                PasskeyOutcome.NoProvider -> {
                                    notice = res.getString(R.string.v2_acct_m_no_provider)
                                    web = true
                                }

                                PasskeyOutcome.Failed -> notice = res.getString(R.string.v2_acct_m_failed)
                            }
                        }
                        null
                    }
                }
            },
            onDone = { ask = null },
            onDismiss = { ask = null }
        )

        is MethodsAsk.Rename -> RenameSheet(
            current = a.passkey.name,
            label = stringResource(R.string.v2_acct_m_passkey_name),
            max = 80,
            save = { name ->
                actions.renamePasskey(a.passkey.id, name).problemOr {
                    say(renamed)
                    onChanged()
                }
            },
            onDismiss = { ask = null }
        )

        null -> Unit
    }
}

/** A problem the page shows (the last way in, passkeys unavailable, too many) closes the sheet; the proof's stay in it. */
private fun pageOrSheet(problem: AccountProblem, onPage: (AccountProblem) -> Unit): AccountProblem? = when (problem) {
    AccountProblem.LastSignInMethod, AccountProblem.PasskeysUnavailable, AccountProblem.TooManyPasskeys, AccountProblem.NotFound -> {
        onPage(problem)
        null
    }

    else -> problem
}

/** A sheet with one name field and Save; [save] answers null when it worked, else the problem (shown under the field). */
@Composable
fun RenameSheet(current: String, label: String, max: Int, save: suspend (String) -> AccountProblem?, onDismiss: () -> Unit) {
    val res = LocalContext.current.resources
    val scope = rememberCoroutineScope()
    var name by remember { mutableStateOf(current) }
    var working by remember { mutableStateOf(false) }
    var problem by remember { mutableStateOf<AccountProblem?>(null) }
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_acct_rename)) {
        FormColumn {
            EchoTextField(
                name,
                {
                    name = it.take(max)
                    problem = null
                },
                label = label,
                error = problem?.let { AccountWords.problem(res, it) }
            )
            EchoButton(stringResource(R.string.v2_acct_save), {
                working = true
                scope.launch {
                    val p = save(name.trim())
                    working = false
                    if (p == null) onDismiss() else problem = p
                }
            }, Modifier.fillMaxWidth(), enabled = name.isNotBlank() && name.trim() != current, loading = working)
        }
    }
}
