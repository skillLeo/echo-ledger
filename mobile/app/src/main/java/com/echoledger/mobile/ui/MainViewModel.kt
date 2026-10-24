package com.echoledger.mobile.ui

import android.app.Application
import android.os.Build
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.calls.Decisions
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.net.NotPairedException
import com.echoledger.mobile.privilege.HelperConnection
import com.echoledger.mobile.widget.WidgetStateStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive

class MainViewModel(app: Application) : AndroidViewModel(app) {
    private val graph = (app as EchoLedgerApp).graph

    val settings: StateFlow<AppSettings> = graph.settings.settings.stateIn(viewModelScope, SharingStarted.Eagerly, AppSettings())
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

    /** Pairs with the code or the pasted QR payload ({"v":1,"url":…,"code":…,"pins":[…]}). */
    fun pair(serverUrl: String, codeOrPayload: String, deviceName: String) = viewModelScope.launch {
        _busy.value = true
        try {
            var url = serverUrl.trim()
            var code = codeOrPayload.trim()
            var pins = emptyList<String>()
            if (code.startsWith("{")) {
                val obj = com.echoledger.mobile.data.ApiJson.parseToJsonElement(code).jsonObject
                url = obj["url"]?.jsonPrimitive?.contentOrNull ?: url
                code = obj["code"]?.jsonPrimitive?.contentOrNull ?: ""
                pins = (obj["pins"] as? kotlinx.serialization.json.JsonArray)?.mapNotNull { it.jsonPrimitive.contentOrNull }.orEmpty()
            }
            val name = deviceName.ifBlank { "${Build.MANUFACTURER} ${Build.MODEL}".trim() }
            val response = withContext(Dispatchers.IO) {
                ApiClient(url, pins, token = null).register(code, name, BuildConfig.VERSION_NAME, Build.VERSION.RELEASE)
            }
            graph.settings.saveToken(response.token)
            val finalPins = response.config.pins.ifEmpty { pins }
            graph.settings.update {
                it.copy(serverUrl = url.trimEnd('/'), pins = finalPins, deviceId = response.device.id, deviceName = response.device.name)
            }
            graph.invalidateApi()
            refresh()
            _message.value = "Paired with ${url.removePrefix("https://")}"
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

    fun delete(item: LibraryItem) = viewModelScope.launch {
        runCatching { withContext(Dispatchers.IO) { graph.api()?.deleteSession(item.id) } }
            .onSuccess { refresh() }
            .onFailure { _message.value = it.message }
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
}
