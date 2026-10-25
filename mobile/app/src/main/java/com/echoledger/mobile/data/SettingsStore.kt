package com.echoledger.mobile.data

import android.content.Context
import android.util.Base64
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map
import kotlinx.serialization.Serializable

private val Context.dataStore by preferencesDataStore(name = "echoledger_settings")

@Serializable
data class AppSettings(
    val serverUrl: String? = null,
    val pins: List<String> = emptyList(),
    val deviceId: String? = null,
    val deviceName: String? = null,
    val autoRecordArmed: Boolean = false,
    val captionsEnabled: Boolean = true,
    val consentAcknowledged: Boolean = false,
    val clientId: String? = null,
    val micSource: String = "voice_communication",
    /** Require a fingerprint/face/PIN unlock when the app is opened. */
    val appLockEnabled: Boolean = false,
    /** How long the app may stay in the background before the lock engages again: 0, 60 000 or 300 000 ms. */
    val lockAfterMs: Long = 0L,
    /** While the lock is on, hide the app's content in Recents and screenshots (FLAG_SECURE). */
    val secureScreen: Boolean = true,
    /** What happens to a recording nobody decided on within 60 s: "keep", "discard" or "wait" (same as the desktop). */
    val decisionTimeout: String = "keep"
) {
    val paired: Boolean get() = serverUrl != null && deviceId != null
}

/** Non-secret settings in DataStore; the device token is stored separately, encrypted with the Keystore keyset. */
class SettingsStore(private val context: Context, private val cipher: SecretCipher) {
    private object Keys {
        val SETTINGS = stringPreferencesKey("settings_json")
        val TOKEN = stringPreferencesKey("device_token_enc")
        val SETUP_SEEN = booleanPreferencesKey("setup_seen")
    }

    val settings: Flow<AppSettings> = context.dataStore.data.map { it.toSettings() }

    suspend fun current(): AppSettings = settings.first()

    suspend fun update(transform: (AppSettings) -> AppSettings) {
        context.dataStore.edit { prefs ->
            prefs[Keys.SETTINGS] = ApiJson.encodeToString(AppSettings.serializer(), transform(prefs.toSettings()))
        }
    }

    suspend fun saveToken(token: String) {
        val enc = cipher.encrypt(token.toByteArray(), TOKEN_CONTEXT)
        context.dataStore.edit { it[Keys.TOKEN] = Base64.encodeToString(enc, Base64.NO_WRAP) }
    }

    suspend fun token(): String? {
        val stored = context.dataStore.data.first()[Keys.TOKEN] ?: return null
        return runCatching { String(cipher.decrypt(Base64.decode(stored, Base64.NO_WRAP), TOKEN_CONTEXT)) }.getOrNull()
    }

    suspend fun clearPairing() {
        context.dataStore.edit {
            it.remove(Keys.TOKEN)
            it[Keys.SETTINGS] = ApiJson.encodeToString(AppSettings.serializer(), it.toSettings().copy(deviceId = null, deviceName = null))
        }
    }

    private fun Preferences.toSettings(): AppSettings =
        this[Keys.SETTINGS]?.let { runCatching { ApiJson.decodeFromString(AppSettings.serializer(), it) }.getOrNull() } ?: AppSettings()

    private companion object {
        val TOKEN_CONTEXT = "echoledger.device_token".toByteArray()
    }
}
