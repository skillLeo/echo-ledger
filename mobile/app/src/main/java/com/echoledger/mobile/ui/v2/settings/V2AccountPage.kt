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
 * and passkeys, browsers and devices). Export, Sign out everywhere and Delete my account stay on their classic page,
 * which a search for them opens at once.
 */
@Composable
fun AccountSettingsPage(
    serverUrl: String?,
    highlight: String?,
    openClassicPage: @Composable (page: String, onBack: () -> Unit) -> Unit,
    onSignedOut: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    val toClassic = highlight == "account.everywhere" || highlight == "account.export" || highlight == "account.delete"
    var classic by remember(highlight) { mutableStateOf(toClassic) }
    if (classic) {
        openClassicPage("account") { if (toClassic) onBack() else classic = false }
    } else {
        AccountSecurityPage(
            serverUrl = serverUrl,
            onBack = onBack,
            modifier = modifier,
            onYourData = { classic = true },
            onSignedOut = onSignedOut,
            lit = highlight == "account.signin"
        )
    }
}
