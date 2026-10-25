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
    val decisionTimeout: String = "keep",
    /** The server's `api_version` ("1.1") from the last config; null for a v1 server or before the first fetch. */
    val serverApiVersion: String? = null,
    /** Per call app (`source_app` wire value): false keeps auto-record from recording it. Missing apps are on. */
    val autoRecordApps: Map<String, Boolean> = emptyMap(),
    /** How the floating caption bubble looks (B4). */
    val captionStyle: CaptionStyle = CaptionStyle(),
    /** Warn when a call has not heard the other side for 30 seconds (B5). */
    val farSideWarning: Boolean = true,
    /** Bookmark tag presets from the last server config; empty = the defaults. */
    val markerTags: List<String> = emptyList(),
    /** Microphone kind for recordings made on this phone (`builtin`, `wired`, `bluetooth`, `usb`); null = automatic. */
    val inputDevice: String? = null,
    /** Language of new recordings: null (the client's or the console default, else automatic), `en`, `ur`, `hi` or `en+ur`. */
    val transcriptionLanguage: String? = null,
    /** Voice keyboard (B14): `toggle` (tap to start, tap to insert) or `push_to_talk` (hold the mic). */
    val dictationMode: String = "toggle",
    val dictationCleanup: Boolean = true,
    val dictationSounds: Boolean = true,
    val dictationReplacements: List<com.echoledger.mobile.dictation.Replacement> = emptyList(),
    val dictationSnippets: List<com.echoledger.mobile.dictation.Snippet> = emptyList(),
    /** Activity centre (B18): show new activity as notifications, except in the quiet hours (local `HH:MM`). */
    val activityNotifications: Boolean = true,
    val quietHours: Boolean = false,
    val quietFrom: String = "22:00",
    val quietTo: String = "07:00",
    /** The first-run wizard has been finished or skipped (B19). Installs paired before v1.1 count as done. */
    val onboardingDone: Boolean = false
) {
    val serverIsV1_1: Boolean get() = serverApiVersion != null
    val paired: Boolean get() = serverUrl != null && deviceId != null
}

/**
 * Caption bubble style (B4): text size, background opacity (40-100 %), visible lines (2, 3 or 5), position (top or
 * bottom of the screen), compact mode (colours instead of speaker labels), auto-hide after 0 / 5 / 10 / 30 s
 * without captions, and speaker colours as `#rrggbb` (null = theme colour).
 */
@Serializable
data class CaptionStyle(
    val size: String = "medium",
    val opacity: Int = 85,
    val lines: Int = 3,
    val position: String = "bottom",
    val compact: Boolean = false,
    val autoHideSeconds: Int = 0,
    val meColor: String? = null,
    val clientColor: String? = null
) {
    /** The style with each out-of-range value replaced by its default. */
    fun sanitized(): CaptionStyle {
        val d = CaptionStyle()
        return copy(
            size = size.takeIf { it in SIZES } ?: d.size,
            opacity = opacity.takeIf { it in 40..100 } ?: d.opacity,
            lines = lines.takeIf { it in LINES } ?: d.lines,
            position = position.takeIf { it in POSITIONS } ?: d.position,
            autoHideSeconds = autoHideSeconds.takeIf { it in AUTO_HIDE } ?: 0,
            meColor = meColor?.takeIf(::isHexColour),
            clientColor = clientColor?.takeIf(::isHexColour)
        )
    }

    /** Caption text size in sp. */
    val textSp: Float get() = when (size) {
        "small" -> 13f
        "large" -> 19f
        else -> 15f
    }

    companion object {
        val SIZES = listOf("small", "medium", "large")
        val LINES = listOf(2, 3, 5)
        val POSITIONS = listOf("top", "bottom")
        val AUTO_HIDE = listOf(0, 5, 10, 30)

        fun isHexColour(value: String): Boolean = Regex("#[0-9a-fA-F]{6}").matches(value)
    }
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

    private fun Preferences.toSettings(): AppSettings {
        val raw = this[Keys.SETTINGS]
        val parsed = raw?.let { runCatching { ApiJson.decodeFromString(AppSettings.serializer(), it) }.getOrNull() } ?: return AppSettings()
        // Settings written before the wizard existed have no such key: a phone that is already paired skips it.
        return if (!raw.contains("\"onboardingDone\"") && parsed.paired) parsed.copy(onboardingDone = true) else parsed
    }

    private companion object {
        val TOKEN_CONTEXT = "echoledger.device_token".toByteArray()
    }
}
