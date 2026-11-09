package com.echoledger.mobile.ui.design

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.SwipeToDismissBox
import androidx.compose.material3.SwipeToDismissBoxValue
import androidx.compose.material3.Text
import androidx.compose.material3.rememberSwipeToDismissBoxState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.core.Notice
import com.echoledger.mobile.core.NoticeKind
import com.echoledger.mobile.core.NoticeTarget
import com.echoledger.mobile.core.Notices
import com.echoledger.mobile.ui.LocalEchoColors
import com.echoledger.mobile.ui.LocalEchoType
import com.echoledger.mobile.ui.Space
import com.echoledger.mobile.ui.reduceMotion
import kotlinx.coroutines.delay

/** The app's notice host, for screens that show a message themselves (null outside the main window, e.g. in tests). */
val LocalNotices = androidx.compose.runtime.staticCompositionLocalOf<NoticeHostState?> { null }

/** The messages at the bottom of the screen (since v1.6.1): one at a time, the next waits its turn. */
@Stable
class NoticeHostState {
    var current by mutableStateOf<Notice?>(null)
        private set
    private val waiting = ArrayDeque<Notice>()
    private var serial = 0L

    /** Which showing this is, so the same text twice runs its own timer. */
    var showing by mutableLongStateOf(0L)
        private set

    fun show(notice: Notice) {
        if (current == null) {
            current = notice
            showing = ++serial
        } else {
            waiting.addLast(notice)
        }
    }

    fun dismiss() {
        current = waiting.removeFirstOrNull()
        if (current != null) showing = ++serial
    }
}

/**
 * A message as docs/DESIGN_SYSTEM.md "Notices" says: tap it to go where it is about, × to close, a timer (6 s, an error
 * 10 s) with a bar that stops while a finger is on it, a swipe left or right to close.
 */
@Composable
fun NoticeHost(
    state: NoticeHostState,
    onOpen: (NoticeTarget) -> Unit,
    modifier: Modifier = Modifier,
    /** The clock the timer reads (tests pass their own). */
    now: () -> Long = { android.os.SystemClock.uptimeMillis() }
) {
    val notice = state.current
    AnimatedVisibility(
        visible = notice != null,
        enter = if (reduceMotion()) fadeIn() else slideInVertically { it } + fadeIn(),
        exit = if (reduceMotion()) fadeOut() else slideOutVertically { it } + fadeOut(),
        modifier = modifier
    ) {
        val shown = notice ?: return@AnimatedVisibility
        key(state.showing) {
            NoticeCard(
                shown,
                now,
                onOpen = { target ->
                    state.dismiss()
                    onOpen(target)
                },
                onClose = { state.dismiss() }
            )
        }
    }
}

@Composable
private fun NoticeCard(notice: Notice, now: () -> Long, onOpen: (NoticeTarget) -> Unit, onClose: () -> Unit) {
    var elapsed by remember { mutableLongStateOf(0L) }
    var held by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        // Measured, not counted: a late tick still counts the time that really passed, and holding it adds nothing.
        var last = now()
        while (elapsed < notice.durationMs) {
            delay(TICK_MS)
            val time = now()
            if (!held) elapsed += time - last
            last = time
        }
        onClose()
    }
    SwipeAway(onGone = onClose, onHeld = { held = it }) {
        val c = LocalEchoColors.current
        Column(
            Modifier
                .fillMaxWidth()
                .padding(horizontal = Space.s3, vertical = Space.s2)
                .clip(RoundedCornerShape(12.dp))
                .background(Color(0xFF1C2024))
                .then(
                    notice.target?.let { target -> Modifier.clickable(onClickLabel = "Open") { onOpen(target) } } ?: Modifier
                )
                .semantics { liveRegion = if (notice.kind == NoticeKind.ERROR) LiveRegionMode.Assertive else LiveRegionMode.Polite }
        ) {
            Row(Modifier.padding(start = Space.s4), verticalAlignment = Alignment.CenterVertically) {
                if (notice.kind == NoticeKind.SUCCESS) SuccessCheck(Color(0xFF9EB1FF), Modifier.padding(end = Space.s2))
                Text(
                    notice.text,
                    style = LocalEchoType.current.bodyM,
                    color = Color(0xFFEDEEF0),
                    modifier = Modifier.weight(1f).padding(vertical = Space.s3)
                )
                IconButton(onClick = onClose) {
                    Icon(Icons.Rounded.Close, contentDescription = "Dismiss", tint = Color(0xFFB0B4BA))
                }
            }
            // The time left: a thin bar that shortens (it stays still with Remove animations on; the time still runs).
            val fraction = if (reduceMotion()) 1f else Notices.barFraction(notice.durationMs, elapsed)
            Box(
                Modifier.fillMaxWidth(fraction).height(2.dp).background(
                    if (notice.kind ==
                        NoticeKind.ERROR
                    ) {
                        c.danger
                    } else {
                        Color(0xFF9EB1FF)
                    }
                )
            )
        }
    }
}

/**
 * A swipe left or right closes what it wraps (since v1.6.1): it follows the finger, leaves past a third of its width or
 * on a flick, and springs back otherwise. [onHeld] says while a finger is on it (a message's timer waits then).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SwipeAway(onGone: () -> Unit, modifier: Modifier = Modifier, onHeld: (Boolean) -> Unit = {}, content: @Composable () -> Unit) {
    val swipe = rememberSwipeToDismissBoxState(positionalThreshold = { it * Notices.SWIPE_SHARE })
    LaunchedEffect(swipe.currentValue) {
        if (swipe.currentValue != SwipeToDismissBoxValue.Settled) onGone()
    }
    SwipeToDismissBox(
        state = swipe,
        backgroundContent = {},
        modifier = modifier.pointerInput(Unit) {
            awaitPointerEventScope {
                while (true) {
                    val event = awaitPointerEvent(PointerEventPass.Initial)
                    onHeld(event.changes.any { it.pressed })
                }
            }
        }
    ) { content() }
}

private const val TICK_MS = 100L
