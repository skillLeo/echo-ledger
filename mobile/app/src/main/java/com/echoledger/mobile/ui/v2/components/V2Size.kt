package com.echoledger.mobile.ui.v2.components

import androidx.compose.ui.unit.dp
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * Sizes the components need that the token set does not carry yet (icon sizes, hairlines, the avatar, the chip and the
 * toast time). Kept in this one place so they move into tokens/component without touching the components.
 */
internal object V2Size {
    val iconSm = 18.dp
    val icon = 24.dp
    val iconLg = 40.dp
    val hairline = 1.dp
    val focusRing = 2.dp
    val avatar = 40.dp
    val chipHeight = 32.dp
    val statusDot = 8.dp
    val spinner = 18.dp
    val handleWidth = 32.dp
    val handleHeight = 4.dp
    val tabIndicator = 2.dp

    /** The taller mobile row (two lines with a leading avatar): 64 + 8 = 72 dp. */
    val rowMobileTall = EchoTokens.Layout.rowMobile + EchoTokens.Space.s8

    /** component.toast.duration in tokens/component/components.json (not in the Kotlin output yet). */
    const val TOAST_MS = 6000L
}
