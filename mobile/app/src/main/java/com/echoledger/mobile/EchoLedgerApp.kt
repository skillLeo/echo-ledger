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
import kotlinx.coroutines.launch

class EchoLedgerApp : Application() {
    lateinit var graph: AppGraph
        private set

    override fun onCreate() {
        super.onCreate()
        // Screen-share privacy first (a small preference file, not the settings store): a floating window or the Quick
        // Settings tile that comes up next already knows the setting (the person's Hide lasts the session only).
        ShareGuard.restore(ShareGuardMemory(this))
        // The person's design (classic or the new one), before any screen, overlay or notice asks the flags.
        com.echoledger.mobile.ui.v2.shell.DesignChoice.load(this)
        // The capture journal (since v1.8): a call the last process left open was lost with it.
        com.echoledger.mobile.recording.CallJournal.processStarted(this)
        graph = AppGraph(this)
        Notifications.createChannels(this)
        graph.helper.autoConnect()
        // This phone's Live Assist card comes and goes with its session.
        com.echoledger.mobile.overlay.AssistCardWindow.follow(this, graph.scope)
        // Auto-record comes back on by itself after an update or a restart, without the app being opened.
        val autoRecord = AutoRecordKeeper.onAppStart(this)
        // The settings read once as the app starts, off the main thread, so a window that opens next (the pill, the
        // after-call card) has them at its first frame (graph.liveSettings).
        val warm = Runnable {
            graph.scope.launch(Dispatchers.IO) {
                runCatching {
                    // The channels were named in the phone's language a moment ago: in the app's, when it has its own.
                    if (graph.settings.current().appLanguage != "system") Notifications.createChannels(this@EchoLedgerApp)
                }
            }
        }
        val main = android.os.Handler(android.os.Looper.getMainLooper())
        main.postDelayed(warm, SETTINGS_WARM_MS)
        cancelStartCheck = {
            autoRecord()
            main.removeCallbacks(warm)
        }
        // Send to WhatsApp's voice copies an hour old or more (WhatsApp has read them by then).
        graph.scope.launch(Dispatchers.IO) { com.echoledger.mobile.share.WhatsAppShare.clearOldCopies(this@EchoLedgerApp) }
    }

    /** Calls off the auto-record check and the settings read of this process start (tests set up their own state). */
    var cancelStartCheck: () -> Unit = {}
        private set
}

/** Minimal service locator (no DI framework: fewer dependencies, smaller APK). */
class AppGraph(val context: Context) {
    val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    val secretCipher: SecretCipher by lazy { TinkSecretCipher(context) }
    val recordingCipher: RecordingCipher by lazy { TinkRecordingCipher(context) }

    /** The app language and text size last saved in this process, so a change of either refreshes what Android draws. */
    @Volatile private var shownLook: Pair<String, String>? = null

    @OptIn(kotlinx.coroutines.ExperimentalCoroutinesApi::class)
    private val lookRefresh = Dispatchers.Default.limitedParallelism(1)

    val settings: SettingsStore by lazy {
        // Every saved change of During screen sharing reaches the floating windows and ShareGuard's memory at once.
        SettingsStore(context, secretCipher, latestSettings) { saved ->
            ShareGuard.setMode(ShareGuard.Mode.of(saved.screenShareMode))
            // The capture journal keeps the choices that decide whether a call should be recorded, as they change.
            com.echoledger.mobile.recording.CallJournal.choices(context, com.echoledger.mobile.recording.CallJournal.choicesOf(saved))
            // The app language or text size changed (or this process saves for the first time): what Android draws for
            // the app (the widget, the notification channels' names) follows, as the v2 screens do.
            val look = saved.appLanguage to saved.textSize
            if (shownLook != look) {
                // One after another, each with the latest language: a slow one never names them after an older choice.
                scope.launch(lookRefresh) {
                    runCatching { com.echoledger.mobile.notify.Notifications.createChannels(context) }
                    runCatching { com.echoledger.mobile.widget.WidgetStateStore.refresh(context) }
                }
            }
            shownLook = look
        }
    }

    /**
     * The stored settings, kept hot for the whole process: every read and save of the store updates it, and the app
     * reads them once as it starts ([EchoLedgerApp.onCreate]). What draws outside the activity starts from its value
     * at once, never waiting on the store; null only until the store has been read the first time.
     */
    val liveSettings: kotlinx.coroutines.flow.StateFlow<com.echoledger.mobile.data.AppSettings?> get() = latestSettings

    /** Kept here, not in the store: reading [liveSettings] never makes the store (it opens the settings file). */
    private val latestSettings = kotlinx.coroutines.flow.MutableStateFlow<com.echoledger.mobile.data.AppSettings?>(null)

    val recordings: RecordingStore by lazy { RecordingStore(context, recordingCipher) }
    val library: LibraryRepository by lazy { LibraryRepository(context, secretCipher) { api() } }
    val helper: HelperConnection by lazy { HelperConnection(context) }

    /** When this phone last sent its device report (Help v2's Contact support). */
    val deviceReports: com.echoledger.mobile.help.DeviceReports by lazy { com.echoledger.mobile.help.DeviceReports(context) }

    /** Help v2 on this phone's API client. */
    val help: com.echoledger.mobile.help.PhoneHelpSource by lazy {
        com.echoledger.mobile.help.PhoneHelpSource({ api() }, deviceReports)
    }

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

/** How soon after the process starts the settings are read for the windows outside the app. */
private const val SETTINGS_WARM_MS = 50L
