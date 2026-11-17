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
import kotlinx.coroutines.delay
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

/** How a toast ended: its action was tapped, or it timed out or was replaced. */
enum class EchoToastResult { Action, Dismissed }

/** One toast on screen. */
class EchoToastData internal constructor(
    val message: String,
    val actionLabel: String?,
    /** A fixed time on screen (a timer elsewhere that the toast must end with); null: the toast's own time. */
    val durationMs: Long?,
    private val continuation: CancellableContinuation<EchoToastResult>
) {
    fun performAction() {
        if (continuation.isActive) continuation.resume(EchoToastResult.Action)
    }

    fun dismiss() {
        if (continuation.isActive) continuation.resume(EchoToastResult.Dismissed)
    }
}

/** Shows toasts one after another. Hold it in the screen ([rememberEchoToastState]) and give it to [EchoToastHost]. */
@Stable
class EchoToastState {
    var current by mutableStateOf<EchoToastData?>(null)
        private set
    private val queue = Mutex()

    /**
     * Shows [message] (with [actionLabel] when given) until the action is tapped or its time runs out: [durationMs] when
     * given (the toast then ends exactly with a timer kept elsewhere), else the toast's own time.
     */
    suspend fun show(message: String, actionLabel: String? = null, durationMs: Long? = null): EchoToastResult = queue.withLock {
        try {
            suspendCancellableCoroutine { cont -> current = EchoToastData(message, actionLabel, durationMs, cont) }
        } finally {
            current = null
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
        val time = data.durationMs ?: accessibility?.calculateRecommendedTimeoutMillis(
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
