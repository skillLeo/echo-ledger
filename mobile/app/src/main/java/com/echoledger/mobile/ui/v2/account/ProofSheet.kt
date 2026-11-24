package com.echoledger.mobile.ui.v2.account

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import com.echoledger.mobile.R
import com.echoledger.mobile.account.AccountActions
import com.echoledger.mobile.account.AccountFormat
import com.echoledger.mobile.account.AccountProblem
import com.echoledger.mobile.account.AccountResult
import com.echoledger.mobile.account.ProofNeed
import com.echoledger.mobile.account.ProofNeeds
import com.echoledger.mobile.data.Proof
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/** Who is proving: what the account has, so the sheet asks for the right things. */
data class ProofAccount(val hasPassword: Boolean, val twoFactor: Boolean, val email: String?)

/**
 * The one proof sheet of every account change (docs/API.md "Proof"): it asks for what the account can give (the
 * password and, with two-step on, a code; an emailed code with Send code when there is neither), sends it with
 * [send], and says what was wrong in plain words. A `proof_required` or a missing two-step code asks again for what
 * the server names. [send] answers null when it worked (then [onDone]), or the problem.
 */
@Composable
fun ProofSheet(
    account: ProofAccount,
    confirmLabel: String,
    send: suspend (Proof) -> AccountProblem?,
    onDone: () -> Unit,
    onDismiss: () -> Unit,
    codeOnly: Boolean = false,
    title: String = stringResource(R.string.v2_acct_proof_title),
    /** What the change does, said before the proof ("You can't sign in with it any more."). */
    lead: String? = null
) {
    EchoBottomSheet(onDismiss = onDismiss, title = title) {
        lead?.let {
            Text(
                it,
                style = EchoV2.type.body,
                color = EchoV2.colors.textPrimary,
                modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
            )
        }
        ProofForm(account, confirmLabel, send, onDone, codeOnly)
    }
}

/** The proof sheet's form without its window (tests). */
@Composable
fun ProofForm(
    account: ProofAccount,
    confirmLabel: String,
    send: suspend (Proof) -> AccountProblem?,
    onDone: () -> Unit,
    codeOnly: Boolean = false,
    actions: AccountActions = accountActions()
) {
    val res = LocalContext.current.resources
    val scope = rememberCoroutineScope()
    var need by remember(account, codeOnly) { mutableStateOf(ProofNeeds.of(account.hasPassword, account.twoFactor, codeOnly = codeOnly)) }
    var password by remember { mutableStateOf("") }
    var code by remember { mutableStateOf("") }
    var emailCode by remember { mutableStateOf("") }
    var working by remember { mutableStateOf(false) }
    var sending by remember { mutableStateOf(false) }
    var sent by remember { mutableStateOf(false) }
    var problem by remember { mutableStateOf<AccountProblem?>(null) }
    // A problem shows under its field when the form has that field, else as a banner.
    val field = ProofNeeds.fieldOf(problem)?.takeIf {
        (it == "password" && need.password) || (it == "code" && need.code) || (it == "email_code" && need.emailCode)
    }
    val words = problem?.let { AccountWords.problem(res, it) }
    Column(
        Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
    ) {
        Text(intro(need, account), style = EchoV2.type.body, color = EchoV2.colors.textSecondary)
        if (need.password) {
            EchoTextField(
                password,
                {
                    password = it.take(200)
                    if (field == "password") problem = null
                },
                label = stringResource(R.string.v2_acct_proof_password),
                error = words.takeIf { field == "password" },
                secret = true
            )
        }
        if (need.code) {
            EchoTextField(
                code,
                {
                    code = it.take(40)
                    if (field == "code") problem = null
                },
                label = stringResource(R.string.v2_acct_proof_code),
                error = words.takeIf { field == "code" },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii)
            )
        }
        if (need.emailCode) {
            EchoButton(
                stringResource(if (sent) R.string.v2_acct_proof_resend else R.string.v2_acct_proof_send),
                {
                    sending = true
                    scope.launch {
                        when (val r = actions.sendProofCode()) {
                            is AccountResult.Done -> {
                                sent = true
                                problem = null
                            }

                            is AccountResult.Failed -> problem = r.problem
                        }
                        sending = false
                    }
                },
                kind = EchoButtonKind.Secondary,
                loading = sending
            )
            if (sent) {
                Text(
                    stringResource(R.string.v2_acct_proof_sent),
                    style = EchoV2.type.meta,
                    color = EchoV2.colors.textSecondary,
                    modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite }
                )
            }
            EchoTextField(
                emailCode,
                {
                    emailCode = it.filter(Char::isDigit).take(6)
                    if (field == "email_code") problem = null
                },
                label = stringResource(R.string.v2_acct_email_code),
                error = words.takeIf { field == "email_code" },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword)
            )
        }
        if (words != null && field == null) {
            EchoBanner(words, EchoBannerKind.Error, Modifier.semantics { liveRegion = LiveRegionMode.Polite })
        }
        EchoButton(
            confirmLabel,
            {
                working = true
                scope.launch {
                    val answer = send(need.proof(password, code, emailCode))
                    working = false
                    if (answer == null) {
                        onDone()
                    } else {
                        need = ProofNeeds.after(need, answer, account.hasPassword, account.twoFactor)
                        problem = answer
                    }
                }
            },
            Modifier.fillMaxWidth(),
            enabled = need.ready(password, code, emailCode),
            loading = working
        )
    }
}

@Composable
private fun intro(need: ProofNeed, account: ProofAccount): String = when {
    need.password && need.code -> stringResource(R.string.v2_acct_proof_both_body)

    need.code -> stringResource(R.string.v2_acct_proof_code_body)

    need.emailCode -> account.email?.let { stringResource(R.string.v2_acct_proof_email_body, AccountFormat.ltr(it)) }
        ?: stringResource(R.string.v2_acct_proof_email_body_plain)

    else -> stringResource(R.string.v2_acct_proof_password_body)
}

/** A call's answer as the proof sheet wants it: null when it worked, else the problem. */
fun <T> AccountResult<T>.problemOr(onDone: (T) -> Unit): AccountProblem? = when (this) {
    is AccountResult.Done -> {
        onDone(value)
        null
    }

    is AccountResult.Failed -> problem
}
