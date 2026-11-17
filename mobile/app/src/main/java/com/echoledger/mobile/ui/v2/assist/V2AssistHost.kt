package com.echoledger.mobile.ui.v2.assist

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.R

/**
 * The card on an [AssistAdapter]: replays the session so far, then follows the messages through [AssistCard.reduce],
 * and sends the card's requests, the "shown" reports and the consent line's copy. This is what the call pill's window
 * hosts once the phone core wires it.
 */
@Composable
fun V2AssistHost(adapter: AssistAdapter, modifier: Modifier = Modifier, startExpanded: Boolean = false) {
    var state by remember(adapter) { mutableStateOf(CardState(meetingType = adapter.meetingType)) }
    var expanded by remember(adapter) { mutableStateOf(startExpanded) }
    LaunchedEffect(adapter) {
        state = AssistCard.reduce(adapter.snapshot(), state)
        adapter.messages.collect { state = AssistCard.reduce(state, it) }
    }
    val context = LocalContext.current
    val consent = stringResource(R.string.v2_assist_consent)
    V2AssistCard(
        state = state,
        expanded = expanded,
        onExpand = { expanded = it },
        onSend = adapter::send,
        onCopyConsent = {
            context.getSystemService(Context.CLIPBOARD_SERVICE).let { it as? ClipboardManager }
                ?.setPrimaryClip(ClipData.newPlainText(consent, consent))
        },
        onShown = adapter::reportShown,
        modifier = modifier
    )
}
