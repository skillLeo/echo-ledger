package com.echoledger.mobile.recording

import android.content.Context
import android.os.Build
import com.echoledger.mobile.BuildConfig
import java.io.File
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/**
 * What happened at each stage of call capture (call seen, helper started, sound arriving, file saved, upload), kept on
 * the phone in a small ring buffer for "Copy diagnostics". Never audio, names, numbers or transcript text: callers
 * pass stage names, app labels, counts and the helper's own messages only.
 */
object CaptureLog {
    const val MAX_LINES = 200
    private const val FILE = "capture-log.txt"
    private val lines = ArrayDeque<String>()
    private var loaded = false
    private val clock = DateTimeFormatter.ofPattern("MM-dd HH:mm:ss").withZone(ZoneId.systemDefault())

    @Synchronized
    fun add(context: Context, stage: String, detail: String = "") {
        load(context)
        val line = "${clock.format(Instant.now())} $stage${if (detail.isBlank()) "" else ": ${detail.take(300)}"}"
        lines.addLast(line.replace('\n', ' '))
        while (lines.size > MAX_LINES) lines.removeFirst()
        runCatching { File(context.filesDir, FILE).writeText(lines.joinToString("\n")) }
    }

    @Synchronized
    fun lines(context: Context): List<String> {
        load(context)
        return lines.toList()
    }

    /** The text "Copy diagnostics" puts on the clipboard: the phone, the app and helper builds, then the log. */
    fun report(context: Context, helperState: String): String = buildString {
        appendLine("EchoLedger capture diagnostics")
        appendLine("Phone: ${Build.MANUFACTURER} ${Build.MODEL}, Android ${Build.VERSION.RELEASE} (SDK ${Build.VERSION.SDK_INT})")
        appendLine("App: ${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE}), helper build ${BuildConfig.HELPER_BUILD}")
        appendLine("Helper: $helperState")
        appendLine("--")
        lines(context).forEach { appendLine(it) }
    }

    @Synchronized
    fun clear(context: Context) {
        lines.clear()
        loaded = true
        runCatching { File(context.filesDir, FILE).delete() }
    }

    private fun load(context: Context) {
        if (loaded) return
        loaded = true
        runCatching { File(context.filesDir, FILE).takeIf { it.exists() }?.readLines()?.takeLast(MAX_LINES)?.let(lines::addAll) }
    }
}
