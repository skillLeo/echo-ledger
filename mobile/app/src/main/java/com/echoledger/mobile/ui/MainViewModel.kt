package com.echoledger.mobile.ui

import android.app.Application
import android.os.Build
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.calls.Decisions
import com.echoledger.mobile.core.PairingPayload
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.net.NotPairedException
import com.echoledger.mobile.net.UploadWorker
import com.echoledger.mobile.privilege.HelperConnection
import com.echoledger.mobile.widget.WidgetStateStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** The stored settings, or the fact that they have not been read yet (a cold start reads DataStore after the first frame). */
sealed interface SettingsState {
    data object Loading : SettingsState

    data class Loaded(val settings: AppSettings) : SettingsState
}

class MainViewModel(app: Application) : AndroidViewModel(app) {
    private val graph = (app as EchoLedgerApp).graph

    val settingsState: StateFlow<SettingsState> = graph.settings.settings
        .map<AppSettings, SettingsState> { SettingsState.Loaded(it) }
        .stateIn(viewModelScope, SharingStarted.Eagerly, SettingsState.Loading)

    /** The stored settings once loaded; the defaults until then (use [settingsState] to tell the two apart). */
    val settings: StateFlow<AppSettings> = settingsState
        .map { (it as? SettingsState.Loaded)?.settings ?: AppSettings() }
        .stateIn(viewModelScope, SharingStarted.Eagerly, AppSettings())
    val library: StateFlow<List<LibraryItem>> = graph.library.items
    val helper: StateFlow<HelperConnection.State> = graph.helper.state

    private val _pending = MutableStateFlow<List<LocalRecording>>(emptyList())
    val pending: StateFlow<List<LocalRecording>> = _pending.asStateFlow()

    private val _clients = MutableStateFlow<List<ClientDto>>(emptyList())
    val clients: StateFlow<List<ClientDto>> = _clients.asStateFlow()

    private val _message = MutableStateFlow<String?>(null)
    val message: StateFlow<String?> = _message.asStateFlow()

    private val _busy = MutableStateFlow(false)
    val busy: StateFlow<Boolean> = _busy.asStateFlow()

    fun consumeMessage() {
        _message.value = null
    }

    fun reloadLocal() {
        viewModelScope.launch(Dispatchers.IO) { _pending.value = graph.recordings.all().filter { it.state != "uploaded" } }
    }

    fun showMessage(text: String) {
        _message.value = text
    }

    /** Pairs from the manual form: server address + one-time code, or the QR text pasted into the code field. */
    fun pair(serverUrl: String, codeOrPayload: String, deviceName: String) {
        val input = codeOrPayload.trim()
        if (input.startsWith("{")) {
            val payload = PairingPayload.parse(input)
            if (payload == null) showMessage(NOT_A_PAIRING_QR) else pairWith(payload, deviceName)
        } else {
            pairWith(PairingPayload(serverUrl.trim().trimEnd('/'), input, emptyList()), deviceName)
        }
    }

    /** Pairs with a scanned (or pasted) QR payload. Its pins already protect the registration request itself. */
    fun pairWith(payload: PairingPayload, deviceName: String) = viewModelScope.launch {
        _busy.value = true
        try {
            val name = deviceName.ifBlank { "${Build.MANUFACTURER} ${Build.MODEL}".trim() }
            val response = withContext(Dispatchers.IO) {
                ApiClient(
                    payload.url,
                    payload.pins,
                    token = null
                ).register(payload.code, name, BuildConfig.VERSION_NAME, Build.VERSION.RELEASE)
            }
            graph.settings.saveToken(response.token)
            val finalPins = response.config.pins.ifEmpty { payload.pins }
            graph.settings.update {
                it.copy(serverUrl = payload.url, pins = finalPins, deviceId = response.device.id, deviceName = response.device.name)
            }
            graph.invalidateApi()
            refresh()
            _message.value = "Paired with ${payload.url.removePrefix("https://")}"
        } catch (e: Exception) {
            _message.value = e.message ?: "Pairing failed"
        } finally {
            _busy.value = false
        }
    }

    fun unpair() = viewModelScope.launch {
        graph.settings.clearPairing()
        graph.library.clear()
        graph.invalidateApi()
        WidgetStateStore.refresh(getApplication())
    }

    fun refresh() = viewModelScope.launch {
        try {
            withContext(Dispatchers.IO) { graph.library.refresh() }
            _clients.value = withContext(Dispatchers.IO) { graph.api()?.clients()?.data.orEmpty() }
            WidgetStateStore.refresh(getApplication())
        } catch (e: NotPairedException) {
            _message.value = "This phone was unpaired on the server. Pair it again."
        } catch (e: Exception) {
            _message.value = "Offline — showing the last synced library"
        }
        reloadLocal()
    }

    suspend fun transcript(id: String): TranscriptDto? = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.transcript(id) }.getOrNull()
    }

    /** Temporary signed streaming URL for the session's recording, or null when it is not (yet) playable. */
    suspend fun playbackUrl(sessionId: String): String? = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.sessionDetail(sessionId)?.playbackUrl }.getOrNull()
    }

    fun delete(item: LibraryItem) = viewModelScope.launch {
        runCatching { withContext(Dispatchers.IO) { graph.api()?.deleteSession(item.id) } }
            .onSuccess { refresh() }
            .onFailure { _message.value = it.message }
    }

    /** Retries one recording the server refused (e.g. after fixing the cause). */
    fun retryUpload(id: String) = viewModelScope.launch(Dispatchers.IO) {
        graph.recordings.update(id) { it.copy(state = "queued", lastError = null) }
        UploadWorker.retryNow(getApplication(), id)
        reloadLocal()
    }

    /** Restarts every upload that is waiting out a retry back-off, so opening the app never leaves one stuck. */
    fun retryPending() = viewModelScope.launch(Dispatchers.IO) {
        graph.recordings.all().filter { it.state == "queued" }.forEach { UploadWorker.retryNow(getApplication(), it.id) }
        reloadLocal()
    }

    fun decide(id: String, keep: Boolean) = viewModelScope.launch(Dispatchers.IO) {
        Decisions.apply(getApplication(), id, keep)
        reloadLocal()
    }

    fun updateSettings(transform: (AppSettings) -> AppSettings) = viewModelScope.launch { graph.settings.update(transform) }

    fun connectShizuku() {
        val h = graph.helper
        when {
            !h.shizukuAvailable() -> _message.value = "Shizuku is not running. Start it (Wireless debugging) and try again."
            !h.shizukuPermissionGranted() -> h.requestShizukuPermission()
            !h.bindShizuku() -> _message.value = "Could not start the capture helper through Shizuku."
        }
    }

    fun adbCommand(): String = graph.helper.adbCommand()

    companion object {
        const val NOT_A_PAIRING_QR = "That isn't an EchoLedger pairing code. In your admin open Devices → Pair a device."
    }
}
