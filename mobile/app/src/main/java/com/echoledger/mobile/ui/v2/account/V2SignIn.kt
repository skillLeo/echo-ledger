package com.echoledger.mobile.ui.v2.account

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.provider.Settings
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.KeyboardType
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import com.echoledger.mobile.R
import com.echoledger.mobile.core.PairingPayload
import com.echoledger.mobile.core.SignInFlow
import com.echoledger.mobile.core.SignInFlow.Step
import com.echoledger.mobile.ui.AppMark
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.QrScanner
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCheckbox
import com.echoledger.mobile.ui.v2.components.EchoDialog
import com.echoledger.mobile.ui.v2.components.EchoDivider
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.shell.VmMessages
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.delay

/**
 * Sign in on the new components (classic's PairingScreen, the same flow and calls): the email first; then the password
 * (or an emailed code) for an account, or a 6-digit code, a name and the Terms for a new one; the two-step code when
 * the account has one. Google and Passkey finish in the browser; the server is the production one unless another is
 * typed under Advanced; Other ways to sign in has the QR code, the one-time code and the browser.
 */
@Composable
fun V2SignInScreen(vm: MainViewModel) {
    val context = LocalContext.current
    val res = context.resources
    val focus = LocalFocusManager.current
    val c = EchoV2.colors
    val state by vm.signInState.collectAsState()
    val busy by vm.busy.collectAsState()
    val server by vm.signInServer.collectAsState()
    val options by vm.authOptions.collectAsState()
    var otherWays by rememberSaveable { mutableStateOf(false) }
    var advanced by rememberSaveable { mutableStateOf(MainViewModel.defaultServer.isBlank()) }
    LaunchedEffect(server) { vm.loadAuthOptions() }
    BackHandler(enabled = otherWays || state.step != Step.EMAIL) {
        if (otherWays) otherWays = false else vm.signInBack()
    }
    if (otherWays) {
        OtherWays(vm, server, onBack = { otherWays = false })
        return
    }
    fun openPage(path: String) {
        val base = server.ifBlank { MainViewModel.defaultServer }.trimEnd('/')
        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("$base$path")).addCategory(Intent.CATEGORY_BROWSABLE)) }
    }
    Column(
        Modifier.fillMaxSize().background(c.bgCanvas).navigationBarsPadding().imePadding().verticalScroll(rememberScrollState())
            .padding(horizontal = EchoTokens.Space.s24, vertical = EchoTokens.Space.s32),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
    ) {
        AppMark(EchoTokens.Space.s48)
        Text(
            stringResource(
                when (state.step) {
                    Step.EMAIL -> R.string.v2_in_title_email
                    Step.PASSWORD -> R.string.v2_in_title_password
                    Step.CODE -> R.string.v2_in_title_code
                    Step.PROFILE -> R.string.v2_in_title_profile
                    Step.OTP -> R.string.v2_in_title_otp
                }
            ),
            style = EchoV2.type.titleL,
            color = c.textPrimary,
            modifier = Modifier.semantics { heading() }
        )
        if (state.step != Step.EMAIL) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(state.email, style = EchoV2.type.body, color = c.textSecondary, modifier = Modifier.weight(1f))
                EchoButton(stringResource(R.string.v2_in_change), { vm.signInBack() }, kind = EchoButtonKind.Quiet, enabled = !busy)
            }
        }
        when (state.step) {
            Step.EMAIL -> {
                EchoTextField(
                    state.email,
                    { vm.signInEmail(it.trim()) },
                    stringResource(R.string.v2_in_email),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email)
                )
                EchoButton(
                    stringResource(if (busy) R.string.v2_in_one_moment else R.string.v2_in_continue),
                    {
                        focus.clearFocus()
                        vm.signInContinue()
                    },
                    enabled = !busy && SignInFlow.validEmail(state.email) && server.isNotBlank(),
                    modifier = Modifier.fillMaxWidth()
                )
                if (server.isNotBlank()) {
                    fun browser() {
                        val page = vm.browserSignInUrl(server, "")
                        runCatching {
                            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(page)).addCategory(Intent.CATEGORY_BROWSABLE))
                        }
                    }
                    Row(
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
                    ) {
                        EchoDivider(Modifier.weight(1f))
                        Text(stringResource(R.string.v2_in_or), style = EchoV2.type.meta, color = c.textSecondary)
                        EchoDivider(Modifier.weight(1f))
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
                        // Google's own "G", as Google's guidelines ask; both finish in the browser.
                        if (options?.google == true) {
                            val google = stringResource(R.string.v2_in_google_full)
                            EchoButton(
                                stringResource(R.string.v2_in_google),
                                ::browser,
                                kind = EchoButtonKind.Secondary,
                                enabled = !busy,
                                modifier = Modifier.weight(1f).semantics { contentDescription = google },
                                leading = {
                                    androidx.compose.foundation.Image(
                                        painterResource(R.drawable.ic_google_g),
                                        null,
                                        Modifier.size(EchoTokens.Icon.md)
                                    )
                                }
                            )
                        }
                        val passkey = stringResource(R.string.v2_in_passkey_full)
                        EchoButton(
                            stringResource(R.string.v2_in_passkey),
                            ::browser,
                            kind = EchoButtonKind.Secondary,
                            enabled = !busy,
                            modifier = Modifier.weight(1f).semantics { contentDescription = passkey },
                            icon = EchoIcons.Key
                        )
                    }
                }
                if (MainViewModel.defaultServer.isNotBlank()) {
                    EchoButton(
                        stringResource(if (advanced) R.string.v2_in_hide_advanced else R.string.v2_in_advanced),
                        { advanced = !advanced },
                        kind = EchoButtonKind.Quiet
                    )
                }
                if (advanced) {
                    EchoTextField(
                        server,
                        { vm.setSignInServer(it.trim()) },
                        stringResource(if (MainViewModel.defaultServer.isBlank()) R.string.v2_in_server else R.string.v2_in_other_server),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri)
                    )
                }
            }

            Step.PASSWORD -> {
                var password by remember { mutableStateOf("") }
                EchoTextField(password, { password = it }, stringResource(R.string.v2_in_password), secret = true)
                EchoButton(
                    stringResource(if (busy) R.string.v2_in_signing_in else R.string.v2_in_sign_in),
                    {
                        focus.clearFocus()
                        vm.signInWithPassword(password, null)
                    },
                    enabled = !busy && password.isNotEmpty(),
                    modifier = Modifier.fillMaxWidth()
                )
                if (state.canUseCode || state.methods.isEmpty()) {
                    EchoButton(stringResource(R.string.v2_in_forgot), { vm.signInUseCode() }, kind = EchoButtonKind.Quiet, enabled = !busy)
                }
            }

            Step.CODE -> {
                var code by remember { mutableStateOf("") }
                var now by remember { mutableLongStateOf(android.os.SystemClock.elapsedRealtime()) }
                LaunchedEffect(state.resendAtMs) {
                    while (SignInFlow.resendIn(state, now) > 0) {
                        delay(1_000)
                        now = android.os.SystemClock.elapsedRealtime()
                    }
                }
                Text(stringResource(R.string.v2_in_code_sent, state.email), style = EchoV2.type.body, color = c.textSecondary)
                EchoTextField(
                    code,
                    { code = it.filter(Char::isDigit).take(6) },
                    stringResource(R.string.v2_in_code),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword)
                )
                EchoButton(
                    stringResource(if (busy) R.string.v2_in_checking else R.string.v2_in_continue),
                    {
                        focus.clearFocus()
                        vm.signInWithCode(code, null)
                    },
                    enabled = !busy && code.length == 6,
                    modifier = Modifier.fillMaxWidth()
                )
                val wait = SignInFlow.resendIn(state, now)
                EchoButton(
                    if (wait > 0) stringResource(R.string.v2_in_send_again_in, wait) else stringResource(R.string.v2_in_send_again),
                    { vm.signInResend() },
                    kind = EchoButtonKind.Quiet,
                    enabled = !busy && wait == 0
                )
            }

            Step.PROFILE -> {
                var name by rememberSaveable { mutableStateOf("") }
                var accepted by rememberSaveable { mutableStateOf(false) }
                EchoTextField(name, { name = it.take(80) }, stringResource(R.string.v2_in_name))
                EchoCheckbox(accepted, { accepted = it }, stringResource(R.string.v2_in_accept))
                Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                    EchoButton(stringResource(R.string.v2_in_terms), { openPage("/terms") }, kind = EchoButtonKind.Quiet)
                    EchoButton(stringResource(R.string.v2_in_privacy), { openPage("/privacy") }, kind = EchoButtonKind.Quiet)
                }
                EchoButton(
                    stringResource(if (busy) R.string.v2_in_creating else R.string.v2_in_create),
                    {
                        focus.clearFocus()
                        vm.signInCreateAccount(name)
                    },
                    enabled = !busy && name.isNotBlank() && accepted,
                    modifier = Modifier.fillMaxWidth()
                )
                Text(stringResource(R.string.v2_in_free_plan), style = EchoV2.type.meta, color = c.textSecondary)
            }

            Step.OTP -> {
                var otp by remember { mutableStateOf("") }
                var password by remember { mutableStateOf("") }
                var code by remember { mutableStateOf("") }
                val afterPassword = state.otpAfter == Step.PASSWORD
                // The step before is sent again with the two-step code: keep what it needs.
                if (afterPassword) {
                    EchoTextField(password, { password = it }, stringResource(R.string.v2_in_password), secret = true)
                } else {
                    EchoTextField(code, { code = it.filter(Char::isDigit).take(6) }, stringResource(R.string.v2_in_code_email))
                }
                EchoTextField(otp, { otp = it.take(20) }, stringResource(R.string.v2_in_otp))
                EchoButton(
                    stringResource(if (busy) R.string.v2_in_signing_in else R.string.v2_in_sign_in),
                    {
                        focus.clearFocus()
                        if (afterPassword) vm.signInWithPassword(password, otp) else vm.signInWithCode(code, otp)
                    },
                    enabled = !busy && otp.isNotBlank() && (if (afterPassword) password.isNotEmpty() else code.length == 6),
                    modifier = Modifier.fillMaxWidth()
                )
            }
        }
        state.error?.let {
            Text(
                VmMessages.localize(res, it),
                style = EchoV2.type.body,
                color = c.danger,
                modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite }
            )
        }
        if (state.step == Step.EMAIL) {
            EchoButton(stringResource(R.string.v2_in_other_ways_full), { otherWays = true }, kind = EchoButtonKind.Quiet)
        }
        Text(stringResource(R.string.v2_in_encrypted), style = EchoV2.type.caption, color = c.textSecondary)
    }
}

/** The QR code and the one-time code (the console's "Sign in a device"), and the browser sign-in for passkeys. */
@Composable
private fun OtherWays(vm: MainViewModel, server: String, onBack: () -> Unit) {
    val context = LocalContext.current
    val focus = LocalFocusManager.current
    val c = EchoV2.colors
    var code by rememberSaveable { mutableStateOf("") }
    var scanning by rememberSaveable { mutableStateOf(false) }
    var cameraBlocked by rememberSaveable { mutableStateOf(false) }
    var confirm by remember { mutableStateOf<PairingPayload?>(null) }
    val busy by vm.busy.collectAsState()
    val cameraGranted = { ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED }
    val needCamera = stringResource(R.string.v2_in_camera_needed)
    val cameraPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) {
            cameraBlocked = false
            scanning = true
        } else {
            // "Don't ask again": the system dialog will not appear any more, so point at app settings instead.
            val activity = context as? android.app.Activity
            cameraBlocked = activity != null && !ActivityCompat.shouldShowRequestPermissionRationale(activity, Manifest.permission.CAMERA)
            if (!cameraBlocked) vm.showMessage(needCamera)
        }
    }
    if (scanning && !cameraGranted()) scanning = false
    BackHandler(enabled = scanning) { scanning = false }
    confirm?.let { payload ->
        EchoDialog(
            title = stringResource(R.string.v2_in_confirm_title),
            text = stringResource(R.string.v2_in_confirm_text, payload.url.removePrefix("https://").removePrefix("http://")),
            confirmLabel = stringResource(R.string.v2_in_sign_in),
            onConfirm = {
                confirm = null
                vm.pairWith(payload, "")
            },
            dismissLabel = stringResource(R.string.v2_cancel),
            onDismiss = { confirm = null }
        )
    }
    val target = server.ifBlank { MainViewModel.defaultServer }
    Box(Modifier.fillMaxSize().background(c.bgCanvas)) {
        Column(Modifier.fillMaxSize()) {
            EchoTopBar(stringResource(R.string.v2_in_other_ways), onBack = onBack)
            Column(
                Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = EchoTokens.Space.s24),
                verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
            ) {
                Text(stringResource(R.string.v2_in_qr_help), style = EchoV2.type.body, color = c.textSecondary)
                EchoButton(
                    stringResource(if (busy) R.string.v2_in_signing_in else R.string.v2_in_scan),
                    {
                        focus.clearFocus()
                        when {
                            cameraGranted() -> scanning = true

                            cameraBlocked -> context.startActivity(
                                Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:${context.packageName}"))
                            )

                            else -> cameraPermission.launch(Manifest.permission.CAMERA)
                        }
                    },
                    enabled = !busy,
                    icon = EchoIcons.Screens,
                    modifier = Modifier.fillMaxWidth()
                )
                if (cameraBlocked) Text(stringResource(R.string.v2_in_camera_off), style = EchoV2.type.meta, color = c.textSecondary)
                EchoTextField(code, { code = it }, stringResource(R.string.v2_in_one_time))
                EchoButton(
                    stringResource(if (busy) R.string.v2_in_signing_in else R.string.v2_in_with_code),
                    { vm.pair(target, code, "") },
                    kind = EchoButtonKind.Secondary,
                    enabled = !busy && code.isNotBlank(),
                    modifier = Modifier.fillMaxWidth()
                )
                EchoButton(
                    stringResource(R.string.v2_in_browser),
                    {
                        val page = vm.browserSignInUrl(target, "")
                        runCatching {
                            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(page)).addCategory(Intent.CATEGORY_BROWSABLE))
                        }
                    },
                    kind = EchoButtonKind.Secondary,
                    enabled = !busy && target.isNotBlank(),
                    modifier = Modifier.fillMaxWidth()
                )
                Box(Modifier.size(EchoTokens.Space.s16))
            }
        }
        if (scanning) {
            QrScanner(
                accept = { text -> PairingPayload.parse(text) != null },
                onResult = { text ->
                    scanning = false
                    PairingPayload.parse(text)?.let { confirm = it }
                },
                onCancel = { scanning = false },
                onError = { message ->
                    scanning = false
                    vm.showMessage(message)
                }
            )
        }
    }
}
