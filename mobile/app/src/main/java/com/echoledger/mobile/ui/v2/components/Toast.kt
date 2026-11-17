package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalAccessibilityManager
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlin.coroutines.resume
import kotlinx.coroutines.CancellableContinuation
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.delay
import kotlinx.coroutines.suspendCancellableCoroutine

/** How a toast ended: its action was tapped, or it timed out or was replaced. */
enum class EchoToastResult { Action, Dismissed }

/** One toast on screen. */
class EchoToastData internal constructor(
    val message: String,
    val actionLabel: String?,
    /**
     * When the toast must be gone, on [EchoToastState.clock] (a timer kept elsewhere, such as a delayed discard); null:
     * the toast's own time.
     */
    val deadlineMs: Long?,
    private val continuation: CancellableContinuation<EchoToastResult>
) {
    fun performAction() {
        if (continuation.isActive) continuation.resume(EchoToastResult.Action)
    }

    fun dismiss() {
        if (continuation.isActive) continuation.resume(EchoToastResult.Dismissed)
    }

    /** Set when a toast with an end time took this one's place: it shows again right after, for its full time. */
    internal var bumped = false
        private set

    internal fun bump() {
        if (!continuation.isActive) return
        bumped = true
        continuation.resume(EchoToastResult.Dismissed)
    }
}

/**
 * Shows toasts one after another. Hold it in the screen ([rememberEchoToastState]) and give it to [EchoToastHost].
 * [clock] is the wall clock that deadlines are on.
 */
@Stable
class EchoToastState(val clock: () -> Long = System::currentTimeMillis) {
    var current by mutableStateOf<EchoToastData?>(null)
        private set

    // One toast at a time; a toast with a deadline is first in line.
    private val lock = Any()
    private var busy = false
    private val waiting = ArrayDeque<CompletableDeferred<Unit>>()

    /** Joins the line (at the front when [first]); null when the turn is free and now taken. */
    private fun joinLine(first: Boolean): CompletableDeferred<Unit>? = synchronized(lock) {
        if (!busy) {
            busy = true
            null
        } else {
            CompletableDeferred<Unit>().also { if (first) waiting.addFirst(it) else waiting.addLast(it) }
        }
    }

    private suspend fun awaitTurn(wait: CompletableDeferred<Unit>?) {
        wait ?: return
        try {
            wait.await()
        } catch (e: CancellationException) {
            // Still waiting: leave the line. Already given the turn: pass it on.
            if (!synchronized(lock) { waiting.remove(wait) }) endTurn()
            throw e
        }
    }

    private fun endTurn() = synchronized(lock) {
        val next = waiting.removeFirstOrNull()
        if (next == null) busy = false else next.complete(Unit)
    }

    /**
     * Shows [message] (with [actionLabel] when given) until the action is tapped or its time runs out.
     *
     * With a [deadlineMs] (on [clock]) the toast belongs to a timer kept elsewhere: it never waits in the queue (the
     * toast on screen steps aside and shows again right after it, for its full time), it is gone by its deadline, and
     * when its turn comes after the deadline it is not shown at all ([EchoToastResult.Dismissed]). Without one it
     * takes its turn and its own time.
     */
    suspend fun show(message: String, actionLabel: String? = null, deadlineMs: Long? = null): EchoToastResult {
        var first = deadlineMs != null
        // In line before the toast on screen steps aside, so its turn passes to this one, not back to itself.
        var place = joinLine(first)
        if (deadlineMs != null) current?.takeIf { it.deadlineMs == null }?.bump()
        while (true) {
            awaitTurn(place)
            var data: EchoToastData? = null
            try {
                if (deadlineMs != null && clock() >= deadlineMs) return EchoToastResult.Dismissed
                val result = suspendCancellableCoroutine { cont ->
                    data = EchoToastData(message, actionLabel, deadlineMs, cont)
                    current = data
                }
                if (data?.bumped != true) return result
            } finally {
                current = null
                endTurn()
            }
            // Made way for a toast with an end time: back at the front of the line, after it.
            first = true
            place = joinLine(first)
        }
    }

    /** "Deleted 3 recordings · Undo": runs [onUndo] only when Undo is tapped in time. */
    suspend fun showUndo(message: String, undoLabel: String, onUndo: () -> Unit): EchoToastResult =
        show(message, undoLabel).also { if (it == EchoToastResult.Action) onUndo() }
}

@Composable
fun rememberEchoToastState(): EchoToastState = remember { EchoToastState() }

/**
 * Where toasts appear (bottom of the screen, above the navigation). A toast with an action stays 6 s, longer when the
 * phone's accessibility settings ask for more time; screen readers announce it politely.
 */
@Composable
fun EchoToastHost(state: EchoToastState, modifier: Modifier = Modifier) {
    val data = state.current ?: return
    val accessibility = LocalAccessibilityManager.current
    LaunchedEffect(data) {
        val time = data.deadlineMs?.let { (it - state.clock()).coerceAtLeast(0) } ?: accessibility?.calculateRecommendedTimeoutMillis(
            EchoTokens.Component.toastDurationMs.toLong(),
            containsIcons = false,
            containsText = true,
            containsControls = data.actionLabel != null
        ) ?: EchoTokens.Component.toastDurationMs.toLong()
        delay(time)
        data.dismiss()
    }
    Box(modifier.fillMaxWidth().padding(EchoTokens.Layout.pagePaddingMobile), contentAlignment = Alignment.BottomCenter) {
        EchoToast(data.message, data.actionLabel, onAction = data::performAction)
    }
}

/** The toast itself: the inverse surface, the message, and at most one action. */
@Composable
fun EchoToast(message: String, actionLabel: String?, onAction: () -> Unit, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    Surface(
        modifier = modifier
            .fillMaxWidth()
            .semantics { liveRegion = LiveRegionMode.Polite },
        shape = RoundedCornerShape(EchoTokens.Component.menuRadius),
        color = c.textPrimary,
        contentColor = c.bgSurface,
        shadowElevation = EchoTokens.Space.s8
    ) {
        Row(
            Modifier
                .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
                .padding(start = EchoTokens.Space.s16, end = EchoTokens.Space.s4),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            Text(message, style = EchoV2.type.meta, modifier = Modifier.weight(1f).padding(vertical = EchoTokens.Space.s12))
            if (actionLabel != null) {
                Surface(
                    onClick = onAction,
                    modifier = Modifier.defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin),
                    color = EchoV2.colors.clear,
                    contentColor = c.inverseAccent,
                    shape = RoundedCornerShape(EchoTokens.Component.buttonRadius)
                ) {
                    Box(Modifier.padding(horizontal = EchoTokens.Space.s12), contentAlignment = Alignment.Center) {
                        Text(actionLabel, style = EchoV2.type.label, modifier = Modifier.padding(vertical = EchoTokens.Space.s12))
                    }
                }
            }
        }
    }
}
