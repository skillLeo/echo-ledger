package com.echoledger.mobile.ui.v2.shell

import android.content.res.Resources
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.platform.LocalContext
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.echoledger.mobile.R
import com.echoledger.mobile.calls.CallChoice
import com.echoledger.mobile.calls.CallLines
import com.echoledger.mobile.ui.v2.components.EchoToastResult
import com.echoledger.mobile.ui.v2.components.EchoToastState
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch

/**
 * The after-call card's confirmations inside the app (v2.1, I-05): while the app is on screen (started), its toast host
 * is [CallLines]' host, so "Transcribing… · Undo", "Saved as audio · Undo", "Deleted · Undo" and "3 calls saved as
 * audio · Review" are its snackbars, one at a time, above the bottom bar, never a floating window. When the app leaves
 * the screen, a line still running carries on in the after-call notification for the time it had left.
 */
@Composable
fun CallLinesHost(toasts: EchoToastState) {
    val res by rememberUpdatedState(LocalContext.current.resources)
    val scope = rememberCoroutineScope()
    val owner = LocalLifecycleOwner.current
    DisposableEffect(owner, toasts) {
        val host = ShellCallLines(toasts, scope) { res }
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> CallLines.attach(host)
                Lifecycle.Event.ON_STOP -> host.leave()
                else -> Unit
            }
        }
        owner.lifecycle.addObserver(observer)
        onDispose {
            owner.lifecycle.removeObserver(observer)
            host.leave()
        }
    }
}

/** [CallLines.Host] on the shell's toasts, in the app's language ([words]). Main thread. */
internal class ShellCallLines(private val toasts: EchoToastState, private val scope: CoroutineScope, private val words: () -> Resources) :
    CallLines.Host {
    private class Out(val job: Job, val endsAt: Long, val actions: CallLines.Actions)

    /** The lines shown or waiting, by call id (null: the group's). */
    private val out = HashMap<String?, Out>()

    override fun show(line: CallLines.Line, lineMs: Long, actions: CallLines.Actions): Long {
        cancel(line.id)
        val now = toasts.clock()
        // After every line with an end time already out (this host's own too, even before it reached the toasts), so
        // this one is on screen for its whole time.
        val endsAt = maxOf(now, toasts.busyUntil(), out.values.maxOfOrNull { it.endsAt } ?: 0L) + lineMs
        val res = words()
        val message = message(res, line)
        val label = res.getString(if (line.id == null) R.string.cc_grouped_review else R.string.cc_undo)
        var self: Job? = null
        val job = scope.launch {
            val result = toasts.show(message, label, deadlineMs = endsAt)
            if (out[line.id]?.job === self) out.remove(line.id)
            if (result == EchoToastResult.Action) actions.action() else actions.ended()
        }
        self = job
        out[line.id] = Out(job, endsAt, actions)
        return endsAt - now
    }

    override fun cancel(id: String?) {
        out.remove(id)?.job?.cancel()
    }

    /** The app left the screen: no more lines here, and the ones still running go on in a notification. */
    fun leave() {
        CallLines.detach(this)
        val now = toasts.clock()
        val left = out.values.toList()
        out.clear()
        left.forEach {
            it.job.cancel()
            val ms = it.endsAt - now
            if (ms > 0) it.actions.away(ms)
        }
    }

    private fun message(res: Resources, line: CallLines.Line): String = when {
        line.id == null -> res.getString(R.string.cc_grouped_audio, line.count)
        line.pick == CallChoice.Pick.TRANSCRIBE -> res.getString(R.string.cc_done_transcribing)
        line.pick == CallChoice.Pick.DELETE -> res.getString(R.string.cc_done_deleted)
        else -> res.getString(R.string.cc_done_audio)
    }
}
