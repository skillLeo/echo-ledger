package com.echoledger.mobile.ui

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.provider.Settings
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.QrCodeScanner
import androidx.compose.material.icons.rounded.Key
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import com.echoledger.mobile.core.PairingPayload
import com.echoledger.mobile.core.SignInFlow
import com.echoledger.mobile.core.SignInFlow.Step
import kotlinx.coroutines.delay

/**
 * Sign in (since v1.6, P1): one flow. The email first; then the password (or an emailed code) for an account, or a
 * 6-digit code, a name and the Terms for a new one; the two-step code when the account has one. "Continue with Google"
 * when the server offers it. The server is the production one unless another is typed under Advanced; the QR code and
 * one-time codes are under "Other ways to sign in".
 */
@Composable
fun PairingScreen(vm: MainViewModel) {
    val context = LocalContext.current
    val focus = LocalFocusManager.current
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
        OtherWaysToSignIn(vm, server, onBack = { otherWays = false })
        return
    }
    fun openPage(path: String) {
        val base = server.ifBlank { MainViewModel.defaultServer }.trimEnd('/')
        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("$base$path")).addCategory(Intent.CATEGORY_BROWSABLE)) }
    }

    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp)
    ) {
        Spacer(Modifier.height(40.dp))
        AppMark(44.dp)
        Text(
            when (state.step) {
                Step.EMAIL -> "Sign in or create an account"
                Step.PASSWORD -> "Welcome back"
                Step.CODE -> "Check your email"
                Step.PROFILE -> "Create your account"
                Step.OTP -> "Two-step sign-in"
            },
            style = LocalEchoType.current.display,
            color = Brand.Text
        )
        if (state.step != Step.EMAIL) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(state.email, color = Brand.Muted, modifier = Modifier.weight(1f))
                TextButton(onClick = { vm.signInBack() }, enabled = !busy) { Text("Change") }
            }
        }
        when (state.step) {
            Step.EMAIL -> EmailStep(
                vm,
                state,
                busy,
                server,
                advanced,
                onAdvanced = { advanced = !advanced },
                google =
                    options?.google == true
            )

            Step.PASSWORD -> {
                var password by remember { mutableStateOf("") }
                OutlinedTextField(
                    password,
                    { password = it },
                    label = { Text("Password") },
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                    modifier = Modifier.fillMaxWidth()
                )
                Button(
                    onClick = {
                        focus.clearFocus()
                        vm.signInWithPassword(password, null)
                    },
                    enabled = !busy && password.isNotEmpty(),
                    modifier = Modifier.fillMaxWidth().height(52.dp)
                ) { Text(if (busy) "Signing in…" else "Sign in", fontSize = 16.sp) }
                if (state.canUseCode || state.methods.isEmpty()) {
                    TextButton(onClick = { vm.signInUseCode() }, enabled = !busy) { Text("Forgot it? Email me a code instead") }
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
                Text("We sent a 6-digit code to ${state.email}. It works for 10 minutes.", color = Brand.Muted)
                OutlinedTextField(
                    code,
                    { code = it.filter(Char::isDigit).take(6) },
                    label = { Text("6-digit code") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                    modifier = Modifier.fillMaxWidth()
                )
                Button(
                    onClick = {
                        focus.clearFocus()
                        vm.signInWithCode(code, null)
                    },
                    enabled = !busy && code.length == 6,
                    modifier = Modifier.fillMaxWidth().height(52.dp)
                ) { Text(if (busy) "Checking…" else "Continue", fontSize = 16.sp) }
                val wait = SignInFlow.resendIn(state, now)
                TextButton(onClick = { vm.signInResend() }, enabled = !busy && wait == 0) {
                    Text(if (wait > 0) "Send again in $wait s" else "Send again")
                }
            }

            Step.PROFILE -> {
                var name by rememberSaveable { mutableStateOf("") }
                var accepted by rememberSaveable { mutableStateOf(false) }
                OutlinedTextField(name, {
                    name = it.take(80)
                }, label = { Text("Your name") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Checkbox(checked = accepted, onCheckedChange = { accepted = it })
                    Text("I accept the ", color = Brand.Text, fontSize = 14.sp)
                    TextButton(onClick = {
                        openPage("/terms")
                    }, contentPadding = androidx.compose.foundation.layout.PaddingValues(0.dp)) { Text("Terms") }
                    Text(" and ", color = Brand.Text, fontSize = 14.sp)
                    TextButton(onClick = {
                        openPage("/privacy")
                    }, contentPadding = androidx.compose.foundation.layout.PaddingValues(0.dp)) { Text("Privacy") }
                }
                Button(
                    onClick = {
                        focus.clearFocus()
                        vm.signInCreateAccount(name)
                    },
                    enabled = !busy && name.isNotBlank() && accepted,
                    modifier = Modifier.fillMaxWidth().height(52.dp)
                ) { Text(if (busy) "Creating…" else "Create account", fontSize = 16.sp) }
                Text("You start on the Free plan. You can upgrade any time.", color = Brand.Muted, fontSize = 13.sp)
            }

            Step.OTP -> {
                var otp by remember { mutableStateOf("") }
                var password by remember { mutableStateOf("") }
                var code by remember { mutableStateOf("") }
                val afterPassword = state.otpAfter == Step.PASSWORD
                // The step before is sent again with the two-step code: keep what it needs.
                if (afterPassword) {
                    OutlinedTextField(
                        password,
                        { password = it },
                        label = { Text("Password") },
                        singleLine = true,
                        visualTransformation = PasswordVisualTransformation(),
                        modifier = Modifier.fillMaxWidth()
                    )
                } else {
                    OutlinedTextField(code, {
                        code = it.filter(Char::isDigit).take(6)
                    }, label = { Text("6-digit code from the email") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                }
                OutlinedTextField(
                    otp,
                    { otp = it.take(20) },
                    label = { Text("Code from your authenticator app") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth()
                )
                Button(
                    onClick = {
                        focus.clearFocus()
                        if (afterPassword) vm.signInWithPassword(password, otp) else vm.signInWithCode(code, otp)
                    },
                    enabled = !busy && otp.isNotBlank() && (if (afterPassword) password.isNotEmpty() else code.length == 6),
                    modifier = Modifier.fillMaxWidth().height(52.dp)
                ) { Text(if (busy) "Signing in…" else "Sign in", fontSize = 16.sp) }
            }
        }
        state.error?.let {
            Text(it, color = Brand.Danger, fontSize = 14.sp, modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite })
        }
        if (state.step == Step.EMAIL) {
            TextButton(onClick = { otherWays = true }, modifier = Modifier.align(Alignment.CenterHorizontally)) {
                Text("Other ways to sign in (QR code, one-time code)")
            }
        }
        Text("Your recordings are encrypted on this phone and on the way to the server.", color = Brand.Muted, fontSize = 12.sp)
    }
}

@Composable
private fun androidx.compose.foundation.layout.ColumnScope.EmailStep(
    vm: MainViewModel,
    state: SignInFlow.State,
    busy: Boolean,
    server: String,
    advanced: Boolean,
    onAdvanced: () -> Unit,
    google: Boolean
) {
    val context = LocalContext.current
    val focus = LocalFocusManager.current
    OutlinedTextField(
        state.email,
        { vm.signInEmail(it.trim()) },
        label = { Text("Email") },
        singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
        modifier = Modifier.fillMaxWidth()
    )
    Button(
        onClick = {
            focus.clearFocus()
            vm.signInContinue()
        },
        enabled = !busy && SignInFlow.validEmail(state.email) && server.isNotBlank(),
        modifier = Modifier.fillMaxWidth().height(52.dp)
    ) { Text(if (busy) "One moment…" else "Continue", fontSize = 16.sp) }
    // Since v1.6.2: "or", then Google (its own "G", as Google's guidelines ask) and Passkey side by side; both finish in the
    // browser, where Google and passkeys work.
    fun openBrowserSignIn() {
        val page = vm.browserSignInUrl(server, "")
        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(page)).addCategory(Intent.CATEGORY_BROWSABLE)) }
    }
    if (server.isNotBlank()) {
        Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            androidx.compose.material3.HorizontalDivider(Modifier.weight(1f), color = Brand.Muted.copy(alpha = 0.3f))
            Text("or", color = Brand.Muted, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 12.dp))
            androidx.compose.material3.HorizontalDivider(Modifier.weight(1f), color = Brand.Muted.copy(alpha = 0.3f))
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            if (google) {
                OutlinedButton(
                    onClick = { openBrowserSignIn() },
                    enabled = !busy,
                    modifier = Modifier.weight(1f).height(52.dp).semantics { contentDescription = "Continue with Google" }
                ) {
                    androidx.compose.foundation.Image(
                        androidx.compose.ui.res.painterResource(com.echoledger.mobile.R.drawable.ic_google_g),
                        contentDescription = null,
                        modifier = Modifier.size(18.dp)
                    )
                    Spacer(Modifier.width(8.dp))
                    Text("Google", color = Brand.Text, maxLines = 1)
                }
            }
            OutlinedButton(
                onClick = { openBrowserSignIn() },
                enabled = !busy,
                modifier = Modifier.weight(1f).height(52.dp).semantics { contentDescription = "Sign in with a passkey" }
            ) {
                Icon(androidx.compose.material.icons.Icons.Rounded.Key, contentDescription = null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Passkey", color = Brand.Text, maxLines = 1)
            }
        }
    }
    // The server: the build's own (production) unless another is typed here; a build without one always asks.
    if (MainViewModel.defaultServer.isNotBlank()) {
        TextButton(onClick = onAdvanced, modifier = Modifier.align(Alignment.Start)) {
            Text(if (advanced) "Hide advanced" else "Advanced")
        }
    }
    if (advanced) {
        OutlinedTextField(
            server,
            { vm.setSignInServer(it.trim()) },
            label = { Text(if (MainViewModel.defaultServer.isBlank()) "Server address" else "Use a different server") },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
            modifier = Modifier.fillMaxWidth()
        )
    }
}

/** QR code and one-time sign-in code (the console's "Sign in a device"), and the browser sign-in for passkeys. */
@Composable
private fun OtherWaysToSignIn(vm: MainViewModel, server: String, onBack: () -> Unit) {
    val context = LocalContext.current
    val focusManager = LocalFocusManager.current
    var code by rememberSaveable { mutableStateOf("") }
    var scanning by rememberSaveable { mutableStateOf(false) }
    var cameraBlocked by rememberSaveable { mutableStateOf(false) }
    var confirm by remember { mutableStateOf<PairingPayload?>(null) }
    val busy by vm.busy.collectAsState()
    val cameraGranted = { ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED }
    val cameraPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) {
            cameraBlocked = false
            scanning = true
        } else {
            // "Don't ask again": the system dialog will not appear any more, so point at app settings instead.
            val activity = context as? android.app.Activity
            cameraBlocked = activity != null && !ActivityCompat.shouldShowRequestPermissionRationale(activity, Manifest.permission.CAMERA)
            if (!cameraBlocked) vm.showMessage("Camera access is needed to scan. You can still type the code.")
        }
    }
    // A process restart with the permission revoked would otherwise restore a scanner with no camera access.
    if (scanning && !cameraGranted()) scanning = false
    BackHandler(enabled = scanning) { scanning = false }

    confirm?.let { payload ->
        AlertDialog(
            onDismissRequest = { confirm = null },
            title = { Text("Sign in to this server?") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text(payload.url.removePrefix("https://").removePrefix("http://"), fontWeight = FontWeight.SemiBold)
                    Text(
                        "Recordings and transcripts from this phone will be sent only to this server.",
                        color = Brand.Muted,
                        fontSize = 13.sp
                    )
                }
            },
            confirmButton = {
                Button(onClick = {
                    confirm = null
                    vm.pairWith(payload, "")
                }) { Text("Sign in") }
            },
            dismissButton = { TextButton(onClick = { confirm = null }) { Text("Cancel") } }
        )
    }

    Box(Modifier.fillMaxSize()) {
        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp)
        ) {
            Spacer(Modifier.height(24.dp))
            TextButton(onClick = onBack) { Text("‹ Back") }
            Text("Other ways to sign in", style = LocalEchoType.current.titleM, color = Brand.Text)
            Text(
                "Signed in on a computer? In the console open Account → Devices → Sign in a device, then scan its QR code here.",
                color = Brand.Muted
            )
            Button(
                onClick = {
                    focusManager.clearFocus()
                    when {
                        cameraGranted() -> scanning = true

                        cameraBlocked -> context.startActivity(
                            Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:${context.packageName}"))
                        )

                        else -> cameraPermission.launch(Manifest.permission.CAMERA)
                    }
                },
                enabled = !busy,
                modifier = Modifier.fillMaxWidth().height(52.dp)
            ) {
                Icon(Icons.Outlined.QrCodeScanner, contentDescription = null, modifier = Modifier.size(22.dp))
                Spacer(Modifier.width(10.dp))
                Text(if (busy) "Signing in…" else "Scan QR code", fontSize = 16.sp)
            }
            if (cameraBlocked) {
                Text(
                    "Camera access is turned off for EchoLedger. Tap Scan QR code to open the app settings and allow it, or type the code.",
                    color = Brand.Muted,
                    fontSize = 12.sp
                )
            }
            OutlinedTextField(code, { code = it }, label = { Text("One-time sign-in code") }, modifier = Modifier.fillMaxWidth())
            OutlinedButton(
                onClick = { vm.pair(server.ifBlank { MainViewModel.defaultServer }, code, "") },
                enabled =
                    !busy && code.isNotBlank(),
                modifier = Modifier.fillMaxWidth()
            ) {
                Text(if (busy) "Signing in…" else "Sign in with the code")
            }
            OutlinedButton(
                onClick = {
                    val page = vm.browserSignInUrl(server.ifBlank { MainViewModel.defaultServer }, "")
                    runCatching {
                        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(page)).addCategory(Intent.CATEGORY_BROWSABLE))
                    }
                },
                enabled = !busy && server.ifBlank { MainViewModel.defaultServer }.isNotBlank(),
                modifier = Modifier.fillMaxWidth()
            ) { Text("Sign in in the browser (passkey)") }
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
