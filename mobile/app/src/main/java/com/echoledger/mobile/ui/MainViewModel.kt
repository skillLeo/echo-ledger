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
import com.echoledger.mobile.core.Owner
import com.echoledger.mobile.core.PairingPayload
import com.echoledger.mobile.core.SettingsTransfer
import com.echoledger.mobile.core.SignIn
import com.echoledger.mobile.data.AndroidReleaseDto
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
import com.echoledger.mobile.update.SelfUpdate
import com.echoledger.mobile.update.UpdatePrefs
import com.echoledger.mobile.update.UpdateStatus
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
        SelfUpdate.restore(app)
    }

    /** Since v1.5: the website build's own updates (the Google Play build always stays idle). */
    val updateStatus: StateFlow<UpdateStatus> = SelfUpdate.status

    private val _updateHiddenCode = MutableStateFlow(UpdatePrefs(app).hiddenCode)
    val updateHiddenCode: StateFlow<Int> = _updateHiddenCode.asStateFlow()

    fun checkForUpdates() = viewModelScope.launch { SelfUpdate.check(getApplication(), graph.api(), force = true) }

    fun installUpdate(release: AndroidReleaseDto) = viewModelScope.launch {
        val api = graph.api() ?: return@launch
        SelfUpdate.install(getApplication(), api, release)
    }

    /** Closes the Record tab's banner for this version; Settings → About still offers it. */
    fun hideUpdateBanner(release: AndroidReleaseDto) {
        UpdatePrefs(getApplication()).hiddenCode = release.code
        _updateHiddenCode.value = release.code
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
                ).register(payload.code, name, BuildConfig.VERSION_NAME, Build.VERSION.RELEASE, installId())
            }
            finishSignIn(payload.url, payload.pins, response)
            _message.value = "Signed in to ${payload.url.removePrefix("https://")}"
        } catch (e: Exception) {
            _message.value = e.message ?: "Could not sign in"
        } finally {
            _busy.value = false
        }
    }

    /** What a pairing and every sign-in end with: the token (Keystore-encrypted), the server and its config. */
    private suspend fun finishSignIn(
        serverUrl: String,
        pins: List<String>,
        response: com.echoledger.mobile.data.RegisterResponse,
        email: String? = null
    ) {
        val previous = graph.settings.current().let { it.account ?: it.serverUrl?.let { url -> Owner.of(url, null) } }
        graph.settings.saveToken(response.token)
        val finalPins = response.config.pins.ifEmpty { pins }
        graph.settings.update {
            it.copy(
                serverUrl = serverUrl,
                pins = finalPins,
                deviceId = response.device.id,
                deviceName = response.device.name,
                serverApiVersion = response.config.apiVersion,
                markerTags = response.config.markers?.tags.orEmpty(),
                unsureStyle = response.config.unsure?.style ?: "brackets",
                unsureThreshold = response.config.unsure?.threshold ?: 0.5,
                unsureNote = response.config.unsure?.note ?: true,
                plan = com.echoledger.mobile.core.PlanState.from(response.config.plan, response.config.workspace)
            )
        }
        graph.invalidateApi()
        // Since v1.5: whose account this is (the typed email, else the server's answer; an older server has none), and
        // recordings made under another account wait for it instead of uploading into this one.
        val known = email ?: withContext(Dispatchers.IO) { runCatching { graph.api()?.account()?.email }.getOrNull() }
        val next = Owner.of(serverUrl, known)
        withContext(Dispatchers.IO) {
            Owner.stamp(graph.recordings.all(), previous, next).forEach { graph.recordings.save(it) }
        }
        graph.settings.update { it.copy(account = next) }
        retryPending()
        refresh()
    }

    private fun deviceName(typed: String): String = typed.ifBlank { "${Build.MANUFACTURER} ${Build.MODEL}".trim() }

    /** Asked for the two-factor code after an email and password were right (since v1.5). */
    private val _otpNeeded = MutableStateFlow(false)
    val otpNeeded: StateFlow<Boolean> = _otpNeeded.asStateFlow()

    /** Signs this phone in with an account's email and password (and the two-factor code once asked). */
    fun signIn(serverUrl: String, email: String, password: String, otp: String?, typedName: String) = viewModelScope.launch {
        _busy.value = true
        val url = SignIn.serverUrl(serverUrl)
        try {
            val response = withContext(Dispatchers.IO) {
                ApiClient(url, emptyList(), token = null)
                    .signInWithPassword(email.trim(), password, otp, deviceName(typedName), BuildConfig.VERSION_NAME, Build.VERSION.RELEASE)
            }
            _otpNeeded.value = false
            finishSignIn(url, emptyList(), response, email.trim())
            _message.value = "Signed in as ${email.trim()}"
        } catch (e: com.echoledger.mobile.net.ApiException) {
            if (e.code == "otp_required") _otpNeeded.value = true
            _message.value = SignIn.message(e.code, e.message)
        } catch (e: Exception) {
            _message.value = e.message ?: "Signing in failed"
        } finally {
            _busy.value = false
        }
    }

    // ---- The one sign-in flow (since v1.6, P1): email first, then password or an emailed code, new accounts too ----

    private val _signInState = MutableStateFlow(com.echoledger.mobile.core.SignInFlow.State())
    val signInState: StateFlow<com.echoledger.mobile.core.SignInFlow.State> = _signInState.asStateFlow()

    /** The server the sign-in uses: the build's default (production), unless one was typed under Advanced. */
    private val _signInServer = MutableStateFlow(defaultServer)
    val signInServer: StateFlow<String> = _signInServer.asStateFlow()

    fun setSignInServer(typed: String) {
        _signInServer.value = typed
    }

    private fun signInUrl(): String = SignIn.serverUrl(_signInServer.value.ifBlank { defaultServer })

    private fun installId(): String = com.echoledger.mobile.core.InstallId.get(getApplication())

    /** What the server's sign-in offers (Google, sign-up), read when the sign-in screen opens; null for an older server. */
    private val _authOptions = MutableStateFlow<com.echoledger.mobile.data.AuthOptionsDto?>(null)
    val authOptions: StateFlow<com.echoledger.mobile.data.AuthOptionsDto?> = _authOptions.asStateFlow()

    fun loadAuthOptions() = viewModelScope.launch {
        val url = signInUrl()
        if (url.length <= "https://".length) return@launch
        _authOptions.value = withContext(Dispatchers.IO) {
            runCatching { ApiClient(url, emptyList(), token = null).authOptions() }.getOrNull()
        }
    }

    fun signInEmail(email: String) {
        _signInState.update { it.copy(email = email, error = null) }
    }

    fun signInBack() {
        _signInState.update { com.echoledger.mobile.core.SignInFlow.back(it) }
    }

    private suspend fun <T> signInStep(block: suspend (ApiClient) -> T): T? {
        _busy.value = true
        return try {
            withContext(Dispatchers.IO) { block(ApiClient(signInUrl(), emptyList(), token = null)) }
        } catch (e: com.echoledger.mobile.net.ApiException) {
            if (e.code == "otp_required") {
                _signInState.update {
                    it.copy(
                        otpAfter =
                            it.step.takeIf { s ->
                                s != com.echoledger.mobile.core.SignInFlow.Step.OTP
                            } ?: it.otpAfter,
                        step = com.echoledger.mobile.core.SignInFlow.Step.OTP,
                        error = null
                    )
                }
            } else {
                _signInState.update { it.copy(error = com.echoledger.mobile.core.SignInFlow.message(e.code, e.message)) }
            }
            null
        } catch (e: IllegalArgumentException) {
            _signInState.update { it.copy(error = e.message ?: "Check the server address.") }
            null
        } catch (e: Exception) {
            _signInState.update { it.copy(error = "Can't reach the server. Check the connection and try again.") }
            null
        } finally {
            _busy.value = false
        }
    }

    /** "Continue" after the email. */
    fun signInContinue() = viewModelScope.launch {
        val state = _signInState.value
        if (!com.echoledger.mobile.core.SignInFlow.validEmail(state.email)) {
            _signInState.update { it.copy(error = "Enter your email address.") }
            return@launch
        }
        _busy.value = true
        val answer = try {
            withContext(Dispatchers.IO) { ApiClient(signInUrl(), emptyList(), token = null).authStart(state.email.trim()) }
        } catch (e: com.echoledger.mobile.net.ApiException) {
            _busy.value = false
            // A server older than v1.6 has no auth/start: its password sign-in, as before.
            if (e.status == 404 || e.status == 405) {
                _signInState.update { com.echoledger.mobile.core.SignInFlow.olderServer(it) }
            } else {
                _signInState.update { it.copy(error = com.echoledger.mobile.core.SignInFlow.message(e.code, e.message)) }
            }
            return@launch
        } catch (e: IllegalArgumentException) {
            _busy.value = false
            _signInState.update { it.copy(error = e.message ?: "Check the server address.") }
            return@launch
        } catch (e: Exception) {
            _busy.value = false
            _signInState.update { it.copy(error = "Can't reach the server. Check the connection and try again.") }
            return@launch
        }
        _busy.value = false
        val (next, action) = com.echoledger.mobile.core.SignInFlow.afterStart(state, answer)
        _signInState.value = next
        sendCodeFor(action)
    }

    private suspend fun sendCodeFor(action: com.echoledger.mobile.core.SignInFlow.Action) {
        val purpose = when (action) {
            com.echoledger.mobile.core.SignInFlow.Action.SEND_SIGN_IN_CODE -> "sign_in"
            com.echoledger.mobile.core.SignInFlow.Action.SEND_SIGN_UP_CODE -> "sign_up"
            com.echoledger.mobile.core.SignInFlow.Action.NONE -> return
        }
        val email = _signInState.value.email
        val sent = signInStep { it.authCode(email, purpose) } ?: return
        _signInState.update { it.copy(resendAtMs = android.os.SystemClock.elapsedRealtime() + sent.resendAfter * 1000L, error = null) }
    }

    /** "Email me a code" on the password step. */
    fun signInUseCode() = viewModelScope.launch {
        val (next, action) = com.echoledger.mobile.core.SignInFlow.useCode(_signInState.value)
        _signInState.value = next
        sendCodeFor(action)
    }

    /** "Send again" on the code step. */
    fun signInResend() = viewModelScope.launch {
        sendCodeFor(
            if (_signInState.value.newAccount) {
                com.echoledger.mobile.core.SignInFlow.Action.SEND_SIGN_UP_CODE
            } else {
                com.echoledger.mobile.core.SignInFlow.Action.SEND_SIGN_IN_CODE
            }
        )
    }

    /** The password (and the two-factor code once asked). */
    fun signInWithPassword(password: String, otp: String?) = viewModelScope.launch {
        val email = _signInState.value.email
        val response = signInStep {
            it.signInWithPassword(email, password, otp, deviceName(""), BuildConfig.VERSION_NAME, Build.VERSION.RELEASE, installId())
        } ?: return@launch
        finishSignIn(signInUrl(), emptyList(), response, email)
        _signInState.value = com.echoledger.mobile.core.SignInFlow.State()
        _message.value = "Signed in as $email"
    }

    /** The emailed code (and the two-factor code once asked). */
    fun signInWithCode(code: String, otp: String?) = viewModelScope.launch {
        val email = _signInState.value.email
        val result = signInStep {
            it.authCodeVerify(email, code.trim(), otp, deviceName(""), BuildConfig.VERSION_NAME, Build.VERSION.RELEASE, installId())
        } ?: return@launch
        when (result) {
            is ApiClient.CodeResult.SignedIn -> {
                finishSignIn(signInUrl(), emptyList(), result.response, email)
                _signInState.value = com.echoledger.mobile.core.SignInFlow.State()
                _message.value = "Signed in as $email"
            }

            is ApiClient.CodeResult.NewAccount -> _signInState.update {
                it.copy(step = com.echoledger.mobile.core.SignInFlow.Step.PROFILE, signUpToken = result.next.signUpToken, error = null)
            }
        }
    }

    /** The new account's name, with the Terms accepted. */
    fun signInCreateAccount(name: String) = viewModelScope.launch {
        val state = _signInState.value
        val token = state.signUpToken ?: return@launch
        val response = signInStep {
            it.authSignUp(token, name.trim(), deviceName(""), BuildConfig.VERSION_NAME, Build.VERSION.RELEASE, installId())
        } ?: return@launch
        finishSignIn(signInUrl(), emptyList(), response, state.email)
        _signInState.value = com.echoledger.mobile.core.SignInFlow.State()
        _message.value = "Welcome to EchoLedger"
    }

    /** The browser sign-in being made (since v1.5): its server, PKCE verifier, state and the phone's name. */
    private var browserSignIn: SignIn.Browser? = null

    /** Starts the browser sign-in (Google, passkeys, …); the page sends the phone back to echoledger://signed-in. */
    fun browserSignInUrl(serverUrl: String, typedName: String): String {
        val started = SignIn.Browser.start(SignIn.serverUrl(serverUrl), deviceName(typedName))
        browserSignIn = started
        return started.url()
    }

    /** echoledger://signed-in?code=…&state=… came back: the code becomes this phone's token. */
    fun completeBrowserSignIn(code: String?, state: String?) = viewModelScope.launch {
        val started = browserSignIn
        if (started == null || code.isNullOrBlank() || state != started.state) {
            _message.value = "That sign-in link is not for this sign-in. Start again."
            return@launch
        }
        browserSignIn = null
        _busy.value = true
        try {
            val response = withContext(Dispatchers.IO) {
                ApiClient(started.serverUrl, emptyList(), token = null)
                    .deviceToken(code, started.verifier, started.deviceName, BuildConfig.VERSION_NAME, Build.VERSION.RELEASE, installId())
            }
            finishSignIn(started.serverUrl, emptyList(), response)
            _message.value = "Signed in"
        } catch (e: com.echoledger.mobile.net.ApiException) {
            _message.value = SignIn.message(e.code, e.message)
        } catch (e: Exception) {
            _message.value = e.message ?: "Signing in failed"
        } finally {
            _busy.value = false
        }
    }

    suspend fun account(): com.echoledger.mobile.data.AccountDto? = workspaceCall("The account could not be loaded.") { it.account() }

    /** Signs out every device and console session of the account, this phone too (back to the sign-in screen). */
    suspend fun signOutEverywhere(): Boolean {
        workspaceCall("Signing out failed.") { it.signOutEverywhere() } ?: return false
        graph.settings.clearPairing()
        graph.library.clear()
        return true
    }

    /** Asks for the account's deletion (7 days to change one's mind); the day it will be deleted, or null. */
    suspend fun deleteAccount(password: String?, otp: String?): String? =
        workspaceCall("The account could not be deleted.") { it.deleteAccount(password, otp) }

    suspend fun startExport(): com.echoledger.mobile.data.AccountExportDto? = workspaceCall("The export could not be started.") {
        it.startExport()
    }

    suspend fun exportStatus(id: String): com.echoledger.mobile.data.AccountExportDto? =
        workspaceCall("The export could not be checked.") { it.exportStatus(id) }

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

    /** The live status (since 1.3.2): the library again, without the pull-to-refresh spinner or a config fetch. */
    fun refreshQuietly() = viewModelScope.launch(Dispatchers.IO) {
        runCatching { graph.library.refresh() }
    }

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
                                ),
                                plan = com.echoledger.mobile.core.PlanState.from(config.plan, config.workspace)
                            )
                        }
                    }
                } catch (e: ApiException) {
                    // No config answer is no reason to skip the library: keep the last known contract version.
                }
                graph.library.refresh()
            }
            com.echoledger.mobile.notify.ActivityWorker.schedule(getApplication())
            SelfUpdate.schedule(getApplication())
            _clients.value = withContext(Dispatchers.IO) { graph.api()?.clients()?.data.orEmpty() }
            WidgetStateStore.refresh(getApplication())
            _offline.value = false
        } catch (e: NotPairedException) {
            // Revoked on the server: back to the pairing screen. Recordings waiting to upload stay on the phone.
            graph.settings.clearPairing()
            graph.library.clear()
            graph.invalidateApi()
            WidgetStateStore.refresh(getApplication())
            _message.value = "This phone was signed out on the server. Sign in again."
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
        keep: Boolean? = null,
        title: String? = null
    ): Boolean {
        val ok = withContext(Dispatchers.IO) {
            runCatching { graph.api()?.updateSession(id, pinned, clientId, clearClient, labels, keep, title) ?: error("Not signed in") }
                .onFailure { _message.value = it.message ?: "The change could not be saved." }.isSuccess
        }
        if (ok) refresh()
        return ok
    }

    /** One action for the selected recordings; how many changed, or null (with a message) when it failed. */
    suspend fun bulk(ids: List<String>, action: String, clientId: String? = null, label: String? = null): Int? {
        val changed = withContext(Dispatchers.IO) {
            runCatching { graph.api()?.bulk(ids.take(Organise.MAX_BULK), action, clientId, label) ?: error("Not signed in") }
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
            .onFailure { _message.value = it.message ?: "The project could not be created." }.getOrNull()
            ?.also { created -> _clients.value = _clients.value.filterNot { it.id == created.id } + created }
    }

    // ---- Projects, filing rules and sharing (since v1.5) -----------------------------------------------------------

    private val _projectsTarget = MutableStateFlow<ProjectsTarget?>(null)

    /** Where the Projects tab should go next (a link opened in the app); the tab clears it once it went there. */
    val projectsTarget: StateFlow<ProjectsTarget?> = _projectsTarget.asStateFlow()

    fun openInProjects(target: ProjectsTarget?) {
        _projectsTarget.value = target
    }

    private suspend fun reloadClients() {
        withContext(Dispatchers.IO) { runCatching { graph.api()?.clients()?.data }.getOrNull() }?.let { _clients.value = it }
    }

    /** A new project, inside [parentId] when given; the list is reloaded so the counts and paths are the server's. */
    suspend fun createProject(name: String, parentId: String?): ClientDto? =
        workspaceCall("The project could not be created.") { it.createProject(name.trim(), parentId) }?.also { reloadClients() }

    suspend fun renameProject(id: String, name: String): Boolean = (
        workspaceCall("The project could not be renamed.") {
            it.updateProject(id, name = name.trim())
        } != null
        ).also { if (it) reloadClients() }

    /** Moves a project into [parentId], or to the top level when it is null. */
    suspend fun moveProject(id: String, parentId: String?): Boolean =
        (workspaceCall("The project could not be moved.") { it.updateProject(id, parentId = parentId, toTop = parentId == null) } != null)
            .also { if (it) reloadClients() }

    /** Deletes a project: its recordings go to the Inbox and its subfolders up a level (said in the message). */
    suspend fun deleteProject(id: String): Boolean {
        val answer = workspaceCall("The project could not be deleted.") { it.deleteProject(id) } ?: return false
        _message.value = buildString {
            append("Project deleted")
            if (answer.movedSessions > 0) append(" · ${countLabel(answer.movedSessions)} moved to the Inbox")
        }
        reloadClients()
        refresh()
        return true
    }

    suspend fun filingRules(clientId: String): List<com.echoledger.mobile.data.FilingRuleDto> =
        workspaceCall("The filing rules could not be loaded.") { it.filingRules(clientId) }.orEmpty()

    suspend fun addFilingRule(clientId: String, kind: String, value: String): com.echoledger.mobile.data.FilingRuleDto? =
        workspaceCall("The rule could not be added.") { it.addFilingRule(clientId, kind, value.trim()) }

    suspend fun deleteFilingRule(clientId: String, ruleId: Long): Boolean =
        workspaceCall("The rule could not be removed.") { it.deleteFilingRule(clientId, ruleId) } != null

    suspend fun createShareLink(
        sessionId: String?,
        clientId: String?,
        audience: String,
        password: String?,
        people: List<String>,
        allowDownload: Boolean,
        expiresAt: String?
    ): ShareLinkDto? = workspaceCall("The link could not be made.") {
        it.createShareLink(sessionId, clientId, audience, password, people, allowDownload, expiresAt)
    }

    suspend fun projectShares(clientId: String): List<ShareLinkDto> =
        workspaceCall("The links could not be loaded.") { it.projectShares(clientId).data }.orEmpty()

    /** Sends a recording or a project to teammates; true when it went (the message says to how many). */
    suspend fun sendToTeammates(sessionId: String?, clientId: String?, to: List<String>, note: String?): Boolean {
        val sent = workspaceCall("It could not be sent.") { it.sendToTeammates(sessionId, clientId, to, note) } ?: return false
        _message.value = if (sent == 1) "Sent to 1 person" else "Sent to $sent people"
        return true
    }

    /** What you sent of this recording or project; empty on a server older than this list. */
    suspend fun sentOf(sessionId: String?, clientId: String?): List<com.echoledger.mobile.data.SentItemDto> =
        withContext(Dispatchers.IO) { runCatching { graph.api()?.sentOf(sessionId, clientId) }.getOrNull().orEmpty() }

    suspend fun stopSharing(id: String): Boolean {
        workspaceCall("It could not be stopped.") { it.stopSharing(id) } ?: return false
        _message.value = "Stopped sharing"
        return true
    }

    suspend fun sharedWithMe(): List<com.echoledger.mobile.data.SharedItemDto>? =
        workspaceCall("Shared with me could not be loaded.") { it.sharedWithMe() }

    /** "Save to my library": a copy that is yours; the library is synced so it shows at once. */
    suspend fun saveShared(id: String): Boolean {
        workspaceCall("It could not be saved.") { it.saveShared(id) } ?: return false
        _message.value = "Saved to your library"
        refresh()
        return true
    }

    suspend fun removeShared(id: String): Boolean = workspaceCall("It could not be removed.") { it.removeShared(id) } != null

    suspend fun requestLinks(): List<com.echoledger.mobile.data.RequestLinkDto>? =
        workspaceCall("The request links could not be loaded.") { it.requestLinks() }

    suspend fun createRequestLink(title: String, clientId: String?, expiresAt: String?): com.echoledger.mobile.data.RequestLinkDto? =
        workspaceCall("The request link could not be made.") { it.createRequestLink(title.trim(), clientId, expiresAt) }

    suspend fun revokeRequestLink(id: String): Boolean =
        workspaceCall("The request link could not be turned off.") { it.revokeRequestLink(id) } != null

    /** A smart folder from the server (`imported`, `shared`); null when it could not be loaded. */
    suspend fun libraryFolder(folder: String): List<LibraryItem>? = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.libraryFolder(folder)?.data }.getOrNull()
    }

    /** The smart folders' counts on the server (since v1.5); null offline or from an older server. */
    suspend fun folderCounts(): Map<String, Int>? = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.libraryFolderCounts() }.getOrNull()
    }

    /** A project's screens (since v1.5), one page; null when they could not be loaded. */
    suspend fun projectScreens(
        clientId: String,
        includeSubfolders: Boolean,
        cursor: String?
    ): com.echoledger.mobile.data.ProjectScreensResponse? =
        withContext(Dispatchers.IO) { runCatching { graph.api()?.projectScreens(clientId, includeSubfolders, cursor) }.getOrNull() }

    /** A recording's screen moments (since v1.5); empty when there are none or they could not be loaded. */
    suspend fun screens(sessionId: String): List<com.echoledger.mobile.data.ScreenDto> = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.screens(sessionId) }.getOrNull().orEmpty()
    }

    /** A screen's picture from its signed link; null when it could not be loaded. */
    suspend fun screenImage(url: String): androidx.compose.ui.graphics.ImageBitmap? = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.screenImage(url) }.getOrNull()?.let(ScreenImages::decode)
    }

    /** The meeting pack (since v1.5): transcript with its screens, as a PDF or a ZIP in the private cache. */
    suspend fun meetingPack(sessionId: String, format: String, view: String?): File? = withContext(Dispatchers.IO) {
        try {
            val api = graph.api() ?: return@withContext null
            val (bytes, name) = api.meetingPack(sessionId, format, view)
            val dir = File(getApplication<Application>().cacheDir, "exports").apply {
                deleteRecursively()
                mkdirs()
            }
            File(dir, name).apply { writeBytes(bytes) }
        } catch (e: Exception) {
            _message.value = e.message ?: "The meeting pack could not be made."
            null
        }
    }

    /** What a link opened in the app is for this phone; null = open the web page. */
    suspend fun resolveLink(link: String): com.echoledger.mobile.data.ResolvedLinkDto? = withContext(Dispatchers.IO) {
        graph.api()?.resolveLink(link)
    }
    // ---- Client workspaces (since 1.4) -----------------------------------------------------------------------------

    /** Runs one workspace request; null (and the server's words as the message) when it failed. */
    private suspend fun <T> workspaceCall(failed: String, block: suspend (com.echoledger.mobile.net.ApiClient) -> T): T? =
        withContext(Dispatchers.IO) {
            runCatching { block(graph.api() ?: error("Sign in on this phone first.")) }
                .onFailure { _message.value = it.message ?: failed }.getOrNull()
        }

    suspend fun workspace(clientId: String) = workspaceCall("The project's workspace could not be loaded.") { it.workspace(clientId) }

    suspend fun setAiNotes(clientId: String, on: Boolean) = workspaceCall("The setting could not be saved.") {
        it.setClientAiNotes(clientId, on)
    }

    suspend fun saveBrief(clientId: String, sections: com.echoledger.mobile.data.BriefSections) =
        workspaceCall("The brief could not be saved.") { it.updateBrief(clientId, sections) }

    suspend fun refreshBrief(clientId: String) = workspaceCall("The brief could not be made again.") { it.refreshBrief(clientId) }

    suspend fun addMemory(clientId: String, kind: String, text: String) =
        workspaceCall("The line could not be added.") { it.addMemory(clientId, kind, text) }

    suspend fun updateMemory(clientId: String, item: String, text: String? = null, pinned: Boolean? = null) =
        workspaceCall("The line could not be saved.") { it.updateMemory(clientId, item, text, pinned) }

    suspend fun deleteMemory(clientId: String, item: String) = workspaceCall("The line could not be removed.") {
        it.deleteMemory(clientId, item)
    }

    /** Uploads picked files to a client's workspace; how many went up. */
    suspend fun addClientFiles(clientId: String, uris: List<android.net.Uri>): Int = workspaceCall("The files could not be added.") { api ->
        val context = getApplication<android.app.Application>()
        uris.count { uri ->
            val picked = com.echoledger.mobile.share.FileImport.describe(context, uri)
            val bytes = context.contentResolver.openInputStream(uri)?.use { it.readBytes() } ?: return@count false
            runCatching { api.uploadClientFile(clientId, picked.name, ClientFiles.mime(picked.mime, picked.name), bytes) }
                .onFailure { _message.value = it.message }.isSuccess
        }
    } ?: 0

    suspend fun addInstructions(clientId: String, name: String, text: String) =
        workspaceCall("The instructions could not be saved.") { it.addInstructions(clientId, name, text) }

    suspend fun deleteClientFile(clientId: String, file: String) = workspaceCall("The file could not be removed.") {
        it.deleteClientFile(clientId, file)
    }

    suspend fun ask(clientId: String, question: String, language: String) =
        workspaceCall("Claude could not answer right now. Try again in a minute.") { it.ask(clientId, question, language) }

    suspend fun bundle(clientId: String) = workspaceCall("The text could not be made.") { it.bundle(clientId) }

    /** Accepts or dismisses a suggested fix; the transcript as it is afterwards. */
    suspend fun decideSuggestion(transcriptId: String, id: Int, accept: Boolean) =
        workspaceCall("The suggestion could not be saved.") { it.decideSuggestion(transcriptId, id, accept) }

    suspend fun sessionNotes(sessionId: String) = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.sessionNotes(sessionId) }.getOrNull()
    }

    suspend fun makeNotes(sessionId: String, language: String) = workspaceCall("The notes could not be started.") {
        it.makeNotes(sessionId, language)
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
            runCatching { graph.api()?.restoreSession(id) ?: error("Not signed in") }
                .onSuccess { _message.value = "Restored" }
                .onFailure { _message.value = it.message ?: "The recording could not be restored." }.isSuccess
        }
        if (ok) refresh()
        return ok
    }

    /** Erases a binned recording now. */
    suspend fun eraseNow(id: String): Boolean = withContext(Dispatchers.IO) {
        runCatching { graph.api()?.eraseFromBin(id) ?: error("Not signed in") }
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
        runCatching { graph.api()?.revokeDevice(id) ?: error("Not signed in") }
            .onSuccess { _message.value = "Device revoked" }
            .onFailure { _message.value = it.message ?: "The device could not be revoked." }.isSuccess
    }
    fun delete(item: LibraryItem) = viewModelScope.launch {
        runCatching { withContext(Dispatchers.IO) { graph.api()?.deleteSession(item.id) } }
            .onSuccess { refresh() }
            .onFailure { _message.value = it.message }
    }

    /** Names, types and sizes of picked files (read off the main thread). */
    suspend fun describeFiles(uris: List<android.net.Uri>): List<com.echoledger.mobile.share.FileImport.Picked> =
        withContext(Dispatchers.IO) { uris.map { com.echoledger.mobile.share.FileImport.describe(getApplication(), it) } }

    /** Imports picked files with the batch's client and language (since 1.4); says what happened when done. */
    suspend fun importFiles(
        files: List<com.echoledger.mobile.share.FileImport.Picked>,
        clientId: String?,
        language: String?
    ): com.echoledger.mobile.share.FileImport.Summary = withContext(Dispatchers.IO) {
        com.echoledger.mobile.share.FileImport.import(getApplication(), graph, files, clientId, language).also {
            reloadLocal()
            _message.value = it.message()
        }
    }

    /** Cancels an upload that has not started (or removes a failed one): nothing of it reaches the server. */
    fun cancelUpload(id: String) = viewModelScope.launch(Dispatchers.IO) {
        val rec = graph.recordings.get(id) ?: return@launch
        if (rec.state == "uploading") return@launch
        com.echoledger.mobile.net.UploadWorker.cancel(getApplication(), id)
        graph.recordings.delete(id)
        reloadLocal()
        _message.value = if (rec.state == "failed") "Removed" else "Upload cancelled"
    }

    /** Removes a row that only reported something (an import the Library already had). */
    fun dismissLocal(id: String) = viewModelScope.launch(Dispatchers.IO) {
        graph.recordings.delete(id)
        reloadLocal()
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
            .onFailure { _message.value = it.message ?: "The project could not be updated." }
    }

    /** Keep or Discard; a [title] typed on the card is stored first, in the same step, so the upload always carries it. */
    fun decide(id: String, keep: Boolean, title: String? = null) = viewModelScope.launch(Dispatchers.IO) {
        if (keep && title != null) graph.recordings.update(id) { it.copy(title = title.trim().take(200).ifBlank { null }) }
        Decisions.apply(getApplication(), id, keep)
        reloadLocal()
    }

    /** Restore on Activity's "Skipped N short calls" (since v1.5): the call is kept and uploads now. */
    fun restoreSkipped(id: String) = viewModelScope.launch(Dispatchers.IO) {
        Decisions.restoreSkipped(getApplication(), id)
        reloadLocal()
        _message.value = "Restored — it uploads now"
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

    /** Ready check → Restart: the helper is stopped and started again through Shizuku (an older one gets this version). */
    fun restartHelper() {
        val h = graph.helper
        if (!h.shizukuAvailable()) {
            _message.value = "Shizuku is not running. Start it (Wireless debugging) and try again."
            return
        }
        h.unbindShizuku()
        if (!h.bindShizuku()) _message.value = "Could not start the capture helper through Shizuku."
    }

    fun adbCommand(): String = graph.helper.adbCommand()

    /** Send to my admin on the device check (since v1.5): the only thing that uploads it. */
    suspend fun sendDeviceReport(facts: com.echoledger.mobile.core.PhoneFacts, rows: List<com.echoledger.mobile.core.CheckRow>) {
        workspaceCall("The report could not be sent.") { it.reportDevice(facts, rows) } ?: return
        _message.value = "Sent to your admin"
    }

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
                    val api = graph.api()
                        ?: throw com.echoledger.mobile.translate.TokenUnavailable("Sign in on this phone first.", permanent = true)
                    try {
                        api.liveTranslateToken(mode.key, target, hints, continueOverBudget = prefs.budgetOverrideMonth == usage.month())
                    } catch (e: com.echoledger.mobile.net.ApiException) {
                        throw com.echoledger.mobile.translate.TokenUnavailable(e.message, permanent = true)
                    }
                },
                onOpenMs = { usage.add(mode, it) }
            )
            val watcher = launch {
                session.feed.collect {
                    _testFeed.value = it
                    when (it.status) {
                        com.echoledger.mobile.translate.TranslateFeed.Status.STOPPED ->
                            com.echoledger.mobile.translate.TranslateStatus.remember(getApplication(), it.notice)

                        com.echoledger.mobile.translate.TranslateFeed.Status.LIVE ->
                            com.echoledger.mobile.translate.TranslateStatus.remember(getApplication(), null)

                        else -> Unit
                    }
                }
            }
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
        /** The production server from the build (BuildConfig.DEFAULT_SERVER); tests set it to "" so nothing reaches it. */
        @Volatile var defaultServer: String = BuildConfig.DEFAULT_SERVER

        const val NOT_A_PAIRING_QR = "That isn't an EchoLedger sign-in code. In the console open Profile → Devices → Sign in a device."
    }
}
