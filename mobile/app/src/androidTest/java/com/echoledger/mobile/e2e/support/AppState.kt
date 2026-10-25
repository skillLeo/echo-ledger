package com.echoledger.mobile.e2e.support

import androidx.work.WorkManager
import com.echoledger.mobile.AppGraph
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LocalRecording
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.runBlocking

/**
 * Direct access to the installed app's own stores, used to put the app into a known state before a test (the equivalent
 * of "Clear storage") and to look at what it keeps on the phone. The behaviour under test is always driven through the UI.
 */
object AppState {
    private val graph: AppGraph get() = (Ui.context.applicationContext as EchoLedgerApp).graph

    /** Forget pairing, library, queued recordings and scheduled uploads. */
    fun reset() = runBlocking {
        val work = WorkManager.getInstance(Ui.context)
        work.cancelAllWork().result.get(15, TimeUnit.SECONDS)
        work.pruneWork().result.get(15, TimeUnit.SECONDS)
        graph.recordings.all().forEach { graph.recordings.delete(it.id) }
        graph.settings.clearPairing()
        graph.settings.update { AppSettings() }
        graph.library.clear()
        graph.invalidateApi()
    }

    /** Leaves the phone paired with the server as a finished pairing would (token in the encrypted store, settings saved). */
    fun pairAs(device: Backend.Device) = runBlocking {
        graph.settings.saveToken(device.token)
        graph.settings.update {
            it.copy(serverUrl = device.baseUrl, pins = device.pins, deviceId = device.id, deviceName = device.name)
        }
        graph.invalidateApi()
    }

    /** Keeps the live-caption stream (a connection to the speech provider's own host) out of the tests. */
    fun disableCaptions() = runBlocking { graph.settings.update { it.copy(captionsEnabled = false) } }

    fun settings(): AppSettings = runBlocking { graph.settings.current() }

    fun token(): String? = runBlocking { graph.settings.token() }

    fun recordings(): List<LocalRecording> = graph.recordings.all()

    fun cachedLibrarySize(): Int = graph.library.items.value.size
}
