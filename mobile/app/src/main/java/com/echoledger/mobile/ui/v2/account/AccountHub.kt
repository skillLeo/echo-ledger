package com.echoledger.mobile.ui.v2.account

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.R
import com.echoledger.mobile.account.AccountFormat
import com.echoledger.mobile.account.AccountProblem
import com.echoledger.mobile.account.AccountResult
import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.data.AccountSecurityDto
import com.echoledger.mobile.data.PasswordRulesDto
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.settings.highlightedIf
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** The account pages, one level under Sign-in & security. */
enum class AccountRoute { Hub, Profile, Email, Password, TwoStep, Methods, Sessions }

/**
 * Sign-in & security (contract 2.0.0-l): who is signed in with their picture, then Profile, Email, Password, Two-step
 * sign-in, Google and passkeys, and Browsers and devices; [onYourData] (export, sign out everywhere, delete) where the
 * page that has them is elsewhere. A 401 calls [onSignedOut], which goes back to sign-in.
 */
@Composable
fun AccountSecurityPage(
    serverUrl: String?,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    onYourData: (() -> Unit)? = null,
    onSignedOut: () -> Unit = {},
    lit: Boolean = false,
    start: AccountRoute = AccountRoute.Hub
) {
    val actions = accountActions()
    var route by rememberSaveable { mutableStateOf(start) }
    var account by remember { mutableStateOf<AccountDto?>(null) }
    var security by remember { mutableStateOf<AccountSecurityDto?>(null) }
    var rules by remember { mutableStateOf<PasswordRulesDto?>(null) }
    var problem by remember { mutableStateOf<AccountProblem?>(null) }
    var loading by remember { mutableStateOf(true) }
    var reload by remember { mutableIntStateOf(0) }
    LaunchedEffect(reload) {
        when (val a = actions.account()) {
            is AccountResult.Done -> {
                account = a.value
                problem = null
            }

            is AccountResult.Failed -> {
                problem = a.problem
                if (a.problem == AccountProblem.SignedOut) onSignedOut()
            }
        }
        security = actions.security().valueOrNull ?: security
        if (rules == null) rules = actions.passwordRules()
        loading = false
    }
    val back: () -> Unit = {
        route = AccountRoute.Hub
        reload++
    }
    // Registered before the pages, so a page's own Back (the recovery codes that can't be left unsaved) comes first.
    BackHandler(enabled = route != AccountRoute.Hub) { back() }
    val a = account
    val s = security
    if (route != AccountRoute.Hub && a != null) {
        val proof = ProofAccount(a.hasPassword, a.twoFactor, a.email)
        when (route) {
            AccountRoute.Profile -> ProfilePage(a, {
                account = it
            }, onEmail = { route = AccountRoute.Email }, onBack = back, modifier = modifier)

            AccountRoute.Email -> EmailPage(a, proof, { account = it }, onBack = back, modifier = modifier)

            AccountRoute.Password -> PasswordPage(a, rules, onDone = back, onBack = back, modifier = modifier)

            AccountRoute.TwoStep -> TwoStepPage(a, s?.twoFactor?.recoveryCodesLeft, onChanged = {
                reload++
            }, onBack = back, modifier = modifier)

            AccountRoute.Methods -> s?.let {
                SignInMethodsPage(a, it, serverUrl, onChanged = { reload++ }, onBack = back, modifier = modifier)
            }

            AccountRoute.Sessions -> s?.let { SessionsPage(it, onChanged = { reload++ }, onBack = back, modifier = modifier) }

            AccountRoute.Hub -> Unit
        }
        return
    }
    AccountPage(stringResource(R.string.v2_set_page_account), onBack, modifier) {
        when {
            a == null && loading -> EchoSkeletonList(rows = 4)

            a == null -> FormColumn {
                EchoBanner(
                    stringResource(R.string.v2_acct_load_failed),
                    EchoBannerKind.Error,
                    actionLabel = v2Text.tryAgain,
                    onAction = {
                        loading = true
                        reload++
                    }
                )
                ProblemBanner(problem?.takeIf { it == AccountProblem.SignedOut })
            }

            else -> AccountHubRows(a, s, lit, onOpen = { route = it }, onYourData = onYourData)
        }
    }
}

/** The hub's rows without the calls (tests). */
@Composable
fun AccountHubRows(
    account: AccountDto,
    security: AccountSecurityDto?,
    lit: Boolean,
    onOpen: (AccountRoute) -> Unit,
    onYourData: (() -> Unit)?
) {
    val c = EchoV2.colors
    FormColumn {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
            AccountAvatar(account.name ?: account.email, account.avatarUrl, account.avatarVersion, ownAvatarKey(account))
            Column(Modifier.weight(1f)) {
                Text(
                    account.name ?: account.email.orEmpty(),
                    style = EchoV2.type.content(account.name.orEmpty(), EchoV2.type.bodyStrong),
                    color = c.textPrimary,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
                account.email?.takeIf { account.name != null }?.let {
                    Text(
                        AccountFormat.ltr(it),
                        style = EchoV2.type.metaLatin,
                        color = c.textSecondary,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
                    )
                }
            }
        }
    }
    EchoSectionHeader(stringResource(R.string.v2_acct_section_signin), Modifier.highlightedIf(lit))
    EchoSettingRow(
        stringResource(
            R.string.v2_acct_profile
        ),
        subtitle = stringResource(R.string.v2_acct_profile_sub),
        icon = EchoIcons.Person,
        onClick = {
            onOpen(AccountRoute.Profile)
        }
    )
    EchoSettingRow(
        stringResource(R.string.v2_acct_email),
        value = if (account.pendingEmail !=
            null
        ) {
            stringResource(R.string.v2_acct_email_waiting_value)
        } else {
            account.email?.let(AccountFormat::ltr)
        },
        icon = EchoIcons.Account,
        onClick = { onOpen(AccountRoute.Email) }
    )
    EchoSettingRow(
        stringResource(R.string.v2_acct_password),
        value = stringResource(if (account.hasPassword) R.string.v2_acct_password_value_set else R.string.v2_acct_password_value_none),
        icon = EchoIcons.Key,
        onClick = { onOpen(AccountRoute.Password) }
    )
    EchoSettingRow(
        stringResource(R.string.v2_acct_two_step),
        value = stringResource(if (account.twoFactor) R.string.v2_set_value_on else R.string.v2_set_value_off),
        icon = EchoIcons.Lock,
        onClick = { onOpen(AccountRoute.TwoStep) }
    )
    if (security != null) {
        EchoSettingRow(stringResource(R.string.v2_acct_methods), icon = EchoIcons.Privacy, onClick = { onOpen(AccountRoute.Methods) })
        EchoSettingRow(
            stringResource(R.string.v2_acct_sessions),
            subtitle = stringResource(R.string.v2_acct_sessions_sub),
            icon = EchoIcons.Devices,
            onClick = { onOpen(AccountRoute.Sessions) }
        )
    }
    onYourData?.let { open ->
        EchoSectionHeader(stringResource(R.string.v2_acct_section_data))
        EchoSettingRow(stringResource(R.string.v2_acct_open_classic), icon = EchoIcons.Export, onClick = open)
    }
}
