package com.echoledger.mobile.recording

import java.util.concurrent.Callable
import java.util.concurrent.Executors

/**
 * The one background thread that reads and writes the capture journal's and the call sightings' files (since v1.8): a
 * call event on the main thread, a stage from the audio reader thread or a call notification never waits for the disk.
 * Changes run in the order they were made, and a read waits for every change made before it.
 */
object JournalIo {
    private const val NAME = "el-journal-io"
    private val executor = Executors.newSingleThreadExecutor { task -> Thread(task, NAME).apply { isDaemon = true } }

    /** Runs [task] on the I/O thread after everything posted before it; the caller does not wait. */
    fun post(task: () -> Unit) {
        executor.execute { runCatching(task) }
    }

    /** [task]'s answer from the I/O thread once everything posted before it has run (at once on that thread itself). */
    fun <T> read(task: () -> T): T = if (Thread.currentThread().name == NAME) task() else executor.submit(Callable(task)).get()

    /** Waits until everything posted so far has run (a file read right after a change, in tests). */
    fun flush() = read { }
}
