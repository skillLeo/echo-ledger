package com.echoledger.mobile.ui.v2.account

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import com.echoledger.mobile.R
import com.echoledger.mobile.account.AccountProblem
import com.echoledger.mobile.account.AccountResult
import com.echoledger.mobile.account.PasswordCheck
import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.data.PasswordRulesDto
import com.echoledger.mobile.data.Proof
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/** Where a password problem is shown: under the current password, the new one, the code, or as a banner (null). */
fun passwordFieldOf(problem: AccountProblem?): String? = when (problem) {
    AccountProblem.WrongPassword -> "current"

    is AccountProblem.InvalidOtp -> "otp"

    is AccountProblem.Fields -> when {
        problem.of("new").isNotEmpty() -> "new"
        problem.of("current").isNotEmpty() -> "current"
        else -> null
    }

    else -> null
}

/**
 * Password (`PUT /api/account/password`): the current one and the new one (with the code when two-step is on), the
 * server's rules checked as it is typed, and Sign out other devices, on by default. An account made with Google sets
 * its first password with the proof sheet (an emailed code, or the authenticator's).
 */
@Composable
fun PasswordPage(account: AccountDto, rules: PasswordRulesDto?, onDone: () -> Unit, onBack: () -> Unit, modifier: Modifier = Modifier) {
    val res = LocalContext.current.resources
    val actions = accountActions()
    val say = rememberSay()
    val scope = rememberCoroutineScope()
    val setting = !account.hasPassword
    var current by remember { mutableStateOf("") }
    var new by remember { mutableStateOf("") }
    var otp by remember { mutableStateOf("") }
    var signOutOthers by remember { mutableStateOf(true) }
    var working by remember { mutableStateOf(false) }
    var asking by remember { mutableStateOf(false) }
    var problem by remember { mutableStateOf<AccountProblem?>(null) }
    val doneWords = stringResource(if (setting) R.string.v2_acct_pw_set_done else R.string.v2_acct_pw_changed)
    val local = PasswordCheck.problems(new, rules, current.takeIf { !setting })
    val field = passwordFieldOf(problem)
    val words = problem?.let { AccountWords.problem(res, it) }

    fun finished() {
        say(doneWords)
        onDone()
    }

    AccountPage(stringResource(if (setting) R.string.v2_acct_pw_title_set else R.string.v2_acct_password), onBack, modifier) {
        FormColumn {
            if (setting) HelpLine(stringResource(R.string.v2_acct_pw_set_help))
            if (!setting) {
                EchoTextField(
                    current,
                    {
                        current = it.take(200)
                        if (field == "current") problem = null
                    },
                    label = stringResource(R.string.v2_acct_pw_current),
                    error =
                        (problem as? AccountProblem.Fields)?.let { AccountWords.fields(res, it.of("current")) }
                            ?: words.takeIf { field == "current" },
                    secret = true
                )
            }
            EchoTextField(
                new,
                {
                    new = it.take(200)
                    if (field == "new") problem = null
                },
                label = stringResource(R.string.v2_acct_pw_new),
                error = (problem as? AccountProblem.Fields)?.let { AccountWords.fields(res, it.of("new")) },
                secret = true
            )
            RuleList(new, rules ?: PasswordCheck.DEFAULT)
            if (!setting && account.twoFactor) {
                EchoTextField(
                    otp,
                    {
                        otp = it.take(40)
                        if (field == "otp") problem = null
                    },
                    label = stringResource(R.string.v2_acct_pw_code),
                    error = words.takeIf { field == "otp" },
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii)
                )
            }
        }
        EchoSwitch(
            signOutOthers,
            { signOutOthers = it },
            stringResource(R.string.v2_acct_pw_sign_out),
            supporting = stringResource(R.string.v2_acct_pw_sign_out_help)
        )
        FormColumn {
            ProblemBanner(problem?.takeIf { field == null })
            EchoButton(
                stringResource(if (setting) R.string.v2_acct_pw_set else R.string.v2_acct_pw_change),
                {
                    if (setting) {
                        asking = true
                    } else {
                        working = true
                        scope.launch {
                            val proof = if (account.twoFactor) Proof(otp = otp) else null
                            when (val r = actions.changePassword(current, new, proof, signOutOthers)) {
                                is AccountResult.Done -> finished()
                                is AccountResult.Failed -> problem = r.problem
                            }
                            working = false
                        }
                    }
                },
                Modifier.fillMaxWidth(),
                enabled = local.none { it.code != "same_as_current" } && (setting || current.isNotEmpty()) &&
                    (setting || !account.twoFactor || otp.isNotBlank()),
                loading = working
            )
        }
    }
    if (asking) {
        ProofSheet(
            account = ProofAccount(account.hasPassword, account.twoFactor, account.email),
            confirmLabel = stringResource(R.string.v2_acct_pw_set),
            send = { p ->
                when (val r = actions.changePassword(null, new, p, signOutOthers)) {
                    is AccountResult.Done -> {
                        finished()
                        null
                    }

                    // A rule the new password breaks is said on the page, under it.
                    is AccountResult.Failed -> if (r.problem is AccountProblem.Fields) {
                        problem = r.problem
                        asking = false
                        null
                    } else {
                        r.problem
                    }
                }
            },
            onDone = { asking = false },
            onDismiss = { asking = false }
        )
    }
}

/** The rules as a checklist that ticks while the password is typed; each line says "done" or "not yet" to TalkBack. */
@Composable
fun RuleList(new: String, rules: PasswordRulesDto) {
    val c = EchoV2.colors
    val met = stringResource(R.string.v2_acct_rule_met)
    val unmet = stringResource(R.string.v2_acct_rule_unmet)
    val codes = PasswordCheck.problems(new, rules).map { it.code }.toSet()
    val lines = buildList {
        add(stringResource(R.string.v2_acct_pw_rule_min, rules.min) to (new.isNotEmpty() && "too_short" !in codes))
        if (rules.letters) add(stringResource(R.string.v2_acct_pw_rule_letters) to (new.isNotEmpty() && "needs_letters" !in codes))
        if (rules.numbers) add(stringResource(R.string.v2_acct_pw_rule_numbers) to (new.isNotEmpty() && "needs_numbers" !in codes))
    }
    Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
        lines.forEach { (words, ok) ->
            Row(
                Modifier.semantics(mergeDescendants = true) { contentDescription = "$words, ${if (ok) met else unmet}" },
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
            ) {
                Icon(
                    if (ok) EchoIcons.Check else EchoIcons.Close,
                    null,
                    Modifier.size(EchoTokens.Icon.sm),
                    tint = if (ok) c.success else c.textSecondary
                )
                Text(words, style = EchoV2.type.meta, color = if (ok) c.textPrimary else c.textSecondary)
            }
        }
    }
}
