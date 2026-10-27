package com.echoledger.mobile

import android.app.Application
import android.content.Context
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
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob

class EchoLedgerApp : Application() {
    lateinit var graph: AppGraph
        private set

    override fun onCreate() {
        super.onCreate()
        graph = AppGraph(this)
        Notifications.createChannels(this)
        graph.helper.autoConnect()
    }
}

/** Minimal service locator (no DI framework: fewer dependencies, smaller APK). */
class AppGraph(val context: Context) {
    val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
    val secretCipher: SecretCipher by lazy { TinkSecretCipher(context) }
    val recordingCipher: RecordingCipher by lazy { TinkRecordingCipher(context) }
    val settings: SettingsStore by lazy { SettingsStore(context, secretCipher) }
    val recordings: RecordingStore by lazy { RecordingStore(context, recordingCipher) }
    val library: LibraryRepository by lazy { LibraryRepository(context, secretCipher) { api() } }
    val helper: HelperConnection by lazy { HelperConnection(context) }

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
