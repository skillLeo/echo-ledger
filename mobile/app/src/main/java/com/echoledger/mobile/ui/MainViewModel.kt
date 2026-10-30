package com.echoledger.mobile.ui

import android.app.Application
import android.os.Build
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.calls.Decisions
import com.echoledger.mobile.core.Languages
import com.echoledger.mobile.core.Organise
import com.echoledger.mobile.core.PairingPayload
import com.echoledger.mobile.core.SettingsTransfer
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.BinItemDto
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.data.SearchItemDto
import com.echoledger.mobile.data.SecurityResponse
import com.echoledger.mobile.data.SessionDetailDto
import com.echoledger.mobile.data.ShareLinkDto
import com.echoledger.mobile.data.StorageDto
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.data.TranscriptViewsDto
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.net.NotPairedException
import com.echoledger.mobile.net.UploadWorker
import com.echoledger.mobile.privilege.HelperConnection
import com.echoledger.mobile.widget.WidgetStateStore
import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
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

    private val localReload = MutableStateFlow(0)

    init {
        // The Record tab's waiting / uploading cards follow the store while the tab stays open (A6.M12). One loader
        // for every trigger: a newer trigger cancels an older load, so a slow stale read can never overwrite the list.
        viewModelScope.launch {
            combine(graph.recordings.changes, localReload) { _, _ -> Unit }.collectLatest {
                _pending.value = withContext(Dispatchers.IO) { graph.recordings.all().filter { it.state != "uploaded" } }
            }
        }
    }

    fun reloadLocal() {
        localReload.update { it + 1 }
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
                it.copy(
                    serverUrl = payload.url,
                    pins = finalPins,
                    deviceId = response.device.id,
                    deviceName = response.device.name,
                    serverApiVersion = response.config.apiVersion,
                    markerTags = response.config.markers?.tags.orEmpty(),
                    unsureStyle = response.config.unsure?.style ?: "brackets",
                    unsureThreshold = response.config.unsure?.threshold ?: 0.5,
                    unsureNote = response.config.unsure?.note ?: true
                )
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

    private val _syncing = MutableStateFlow(false)

    /** A library sync is running (the first one shows a progress indicator instead of an empty library). */
    val syncing: StateFlow<Boolean> = _syncing.asStateFlow()

    private val _offline = MutableStateFlow(false)

    /** The last sync could not reach the server (C6): the library shows a banner with Retry. */
    val offline: StateFlow<Boolean> = _offline.asStateFlow()

    /** Whether this phone has finished at least one library sync since it was paired. */
    fun hasSynced(): Boolean = graph.library.hasSynced()

    fun refresh() = viewModelScope.launch {
        _syncing.value = true
        try {
            withContext(Dispatchers.IO) {
                // The server's contract version decides the v1 or v1.1 flows (open sessions, usage reports).
                try {
                    graph.api()?.config()?.let { config ->
                        graph.settings.update {
                            it.copy(
                                serverApiVersion = config.apiVersion,
                                markerTags = config.markers?.tags.orEmpty(),
                                unsureStyle = config.unsure?.style ?: "brackets",
                                unsureThreshold = config.unsure?.threshold ?: 0.5,
                                unsureNote = config.unsure?.note ?: true,
                                liveTranslate = com.echoledger.mobile.translate.ConsoleDefaults.apply(
                                    it.liveTranslate,
                                    config.liveTranslate
                                )
                            )
                        }
                    }
                } catch (e: ApiException) {
                    // No config answer is no reason to skip the library: keep the last known contract version.
                }
                graph.library.refresh()
            }
            com.echoledger.mobile.notify.ActivityWorker.schedule(getApplication())
            _clients.value = withContext(Dispatchers.IO) { graph.api()?.clients()?.data.orEmpty() }
            WidgetStateStore.refresh(getApplication())
            _offline.value = false
        } catch (e: NotPairedException) {
            // Revoked on the server: back to the pairing screen. Recordings waiting to upload stay on the phone.
            graph.settings.clearPairing()
            graph.library.clear()
            graph.invalidateApi()
            WidgetStateStore.refresh(getApplication())
            _message.value = "This phone was unpaired on the server. Pair it again."
        } catch (e: Exception) {
            _offline.value = true
            _message.value = "Offline — showing the last synced library"
        } finally {
            _syncing.value = false
        }
        reloadLocal()
    }

    suspend fun transcript(id: String): TranscriptDto? = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.transcript(id) }.getOrNull()
    }

    /**
     * "Re-transcribe as <language>" (null = Auto, since v1.2): the current transcript stays until the new one is
     * ready, then the new one is shown and the old one is kept as the previous version.
     */
    suspend fun retranscribe(sessionId: String, language: String?): Boolean = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.retranscribe(sessionId, language) ?: error("This phone is not paired.") }
            .onSuccess {
                _message.value = "Re-transcribing in ${Languages.label(language)}. The current transcript stays until the new one is ready."
            }
            .onFailure { _message.value = it.message ?: "The recording could not be re-transcribed." }
            .isSuccess
    }

    /** Show as (since v1.2): the Roman Urdu and English views and their state; null when they cannot be read now. */
    suspend fun transcriptViews(transcriptId: String): TranscriptViewsDto? = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.transcriptViews(transcriptId) }.getOrNull()
    }

    /** Show as (since v1.2): asks the server to write the views; null with a message when it cannot. */
    suspend fun makeTranscriptViews(transcriptId: String): TranscriptViewsDto? = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.makeTranscriptViews(transcriptId) }
            .onFailure { _message.value = it.message ?: "The views could not be started." }.getOrNull()
    }

    /** Temporary signed streaming URL for the session's recording, or null when it is not (yet) playable. */
    suspend fun playbackUrl(sessionId: String): String? = sessionDetail(sessionId)?.playbackUrl

    /** The session detail: the signed playback URL and the bookmarks; null when it cannot be read now. */
    suspend fun sessionDetail(sessionId: String): SessionDetailDto? = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.sessionDetail(sessionId) }.getOrNull()
    }

    /** `GET /api/search`: matches in every transcript; empty when offline or the query is too short. */
    suspend fun search(query: String): List<SearchItemDto> = withContext(Dispatchers.IO) {
        if (query.trim().length < 2) return@withContext emptyList()
        runCatching { graph.api()?.search(query)?.data.orEmpty() }.getOrDefault(emptyList())
    }

    /**
     * Downloads an export into the app's private cache (`exports/`, emptied first) and returns the file, or null
     * with a message when it failed.
     */
    suspend fun export(transcriptId: String, format: String, view: String? = null): File? = withContext(Dispatchers.IO) {
        try {
            val api = graph.api() ?: return@withContext null
            val (bytes, name) = api.export(transcriptId, format, view)
            val dir = File(getApplication<Application>().cacheDir, "exports").apply {
                deleteRecursively()
                mkdirs()
            }
            File(dir, name).apply { writeBytes(bytes) }
        } catch (e: Exception) {
            _message.value = e.message ?: "The export failed."
            null
        }
    }

    suspend fun createShare(sessionId: String, expiresIn: Long, password: String?, includeAudio: Boolean): ShareLinkDto? =
        withContext(Dispatchers.IO) {
            runCatching { graph.api()?.createShare(sessionId, expiresIn, password, includeAudio) }
                .onFailure { _message.value = it.message ?: "The link could not be made." }.getOrNull()
        }

    suspend fun shares(sessionId: String): List<ShareLinkDto> = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.shares(sessionId)?.data.orEmpty() }.getOrDefault(emptyList())
    }

    suspend fun revokeShare(id: String): Boolean = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.revokeShare(id) }.onFailure { _message.value = it.message }.isSuccess
    }

    /** "Add to vocabulary": for the selected client, or for every recording. */
    fun addKeyterm(term: String, clientId: String?) = viewModelScope.launch(Dispatchers.IO) {
        runCatching { graph.api()?.addKeyterm(term.trim(), clientId) }
            .onSuccess { _message.value = "Added “${term.trim()}” to the vocabulary" }
            .onFailure { _message.value = it.message ?: "The term could not be added." }
    }

    // ---- Organising the library (B15) ------------------------------------------------------------------------------

    /** Pins, moves or relabels one recording; the library syncs afterwards. False (with a message) when it failed. */
    suspend fun organise(
        id: String,
        pinned: Boolean? = null,
        clientId: String? = null,
        clearClient: Boolean = false,
        labels: List<String>? = null,
        keep: Boolean? = null
    ): Boolean {
        val ok = withContext(Dispatchers.IO) {
            runCatching { graph.api()?.updateSession(id, pinned, clientId, clearClient, labels, keep) ?: error("Not paired") }
                .onFailure { _message.value = it.message ?: "The change could not be saved." }.isSuccess
        }
        if (ok) refresh()
        return ok
    }

    /** One action for the selected recordings; how many changed, or null (with a message) when it failed. */
    suspend fun bulk(ids: List<String>, action: String, clientId: String? = null, label: String? = null): Int? {
        val changed = withContext(Dispatchers.IO) {
            runCatching { graph.api()?.bulk(ids.take(Organise.MAX_BULK), action, clientId, label) ?: error("Not paired") }
                .onFailure { _message.value = it.message ?: "The change could not be saved." }.getOrNull()
        }
        if (changed != null) {
            _message.value =
                if (action == "delete") {
                    "$changed deleted"
                } else if (changed == 1) {
                    "1 recording updated"
                } else {
                    "$changed recordings updated"
                }
            refresh()
        }
        return changed
    }

    /** The finished transcripts of the selected recordings as one zip in the private cache (`exports/`). */
    suspend fun exportSelected(ids: List<String>, format: String): File? = withContext(Dispatchers.IO) {
        try {
            val api = graph.api() ?: return@withContext null
            val (bytes, name) = api.exportSessions(ids.take(Organise.MAX_BULK), format)
            val dir = File(getApplication<Application>().cacheDir, "exports").apply {
                deleteRecursively()
                mkdirs()
            }
            File(dir, name).apply { writeBytes(bytes) }
        } catch (e: Exception) {
            _message.value = e.message ?: "The export failed."
            null
        }
    }

    /** A new client folder, added to the list right away. */
    suspend fun createClient(name: String): ClientDto? = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.createClient(name.trim()) }
            .onFailure { _message.value = it.message ?: "The client could not be created." }.getOrNull()
            ?.also { created -> _clients.value = _clients.value.filterNot { it.id == created.id } + created }
    }
    // ---- Activity centre (B18) -------------------------------------------------------------------------------------

    /** Opening a recording (since v1.3): the dot goes away here at once, and the server is told. */
    fun markOpened(item: LibraryItem) {
        if (item.opened != false) return
        viewModelScope.launch(Dispatchers.IO) {
            graph.library.markOpenedLocally(item.id)
            runCatching { graph.api()?.markOpened(item.id) }
        }
    }

    /** The activity list; null when offline. */
    suspend fun activity(): com.echoledger.mobile.data.ActivityResponse? = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.activity(30) }.getOrNull()
    }

    /** Marks those ids (or all) read; the new unread count, or null when it failed. */
    suspend fun markActivityRead(ids: List<String>? = null): Int? =
        withContext(Dispatchers.IO) { runCatching { graph.api()?.markActivityRead(ids) }.getOrNull() }
    // ---- Undo bin and storage (B17) --------------------------------------------------------------------------------

    /** Recordings deleted in the last 48 hours; empty when offline. */
    suspend fun bin(): List<BinItemDto> = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.bin()?.data.orEmpty() }.getOrDefault(emptyList())
    }

    /** Restores a recording from the bin and syncs it back into the library. */
    suspend fun restore(id: String): Boolean {
        val ok = withContext(Dispatchers.IO) {
            runCatching { graph.api()?.restoreSession(id) ?: error("Not paired") }
                .onSuccess { _message.value = "Restored" }
                .onFailure { _message.value = it.message ?: "The recording could not be restored." }.isSuccess
        }
        if (ok) refresh()
        return ok
    }

    /** Erases a binned recording now. */
    suspend fun eraseNow(id: String): Boolean = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.eraseFromBin(id) ?: error("Not paired") }
            .onSuccess { _message.value = "Erased" }
            .onFailure { _message.value = it.message ?: "The recording could not be erased." }.isSuccess
    }

    /** The storage meter; null when offline. */
    suspend fun storage(): StorageDto? = withContext(Dispatchers.IO) { runCatching { graph.api()?.storage() }.getOrNull() }
    // ---- Security centre (B16) -------------------------------------------------------------------------------------

    /** Paired devices and the recent audit log; null when offline or unpaired. */
    suspend fun security(): SecurityResponse? = withContext(Dispatchers.IO) { runCatching { graph.api()?.security() }.getOrNull() }

    /** Revokes another paired device; false (with the server's reason) when it was refused. */
    suspend fun revokeDevice(id: String): Boolean = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.revokeDevice(id) ?: error("Not paired") }
            .onSuccess { _message.value = "Device revoked" }
            .onFailure { _message.value = it.message ?: "The device could not be revoked." }.isSuccess
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

    /** "Use Urdu for Acme from now on?" after a language change on a waiting recording; null when nothing is asked. */
    data class LearnLanguage(val recordingId: String, val clientId: String, val question: String, val language: String?)

    private val _learn = MutableStateFlow<LearnLanguage?>(null)
    val learn: StateFlow<LearnLanguage?> = _learn.asStateFlow()

    /** The title typed on a waiting call's card (blank = let the server name it; the server allows 200 characters). */
    fun setPendingTitle(id: String, title: String) = viewModelScope.launch(Dispatchers.IO) {
        graph.recordings.update(id) { it.copy(title = title.trim().take(200).ifBlank { null }) } ?: return@launch
        reloadLocal()
    }

    /** The client chosen on a waiting call's card (null = no client). */
    fun setPendingClient(id: String, clientId: String?) = viewModelScope.launch(Dispatchers.IO) {
        graph.recordings.update(id) { it.copy(clientId = clientId) } ?: return@launch
        reloadLocal()
    }

    /** The language chip of a waiting recording: Keep transcribes in it. */
    fun setPendingLanguage(id: String, language: String?) = viewModelScope.launch(Dispatchers.IO) {
        val updated = graph.recordings.update(id) { it.copy(language = Languages.canonical(language)) } ?: return@launch
        reloadLocal()
        val client = updated.clientId?.let { cid -> _clients.value.firstOrNull { it.id == cid } }
        val question = client?.let { Languages.learnPrompt(it.name, it.language, updated.language) }
        _learn.value = if (client != null && question != null) LearnLanguage(id, client.id, question, updated.language) else null
    }

    fun answerLearn(yes: Boolean) = viewModelScope.launch(Dispatchers.IO) {
        val asked = _learn.value ?: return@launch
        _learn.value = null
        if (!yes) return@launch
        runCatching { graph.api()?.setClientLanguage(asked.clientId, asked.language) }
            .onSuccess { _clients.value = _clients.value.map { if (it.id == asked.clientId) it.copy(language = asked.language) else it } }
            .onFailure { _message.value = it.message ?: "The client could not be updated." }
    }

    /** Keep or Discard; a [title] typed on the card is stored first, in the same step, so the upload always carries it. */
    fun decide(id: String, keep: Boolean, title: String? = null) = viewModelScope.launch(Dispatchers.IO) {
        if (keep && title != null) graph.recordings.update(id) { it.copy(title = title.trim().take(200).ifBlank { null }) }
        Decisions.apply(getApplication(), id, keep)
        reloadLocal()
    }

    fun updateSettings(transform: (AppSettings) -> AppSettings) = viewModelScope.launch { graph.settings.update(transform) }

    // ---- Onboarding and settings files (B19) ------------------------------------------------------------------------

    /** Ends the first-run wizard for good; the consent box of its last step is recorded when ticked. */
    fun finishOnboarding(consent: Boolean = false) = updateSettings {
        it.copy(onboardingDone = true, consentAcknowledged = it.consentAcknowledged || consent)
    }

    /** Writes the portable settings (never the pairing, token or lock) to a file the user picked. */
    fun exportSettingsTo(uri: android.net.Uri) = viewModelScope.launch(Dispatchers.IO) {
        try {
            val text = SettingsTransfer.export(graph.settings.current())
            val out = getApplication<Application>().contentResolver.openOutputStream(uri, "wt") ?: error("no stream")
            out.use { it.write(text.toByteArray()) }
            _message.value = "Settings saved"
        } catch (e: Exception) {
            _message.value = "The settings could not be saved."
        }
    }

    /** Applies a settings file the user picked; the pairing, token and lock stay as they are. */
    fun importSettingsFrom(uri: android.net.Uri) = viewModelScope.launch(Dispatchers.IO) {
        val text = try {
            getApplication<Application>().contentResolver.openInputStream(uri)?.use { input ->
                // At most one byte past the limit is read, so a huge file is refused without being loaded.
                val bytes = ByteArray(SettingsTransfer.MAX_BYTES + 1)
                var read = 0
                while (read < bytes.size) {
                    val n = input.read(bytes, read, bytes.size - read)
                    if (n < 0) break
                    read += n
                }
                String(bytes, 0, read, Charsets.UTF_8)
            } ?: error("no stream")
        } catch (e: Exception) {
            _message.value = "The file could not be read."
            return@launch
        }
        when (val result = SettingsTransfer.import(text, graph.settings.current())) {
            is SettingsTransfer.Result.Rejected -> _message.value = result.reason

            is SettingsTransfer.Result.Applied -> {
                graph.settings.update { current ->
                    (SettingsTransfer.import(text, current) as? SettingsTransfer.Result.Applied)?.settings
                        ?: current
                }
                _message.value = "Settings imported"
            }
        }
    }

    private val _captureTest = MutableStateFlow<com.echoledger.mobile.recording.CaptureTest.Result?>(null)
    val captureTest: StateFlow<com.echoledger.mobile.recording.CaptureTest.Result?> = _captureTest.asStateFlow()

    private val _captureTesting = MutableStateFlow(false)
    val captureTesting: StateFlow<Boolean> = _captureTesting.asStateFlow()

    private val _farSideTest = MutableStateFlow<String?>(null)

    /** The helper's far-side self-test: null (not run), "running", "ok …" or "fail: …" (since v1.3). */
    val farSideTest: StateFlow<String?> = _farSideTest.asStateFlow()

    /** Setup → Call capture → Test the other side: arms the voice-call mix and hears a soft beep back (1-2 s, no call needed). */
    fun runFarSideTest() = viewModelScope.launch {
        val helper = graph.helper.helper
        if (helper == null) {
            _farSideTest.value = "fail: the helper is not running. Connect it above first."
            return@launch
        }
        _farSideTest.value = "running"
        _farSideTest.value = withContext(Dispatchers.IO) {
            runCatching { helper.selfTest() }.getOrElse { "fail: ${it.message ?: "the helper is too old: restart it"}" }
        }
        com.echoledger.mobile.recording.CaptureLog.add(getApplication(), "far-side test", _farSideTest.value.orEmpty())
    }

    /** Setup → Call capture → Test call capture (about ten seconds; nothing is kept or uploaded). */
    fun runCaptureTest() = viewModelScope.launch {
        if (_captureTesting.value) return@launch
        if (com.echoledger.mobile.recording.RecordingService.isRecording) {
            _message.value = "Stop the recording first, then run the test."
            return@launch
        }
        _captureTesting.value = true
        _captureTest.value = null
        try {
            val settings = graph.settings.current()
            _captureTest.value = com.echoledger.mobile.recording.CaptureTest.run(
                getApplication(),
                graph.helper.helper,
                graph.helper.state.value.stale,
                com.echoledger.mobile.recording.CaptureTest.micSourceFor(settings.micSource)
            )
        } finally {
            _captureTesting.value = false
        }
    }

    /** The text "Copy diagnostics" puts on the clipboard (no audio, names or numbers). */
    fun captureDiagnostics(): String {
        val h = graph.helper.state.value
        val state = if (h.connected) {
            val caps = runCatching { graph.helper.helper?.capabilities() }.getOrNull()
            "connected via ${h.source}, uid ${h.helperUid}${if (h.stale) ", older version" else ""}${caps?.let { " ($it)" } ?: ""}"
        } else {
            "not connected (${h.error ?: "never started"})"
        }
        return com.echoledger.mobile.recording.CaptureLog.report(getApplication(), state)
    }

    fun connectShizuku() {
        val h = graph.helper
        when {
            !h.shizukuAvailable() -> _message.value = "Shizuku is not running. Start it (Wireless debugging) and try again."
            !h.shizukuPermissionGranted() -> h.requestShizukuPermission()
            !h.bindShizuku() -> _message.value = "Could not start the capture helper through Shizuku."
        }
    }

    fun adbCommand(): String = graph.helper.adbCommand()

    // ---- Test translate (since v1.3, plan B3) ---------------------------------------------------------------------

    private val _testFeed = MutableStateFlow<com.echoledger.mobile.translate.TranslateFeed?>(null)

    /** The box text of the running test (null when none ran), for the preview on the Game and Watch pages. */
    val testFeed: StateFlow<com.echoledger.mobile.translate.TranslateFeed?> = _testFeed.asStateFlow()

    private var testJob: kotlinx.coroutines.Job? = null

    /**
     * Speaks the mode's test sentence with the phone's text-to-speech and plays it through Live Translate (Soniox,
     * the phone's real settings) at its real pace, so the preview shows what the box would show.
     */
    fun testTranslate(mode: com.echoledger.mobile.translate.TranslateMode) {
        testJob?.cancel()
        testJob = viewModelScope.launch(Dispatchers.IO) {
            _testFeed.value = com.echoledger.mobile.translate.TranslateFeed(
                status = com.echoledger.mobile.translate.TranslateFeed.Status.CONNECTING,
                notice = "Preparing the test voice…"
            )
            val (locale, text) = com.echoledger.mobile.translate.TestTranslate.sentence(mode)
            val wav = com.echoledger.mobile.translate.TestTranslate.synthesize(getApplication(), locale, text)
            val frames = wav?.let { com.echoledger.mobile.translate.TestTranslate.frames(it) }.orEmpty()
            if (frames.isEmpty()) {
                _testFeed.value = com.echoledger.mobile.translate.TranslateFeed(
                    status = com.echoledger.mobile.translate.TranslateFeed.Status.STOPPED,
                    notice = "This phone has no ${locale.displayLanguage} voice for the test. Add it in Settings → Text-to-speech."
                )
                return@launch
            }
            val prefs = graph.settings.current().liveTranslate
            val (target, hints, speed) = if (mode == com.echoledger.mobile.translate.TranslateMode.GAME) {
                Triple(prefs.game.target, prefs.game.spoken, prefs.game.speed)
            } else {
                Triple(prefs.watch.target, prefs.watch.spoken, prefs.watch.speed)
            }
            val usage = com.echoledger.mobile.translate.TranslateUsage(getApplication())
            val session = com.echoledger.mobile.translate.LiveTranslateSession(
                okhttp3.OkHttpClient(),
                this,
                com.echoledger.mobile.translate.SpeedPreset.of(speed),
                fetchToken = {
                    val api =
                        graph.api() ?: throw com.echoledger.mobile.translate.TokenUnavailable("Pair this phone first.", permanent = true)
                    try {
                        api.liveTranslateToken(mode.key, target, hints, continueOverBudget = prefs.budgetOverrideMonth == usage.month())
                    } catch (e: com.echoledger.mobile.net.ApiException) {
                        throw com.echoledger.mobile.translate.TokenUnavailable(e.message, permanent = true)
                    }
                },
                onOpenMs = { usage.add(mode, it) }
            )
            val watcher = launch { session.feed.collect { _testFeed.value = it } }
            frames.forEach { frame ->
                session.send(listOf(frame))
                delay(100)
            }
            session.speechEnded()
            delay(4_000)
            session.stop()
            watcher.cancel()
        }
    }

    fun clearTestTranslate() {
        testJob?.cancel()
        _testFeed.value = null
    }

    // ---- Audio probe (since v1.3, plan B0) ------------------------------------------------------------------------

    /** The players the helper sees right now; empty without a helper. */
    suspend fun probePlayers(): List<com.echoledger.mobile.translate.ProbePlayer> = withContext(Dispatchers.IO) {
        val h = graph.helper.helper ?: return@withContext emptyList()
        com.echoledger.mobile.translate.AudioProbe.parse(runCatching { h.players() }.getOrNull())
    }

    /**
     * Arms a mix for [player]'s app (beside the picked games), then captures [seconds] of its sound: the 16 kHz PCM
     * and why nothing came, if so. [onLevel] gets the level (0-1) of each 100 ms.
     */
    suspend fun probeCapture(
        player: com.echoledger.mobile.translate.ProbePlayer,
        seconds: Int,
        onLevel: (Float) -> Unit
    ): Pair<ShortArray, String?> = withContext(Dispatchers.IO) {
        val h = graph.helper.helper ?: return@withContext ShortArray(0) to "The capture helper is not running."
        val source = com.echoledger.mobile.translate.HelperAppSource(
            h,
            player.uid,
            if (player.voiceChat) player.usage else 0,
            voiceChat = player.voiceChat
        )
        val frames = java.util.Collections.synchronizedList(mutableListOf<ShortArray>())
        val problem = source.start({ frame ->
            frames += frame
            onLevel((com.echoledger.mobile.translate.SpeechGate.rms(frame) / 8_000.0).toFloat().coerceIn(0f, 1f))
        }, {})
        if (problem != null) return@withContext ShortArray(0) to problem
        delay(seconds * 1_000L)
        source.stop()
        val all = synchronized(frames) { frames.flatMap { it.asList() }.toShortArray() }
        all to null
    }

    companion object {
        const val NOT_A_PAIRING_QR = "That isn't an EchoLedger pairing code. In your admin open Devices → Pair a device."
    }
}
