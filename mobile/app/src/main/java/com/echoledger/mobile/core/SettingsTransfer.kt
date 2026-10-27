package com.echoledger.mobile.core

import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.dictation.DictationText
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put

/**
 * Settings as a portable JSON file (B19), same envelope as the desktop app. Only the preferences listed in [PORTABLE]
 * travel: never the device token or the pairing (server, pins, device), the lock (a lock is switched on by unlocking
 * once), consent (given in person) or anything the server decides.
 */
object SettingsTransfer {
    const val FORMAT = "echoledger-settings"
    const val VERSION = 1

    /** The largest file we are willing to read. */
    const val MAX_BYTES = 256 * 1024

    val PORTABLE: Set<String> = setOf(
        "autoRecordArmed",
        "captionsEnabled",
        "micSource",
        "decisionTimeout",
        "decisionTimeoutChosen",
        "autoRecordApps",
        "captionStyle",
        "farSideWarning",
        "inputDevice",
        "transcriptionLanguage",
        "dictationMode",
        "dictationCleanup",
        "dictationSounds",
        "dictationReplacements",
        "dictationSnippets",
        "activityNotifications",
        "quietHours",
        "quietFrom",
        "quietTo"
    )

    /**
     * Kept on this phone: the pairing and everything tied to this server, the lock, consent, the wizard flag and the
     * look (theme and wallpaper colours follow this phone, like the desktop's theme).
     */
    val NOT_PORTABLE: Set<String> = setOf(
        "serverUrl",
        "pins",
        "deviceId",
        "deviceName",
        "clientId",
        "serverApiVersion",
        "markerTags",
        "consentAcknowledged",
        "appLockEnabled",
        "lockAfterMs",
        "secureScreen",
        "settingsVersion",
        "onboardingDone",
        "themeMode",
        "dynamicColour"
    )

    sealed interface Result {
        data class Applied(val settings: AppSettings, val applied: Int) : Result

        data class Rejected(val reason: String) : Result
    }

    const val NOT_A_SETTINGS_FILE = "That is not an EchoLedger settings file."

    fun export(settings: AppSettings): String {
        val all = ApiJson.encodeToJsonElement(AppSettings.serializer(), settings).jsonObject
        val portable = JsonObject(all.filterKeys { it in PORTABLE })
        val doc = buildJsonObject {
            put("format", FORMAT)
            put("version", VERSION)
            put("platform", "android")
            put("settings", portable)
        }
        return PRETTY.encodeToString(JsonObject.serializer(), doc) + "\n"
    }

    /** Applies the portable values of [text] on top of [current]; values this build does not understand are skipped one by one. */
    fun import(text: String, current: AppSettings): Result {
        if (text.length > MAX_BYTES) return Result.Rejected(NOT_A_SETTINGS_FILE)
        val doc = runCatching { ApiJson.parseToJsonElement(text).jsonObject }.getOrNull() ?: return Result.Rejected(NOT_A_SETTINGS_FILE)
        if ((doc["format"] as? JsonPrimitive)?.contentOrNull != FORMAT) return Result.Rejected(NOT_A_SETTINGS_FILE)
        val incoming = doc["settings"] as? JsonObject ?: return Result.Rejected("The file has no settings.")
        var merged: JsonObject = ApiJson.encodeToJsonElement(AppSettings.serializer(), current).jsonObject
        var applied = 0
        for ((key, value) in incoming) {
            if (key !in PORTABLE) continue
            val probe = JsonObject(merged + (key to value))
            if (decode(probe) != null) {
                merged = probe
                applied++
            }
        }
        val settings = decode(merged) ?: return Result.Rejected(NOT_A_SETTINGS_FILE)
        val clean = settings.copy(
            captionStyle = settings.captionStyle.sanitized(),
            dictationReplacements = DictationText.cleanReplacements(settings.dictationReplacements),
            dictationSnippets = DictationText.cleanSnippets(settings.dictationSnippets),
            quietFrom = settings.quietFrom.takeIf { Activity.parseHhmm(it) != null } ?: current.quietFrom,
            quietTo = settings.quietTo.takeIf { Activity.parseHhmm(it) != null } ?: current.quietTo
        )
        return Result.Applied(clean, applied)
    }

    private fun decode(obj: JsonObject): AppSettings? =
        runCatching { ApiJson.decodeFromJsonElement(AppSettings.serializer(), obj) }.getOrNull()

    private val PRETTY = Json(ApiJson) { prettyPrint = true }
}
