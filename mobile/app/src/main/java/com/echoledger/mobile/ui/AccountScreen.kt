package com.echoledger.mobile.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import com.echoledger.mobile.data.AccountDto
import com.echoledger.mobile.data.AccountExportDto
import com.echoledger.mobile.ui.design.BannerKind
import com.echoledger.mobile.ui.design.BarIcon
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.EchoTopBar
import com.echoledger.mobile.ui.design.InlineBanner
import com.echoledger.mobile.ui.design.SectionHeader
import com.echoledger.mobile.ui.design.SkeletonRow
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * The account this phone is signed in to (since v1.5): who and which workspace, the data export, signing out every
 * device, and deleting the account (confirmed with the password or a two-factor code; 7 days to change one's mind).
 */
@Composable
fun AccountScreen(vm: MainViewModel, onBack: () -> Unit) {
    val context = LocalContext.current
    val type = LocalEchoType.current
    val scope = rememberCoroutineScope()
    var account by remember { mutableStateOf<AccountDto?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    var export by remember { mutableStateOf<AccountExportDto?>(null) }
    var confirmSignOut by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf(false) }
    LaunchedEffect(reload) {
        account = vm.account()
        loaded = true
    }
    // The export is made on the server: look again every 5 s until it is ready or failed.
    LaunchedEffect(export?.id, export?.status) {
        val current = export ?: return@LaunchedEffect
        if (current.status != "making") return@LaunchedEffect
        delay(5_000)
        vm.exportStatus(current.id)?.let { export = it }
    }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(bottom = Space.s7)) {
        EchoTopBar("Account", large = false, navigation = {
            BarIcon(Icons.AutoMirrored.Rounded.ArrowBack, "Back", onBack, tint = Brand.Text)
        })
        val a = account
        when {
            !loaded -> SkeletonRow()

            a == null -> InlineBanner(
                "This phone is paired with the server, not signed in to an account (or the server is older).",
                Modifier.padding(horizontal = Space.screen),
                kind = BannerKind.Info,
                action = "Retry",
                onAction = { reload++ }
            )

            else -> Column(Modifier.padding(horizontal = Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s1)) {
                Text(a.name ?: a.email ?: "Your account", style = type.titleM, color = Brand.Text)
                a.email?.let { Text(it, style = type.bodyM, color = Brand.Muted) }
                Text(AccountText.workspace(a), style = type.labelM, color = Brand.Muted)
                Text(AccountText.security(a), style = type.labelM, color = Brand.Muted)
                a.deletesAt?.let {
                    InlineBanner(
                        "This account will be deleted on ${it.take(10)}. Sign in on the console and choose Keep my account to stop it.",
                        kind = BannerKind.Warning
                    )
                }
            }
        }
        SectionHeader("Your data")
        Column(Modifier.padding(horizontal = Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s2)) {
            Text(
                "One ZIP with every recording's audio, transcripts, screens, project files and notes.",
                style = type.caption,
                color = Brand.Muted
            )
            val e = export
            when {
                e == null -> EchoButton("Export my data", {
                    scope.launch { vm.startExport()?.let { export = it } }
                }, kind = ButtonKind.Secondary)

                e.status == "making" -> Text(
                    "Making the export… (you also get an email when it is ready)",
                    style = type.bodyM,
                    color = Brand.Text
                )

                e.status == "ready" && e.url != null -> EchoButton("Download the export", {
                    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(e.url))) }
                }, kind = ButtonKind.Secondary)

                else -> InlineBanner("The export could not be made.", kind = BannerKind.Error, action = "Try again", onAction = {
                    export =
                        null
                })
            }
        }
        SectionHeader("Sign-in")
        Column(Modifier.padding(horizontal = Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s2)) {
            EchoButton("Sign out everywhere…", { confirmSignOut = true }, kind = ButtonKind.Secondary)
            EchoButton("Delete my account…", { deleting = true }, kind = ButtonKind.Text)
        }
    }
    if (confirmSignOut) {
        AlertDialog(
            onDismissRequest = { confirmSignOut = false },
            title = { Text("Sign out everywhere?") },
            text = {
                Text(
                    "Every phone, computer and console session of this account is signed out, this phone too. Recordings waiting to upload stay on this phone."
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    confirmSignOut = false
                    scope.launch { vm.signOutEverywhere() }
                }) { Text("Sign out", color = Brand.Danger) }
            },
            dismissButton = { TextButton(onClick = { confirmSignOut = false }) { Text("Cancel") } }
        )
    }
    if (deleting) {
        DeleteAccountDialog(vm, account?.twoFactor == true, onDone = { deletesAt ->
            deleting = false
            if (deletesAt != null) account = account?.copy(deletesAt = deletesAt)
        })
    }
}

@Composable
private fun DeleteAccountDialog(vm: MainViewModel, twoFactor: Boolean, onDone: (String?) -> Unit) {
    val scope = rememberCoroutineScope()
    var secret by remember { mutableStateOf("") }
    var working by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = { onDone(null) },
        title = { Text("Delete your account?") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(Space.s2)) {
                Text(
                    "Your recordings, transcripts, screens, files and projects are deleted after 7 days. Until then you can keep the account by signing in to the console."
                )
                OutlinedTextField(
                    secret,
                    { secret = it.take(200) },
                    label = { Text(if (twoFactor) "Code from your authenticator app" else "Your password") },
                    singleLine = true,
                    visualTransformation = if (twoFactor) VisualTransformation.None else PasswordVisualTransformation(),
                    modifier = Modifier.fillMaxWidth()
                )
            }
        },
        confirmButton = {
            TextButton(enabled = secret.isNotBlank() && !working, onClick = {
                working = true
                scope.launch {
                    val at = if (twoFactor) vm.deleteAccount(null, secret.trim()) else vm.deleteAccount(secret, null)
                    working = false
                    if (at != null) onDone(at)
                }
            }) { Text("Delete", color = Brand.Danger) }
        },
        dismissButton = { TextButton(onClick = { onDone(null) }) { Text("Cancel") } }
    )
}

internal object AccountText {
    fun workspace(a: AccountDto): String {
        val role = when (a.workspace?.role) {
            "owner" -> "Owner"
            "admin" -> "Admin"
            "viewer" -> "Viewer"
            "member" -> "Member"
            else -> null
        }
        return listOfNotNull(a.workspace?.name, role).joinToString(" · ").ifEmpty { "Your workspace" }
    }

    fun security(a: AccountDto): String = listOfNotNull(
        if (a.twoFactor) "Two-factor sign-in on" else "Two-factor sign-in off",
        if (a.google) "Google linked" else null,
        if (!a.emailVerified) "Email not verified" else null
    ).joinToString(" · ")
}
