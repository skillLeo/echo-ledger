package com.echoledger.mobile.ui.design

import androidx.compose.animation.AnimatedVisibilityScope
import androidx.compose.animation.ExperimentalSharedTransitionApi
import androidx.compose.animation.SharedTransitionScope
import androidx.compose.runtime.Composable
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Modifier
import com.echoledger.mobile.ui.reduceMotion

/**
 * The scopes of the row → recording transition (since v1.3): set by MainActivity around the Library and the recording
 * page; null anywhere else (previews, tests of a single screen), where [sharedElement] does nothing.
 */
@OptIn(ExperimentalSharedTransitionApi::class)
val LocalSharedScopes = staticCompositionLocalOf<Pair<SharedTransitionScope, AnimatedVisibilityScope>?> { null }

/**
 * Marks this element as the same thing on both screens ([key], e.g. "tile-<id>"): when a row opens its recording, the
 * app tile and the title move and grow into place. Skipped when animations are off.
 */
@OptIn(ExperimentalSharedTransitionApi::class)
@Composable
fun Modifier.sharedElement(key: String): Modifier {
    val scopes = LocalSharedScopes.current ?: return this
    if (reduceMotion()) return this
    val (shared, visibility) = scopes
    return with(shared) { this@sharedElement.sharedBounds(rememberSharedContentState(key), visibility) }
}
