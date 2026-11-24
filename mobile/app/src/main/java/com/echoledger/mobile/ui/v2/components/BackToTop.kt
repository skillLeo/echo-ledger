package com.echoledger.mobile.ui.v2.components

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.foundation.ScrollState
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.runtime.Composable
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.R
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/**
 * Back to top (v2.1 UX-25): a small round ↑, 40 dp in a 48 dp touch area, at the list's bottom end, 16 dp from the
 * edges. It shows once the list has been scrolled more than one screen down and fades out at the top; a tap scrolls
 * smoothly to the top. Its name, also for screen readers, is "Back to top". Put it in a Box over the list
 * ([modifier] aligns it: BottomEnd).
 */
@Composable
fun EchoBackToTop(list: LazyListState, modifier: Modifier = Modifier) {
    val far by remember(list) { derivedStateOf { list.scrolledPastOneScreen() } }
    val scope = rememberCoroutineScope()
    BackToTopButton(far, modifier) { scope.launch { list.animateScrollToItem(0) } }
}

/** The same for a page that scrolls as one column (a project's page). */
@Composable
fun EchoBackToTop(scroll: ScrollState, modifier: Modifier = Modifier) {
    val far by remember(scroll) { derivedStateOf { scroll.viewportSize > 0 && scroll.value > scroll.viewportSize } }
    val scope = rememberCoroutineScope()
    BackToTopButton(far, modifier) { scope.launch { scroll.animateScrollTo(0) } }
}

@Composable
private fun BackToTopButton(visible: Boolean, modifier: Modifier, onTop: () -> Unit) {
    val words = stringResource(R.string.v2_back_to_top)
    AnimatedVisibility(visible, modifier.padding(EchoTokens.Space.s16), enter = fadeIn(), exit = fadeOut()) {
        Box(Modifier.size(EchoTokens.Layout.controlTouchMin), contentAlignment = Alignment.Center) {
            Surface(
                onClick = onTop,
                modifier = Modifier
                    .size(EchoTokens.Space.s40)
                    .semantics {
                        contentDescription = words
                        role = Role.Button
                    },
                shape = CircleShape,
                color = EchoV2.colors.bgSurface,
                contentColor = EchoV2.colors.textPrimary,
                shadowElevation = EchoTokens.Elevation.floating,
                border = androidx.compose.foundation.BorderStroke(EchoTokens.Border.hairline, EchoV2.colors.borderDefault)
            ) {
                Box(contentAlignment = Alignment.Center) { Icon(EchoIcons.BackToTop, null, Modifier.size(EchoTokens.Icon.md)) }
            }
        }
    }
}

/** More than one screen down: the rows above the first one shown are taller together than the list's own height. */
internal fun LazyListState.scrolledPastOneScreen(): Boolean {
    val info = layoutInfo
    val viewport = info.viewportEndOffset - info.viewportStartOffset
    if (viewport <= 0 || firstVisibleItemIndex == 0) return false
    val seen = info.visibleItemsInfo
    val average = if (seen.isEmpty()) return false else seen.sumOf { it.size } / seen.size
    return firstVisibleItemIndex.toLong() * average + firstVisibleItemScrollOffset > viewport
}
