package com.echoledger.mobile.ui.v2.account

import android.content.ClipData
import android.content.ClipDescription
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.PersistableBundle
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import com.echoledger.mobile.R
import com.echoledger.mobile.account.AccountFormat
import com.echoledger.mobile.account.AccountProblem
import com.echoledger.mobile.account.AccountResult
import com.echoledger.mobile.account.AuthenticatorApp
import com.echoledger.mobile.account.QrCode
import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.data.TwoFactorSetupDto
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCard
import com.echoledger.mobile.ui.v2.components.EchoCheckbox
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/** Where the two-step page is. */
sealed interface TwoStepStep {
    data object Overview : TwoStepStep
    data class Scan(val setup: TwoFactorSetupDto) : TwoStepStep
    data class Codes(val codes: List<String>) : TwoStepStep
}

/** Copies [text] and marks it sensitive, so the phone doesn't show it in its clipboard preview (Android 13 and later). */
fun copySecret(context: Context, label: String, text: String) {
    val clip = ClipData.newPlainText(label, text)
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
        clip.description.extras = PersistableBundle().apply { putBoolean(ClipDescription.EXTRA_IS_SENSITIVE, true) }
    }
    (context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager)?.setPrimaryClip(clip)
}

/**
 * Two-step sign-in: turn on with the proof (`POST /api/account/two-factor`), scan the QR code or open the
 * otpauth link in an authenticator app (or type the key), confirm with its code, then the 8 recovery codes once, with
 * Copy, Share and Save, and "I saved them" before leaving. When on: new recovery codes and Turn off, each with a code.
 */
@Composable
fun TwoStepPage(account: AccountDto, codesLeft: Int?, onChanged: () -> Unit, onBack: () -> Unit, modifier: Modifier = Modifier) {
    var step by remember { mutableStateOf<TwoStepStep>(TwoStepStep.Overview) }
    when (val s = step) {
        TwoStepStep.Overview -> TwoStepOverview(account, codesLeft, onStarted = { step = TwoStepStep.Scan(it) }, onCodes = {
            step = TwoStepStep.Codes(it)
        }, onChanged = onChanged, onBack = onBack, modifier = modifier)

        is TwoStepStep.Scan -> {
            BackHandler { step = TwoStepStep.Overview }
            ScanStep(s.setup, onConfirmed = { step = TwoStepStep.Codes(it) }, onBack = { step = TwoStepStep.Overview }, modifier = modifier)
        }

        is TwoStepStep.Codes -> RecoveryCodesStep(s.codes, onDone = {
            step = TwoStepStep.Overview
            onChanged()
        }, modifier = modifier)
    }
}

@Composable
private fun TwoStepOverview(
    account: AccountDto,
    codesLeft: Int?,
    onStarted: (TwoFactorSetupDto) -> Unit,
    onCodes: (List<String>) -> Unit,
    onChanged: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier
) {
    val actions = accountActions()
    val say = rememberSay()
    var asking by remember { mutableStateOf<String?>(null) }
    var problem by remember { mutableStateOf<AccountProblem?>(null) }
    val offWords = stringResource(R.string.v2_acct_2s_off_done)
    val proof = ProofAccount(account.hasPassword, account.twoFactor, account.email)
    AccountPage(stringResource(R.string.v2_acct_two_step), onBack, modifier) {
        FormColumn {
            if (account.twoFactor) {
                Text(
                    codesLeft?.let { stringResource(R.string.v2_acct_2s_on_body, it) } ?: stringResource(R.string.v2_acct_2s_on_plain),
                    style = EchoV2.type.body,
                    color = EchoV2.colors.textPrimary
                )
                ProblemBanner(problem)
                EchoButton(stringResource(R.string.v2_acct_2s_new_codes), {
                    asking = "codes"
                }, Modifier.fillMaxWidth(), kind = EchoButtonKind.Secondary)
                EchoButton(stringResource(R.string.v2_acct_2s_turn_off), {
                    asking = "off"
                }, Modifier.fillMaxWidth(), kind = EchoButtonKind.Danger)
            } else {
                Text(stringResource(R.string.v2_acct_2s_off_body), style = EchoV2.type.body, color = EchoV2.colors.textPrimary)
                ProblemBanner(problem)
                EchoButton(stringResource(R.string.v2_acct_2s_turn_on), { asking = "on" }, Modifier.fillMaxWidth())
            }
        }
    }
    when (asking) {
        "on" -> ProofSheet(
            account = proof,
            confirmLabel = stringResource(R.string.v2_acct_continue),
            send = { p ->
                when (val r = actions.startTwoStep(p)) {
                    is AccountResult.Done -> {
                        onStarted(r.value)
                        null
                    }

                    is AccountResult.Failed -> if (r.problem == AccountProblem.AlreadyEnabled) {
                        problem = r.problem
                        onChanged()
                        null
                    } else {
                        r.problem
                    }
                }
            },
            onDone = { asking = null },
            onDismiss = { asking = null }
        )

        "codes" -> ProofSheet(
            account = proof,
            confirmLabel = stringResource(R.string.v2_acct_continue),
            title = stringResource(R.string.v2_acct_2s_new_codes),
            lead = stringResource(R.string.v2_acct_2s_new_codes_body),
            codeOnly = true,
            send = { p ->
                when (val r = actions.newRecoveryCodes(p.otp.orEmpty())) {
                    is AccountResult.Done -> {
                        onCodes(r.value.recoveryCodes)
                        null
                    }

                    is AccountResult.Failed -> if (r.problem == AccountProblem.NotEnabled) {
                        problem = r.problem
                        onChanged()
                        null
                    } else {
                        r.problem
                    }
                }
            },
            onDone = { asking = null },
            onDismiss = { asking = null }
        )

        "off" -> ProofSheet(
            account = proof,
            confirmLabel = stringResource(R.string.v2_acct_2s_turn_off),
            title = stringResource(R.string.v2_acct_2s_turn_off),
            lead = stringResource(R.string.v2_acct_2s_turn_off_body),
            codeOnly = true,
            send = { p ->
                when (val r = actions.turnOffTwoStep(p.otp.orEmpty())) {
                    is AccountResult.Done -> {
                        say(offWords)
                        onChanged()
                        null
                    }

                    is AccountResult.Failed -> if (r.problem == AccountProblem.RequiredForStaff) {
                        problem = r.problem
                        null
                    } else {
                        r.problem
                    }
                }
            },
            onDone = { asking = null },
            onDismiss = { asking = null }
        )
    }
}

@Composable
private fun ScanStep(setup: TwoFactorSetupDto, onConfirmed: (List<String>) -> Unit, onBack: () -> Unit, modifier: Modifier) {
    val context = LocalContext.current
    val res = context.resources
    val actions = accountActions()
    val say = rememberSay()
    val scope = rememberCoroutineScope()
    var noApp by remember { mutableStateOf(false) }
    var code by remember { mutableStateOf("") }
    var working by remember { mutableStateOf(false) }
    var problem by remember { mutableStateOf<AccountProblem?>(null) }
    val qr = remember(setup.otpauthUri) { runCatching { QrCode.bitmap(setup.otpauthUri).asImageBitmap() }.getOrNull() }
    val copied = stringResource(R.string.v2_acct_copied)
    val onWords = stringResource(R.string.v2_acct_2s_on_done)
    AccountPage(stringResource(R.string.v2_acct_two_step), onBack, modifier) {
        EchoSectionHeader(stringResource(R.string.v2_acct_2s_scan))
        FormColumn {
            qr?.let {
                val described = stringResource(R.string.v2_acct_2s_qr)
                EchoCard(Modifier.fillMaxWidth()) {
                    Image(
                        it,
                        contentDescription = described,
                        Modifier.align(Alignment.CenterHorizontally).size(EchoTokens.Space.s64 * 3)
                    )
                }
            }
            EchoButton(stringResource(R.string.v2_acct_2s_open_app), {
                noApp = !AuthenticatorApp.open(context, setup.otpauthUri)
            }, Modifier.fillMaxWidth(), kind = EchoButtonKind.Secondary)
            if (noApp) {
                EchoBanner(
                    stringResource(R.string.v2_acct_2s_no_app),
                    EchoBannerKind.Info,
                    Modifier.semantics {
                        liveRegion =
                            LiveRegionMode.Polite
                    }
                )
            }
            Text(stringResource(R.string.v2_acct_2s_key), style = EchoV2.type.label, color = EchoV2.colors.textPrimary)
            val spoken = stringResource(R.string.v2_acct_2s_key_spoken, AccountFormat.spoken(setup.secret))
            Text(
                AccountFormat.ltr(AccountFormat.grouped(setup.secret)),
                style = EchoV2.type.mono,
                color = EchoV2.colors.textPrimary,
                modifier = Modifier.semantics { contentDescription = spoken }
            )
            EchoButton(stringResource(R.string.v2_acct_2s_copy_key), {
                copySecret(context, res.getString(R.string.v2_acct_2s_key), setup.secret)
                say(copied)
            }, kind = EchoButtonKind.Quiet)
        }
        EchoSectionHeader(stringResource(R.string.v2_acct_2s_enter))
        FormColumn {
            EchoTextField(
                code,
                {
                    code = it.filter(Char::isDigit).take(setup.digits)
                    problem = null
                },
                label = stringResource(R.string.v2_acct_2s_code),
                error = problem?.takeIf { it is AccountProblem.InvalidOtp }?.let { AccountWords.problem(res, it) },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword)
            )
            ProblemBanner(problem?.takeIf { it !is AccountProblem.InvalidOtp })
            EchoButton(stringResource(R.string.v2_acct_2s_confirm), {
                working = true
                scope.launch {
                    when (val r = actions.confirmTwoStep(setup.ceremony, code)) {
                        is AccountResult.Done -> {
                            say(onWords)
                            onConfirmed(r.value.recoveryCodes)
                        }

                        is AccountResult.Failed -> problem = r.problem
                    }
                    working = false
                }
            }, Modifier.fillMaxWidth(), enabled = code.length == setup.digits, loading = working)
        }
    }
}

/**
 * The recovery codes, shown once: each read one character at a time by TalkBack, Copy, Share, Save as a file, and
 * "I saved them" before Done. Back stays here until then: the codes can't be seen again.
 */
@Composable
fun RecoveryCodesStep(codes: List<String>, onDone: () -> Unit, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val res = context.resources
    val say = rememberSay()
    val scope = rememberCoroutineScope()
    var saved by remember { mutableStateOf(false) }
    val text = AccountFormat.recoveryText(res.getString(R.string.v2_acct_rc_heading), codes)
    val copied = stringResource(R.string.v2_acct_copied)
    val fileSaved = stringResource(R.string.v2_acct_rc_file_saved)
    val saver = rememberLauncherForActivityResult(ActivityResultContracts.CreateDocument("text/plain")) { uri ->
        if (uri != null) {
            val ok = runCatching { context.contentResolver.openOutputStream(uri)?.use { it.write(text.toByteArray()) } }.isSuccess
            if (ok) say(fileSaved)
        }
    }
    BackHandler(enabled = !saved) {}
    AccountPage(stringResource(R.string.v2_acct_rc_title), onBack = { if (saved) onDone() }, modifier = modifier) {
        FormColumn {
            Text(stringResource(R.string.v2_acct_rc_body), style = EchoV2.type.body, color = EchoV2.colors.textPrimary)
            EchoCard(Modifier.fillMaxWidth()) {
                Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                    codes.forEachIndexed { i, code ->
                        val spoken = stringResource(R.string.v2_acct_rc_code, i + 1, AccountFormat.spoken(code))
                        Text(
                            AccountFormat.ltr(code),
                            style = EchoV2.type.mono,
                            color = EchoV2.colors.textPrimary,
                            modifier = Modifier.semantics { contentDescription = spoken }
                        )
                    }
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                EchoButton(stringResource(R.string.v2_acct_rc_copy), {
                    copySecret(context, res.getString(R.string.v2_acct_rc_heading), text)
                    say(copied)
                }, kind = EchoButtonKind.Secondary)
                EchoButton(stringResource(R.string.v2_acct_rc_share), {
                    val send = Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, text)
                    runCatching { context.startActivity(Intent.createChooser(send, null).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
                }, kind = EchoButtonKind.Secondary)
            }
            EchoButton(stringResource(R.string.v2_acct_rc_save), {
                runCatching { saver.launch("echoledger-recovery-codes.txt") }
            }, kind = EchoButtonKind.Quiet)
        }
        EchoCheckbox(saved, { saved = it }, stringResource(R.string.v2_acct_rc_saved_them))
        FormColumn {
            EchoButton(
                stringResource(R.string.v2_acct_done),
                onDone,
                Modifier.fillMaxWidth().padding(top = EchoTokens.Space.s8),
                enabled = saved
            )
        }
    }
}
