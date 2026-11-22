package com.echoledger.mobile.ui.v2.settings

import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import com.echoledger.mobile.ui.v2.account.AccountSecurityPage

/**
 * Settings v2's Sign-in & security: the account pages of contract 2.0.0-l (profile, email, password, two-step, Google
 * and passkeys, browsers and devices), and Your data (export, sign out everywhere, delete my account), which a search
 * for one of those opens at once, lit.
 */
@Composable
fun AccountSettingsPage(
    serverUrl: String?,
    highlight: String?,
    data: DataActions,
    onSignedOut: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    val toData = highlight == "account.everywhere" || highlight == "account.export" || highlight == "account.delete"
    var yourData by remember(highlight) { mutableStateOf(toData) }
    if (yourData) {
        androidx.activity.compose.BackHandler { if (toData) onBack() else yourData = false }
        YourDataPage(data, serverUrl, highlight, onBack = { if (toData) onBack() else yourData = false }, modifier = modifier)
    } else {
        AccountSecurityPage(
            serverUrl = serverUrl,
            onBack = onBack,
            modifier = modifier,
            onYourData = { yourData = true },
            onSignedOut = onSignedOut,
            lit = highlight == "account.signin"
        )
    }
}
