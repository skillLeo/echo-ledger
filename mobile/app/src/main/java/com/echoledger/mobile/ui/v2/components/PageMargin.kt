package com.echoledger.mobile.ui.v2.components

import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.unit.Dp
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * The side margin of the page being drawn: the phone's usual one, or One UI's 24 dp where a page asks for it (the
 * Library, v2.1 UX-14). The top bar, the list rows and the group headings follow it, so a page stays aligned.
 */
val LocalPageMargin = staticCompositionLocalOf<Dp> { EchoTokens.Layout.pagePaddingMobile }
