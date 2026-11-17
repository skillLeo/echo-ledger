package com.echoledger.mobile

import android.app.Application
import android.content.Context
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.core.ShareGuardMemory
import com.echoledger.mobile.data.LibraryRepository
import com.echoledger.mobile.data.RecordingCipher
import com.echoledger.mobile.data.RecordingStore
import com.echoledger.mobile.data.SecretCipher
import com.echoledger.mobile.data.SettingsStore
import com.echoledger.mobile.data.TinkRecordingCipher
import com.echoledger.mobile.data.TinkSecretCipher
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.privilege.HelperConnection
import com.echoledger.mobile.recording.AutoRecordKeeper
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob

class EchoLedgerApp : Application() {
    lateinit var graph: AppGraph
        private set

    override fun onCreate() {
        super.onCreate()
        // Screen-share privacy first (a small preference file, not the settings store): a floating window or the Quick
        // Settings tile that comes up next already knows the setting (the person's Hide lasts the session only).
        ShareGuard.restore(ShareGuardMemory(this))
        // The capture journal (since v1.8): a call the last process left open was lost with it.
        com.echoledger.mobile.recording.CallJournal.processStarted(this)
        graph = AppGraph(this)
        Notifications.createChannels(this)
        graph.helper.autoConnect()
        // This phone's Live Assist card comes and goes with its session.
        com.echoledger.mobile.overlay.AssistCardWindow.follow(this, graph.scope)
        // Auto-record comes back on by itself after an update or a restart, without the app being opened.
        cancelStartCheck = AutoRecordKeeper.onAppStart(this)
    }

    /** Calls off the auto-record check of this process start (tests set up their own state). */
    var cancelStartCheck: () -> Unit = {}
        private set
}

/** Minimal service locator (no DI framework: fewer dependencies, smaller APK). */
class AppGraph(val context: Context) {
    val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    val secretCipher: SecretCipher by lazy { TinkSecretCipher(context) }
    val recordingCipher: RecordingCipher by lazy { TinkRecordingCipher(context) }
    val settings: SettingsStore by lazy {
        // Every saved change of During screen sharing reaches the floating windows and ShareGuard's memory at once.
        SettingsStore(context, secretCipher) { saved ->
            ShareGuard.setMode(ShareGuard.Mode.of(saved.screenShareMode))
            // The capture journal keeps the choices that decide whether a call should be recorded, as they change.
            com.echoledger.mobile.recording.CallJournal.choices(context, com.echoledger.mobile.recording.CallJournal.choicesOf(saved))
        }
    }
    val recordings: RecordingStore by lazy { RecordingStore(context, recordingCipher) }
    val library: LibraryRepository by lazy { LibraryRepository(context, secretCipher) { api() } }
    val helper: HelperConnection by lazy { HelperConnection(context) }

    /** When this phone last sent its device report (Help v2's Contact support). */
    val deviceReports: com.echoledger.mobile.help.DeviceReports by lazy { com.echoledger.mobile.help.DeviceReports(context) }

    /** Help v2 on this phone's API client. */
    val help: com.echoledger.mobile.help.PhoneHelpSource by lazy { com.echoledger.mobile.help.PhoneHelpSource({ api() }, deviceReports) }

    @Volatile private var cached: Pair<String, ApiClient>? = null

    /** Pinned API client for the paired server, or null when not paired. Reused to keep one connection pool. */
    suspend fun api(): ApiClient? {
        val s = settings.current()
        val url = s.serverUrl ?: return null
        val token = settings.token() ?: return null
        val key = "$url|${s.pins.joinToString()}|${token.hashCode()}"
        cached?.let { (k, client) -> if (k == key) return client }
        return ApiClient(url, s.pins, token).also { cached = key to it }
    }

    fun invalidateApi() {
        cached = null
    }
}
