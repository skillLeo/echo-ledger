package com.echoledger.mobile.update

import android.content.Context
import com.echoledger.mobile.data.AndroidReleaseDto
import com.echoledger.mobile.data.ApiJson
import java.io.File
import java.security.MessageDigest
import java.util.Locale

/** Where the app's own update stands (since v1.5; only the website build ever leaves [Idle]). */
sealed interface UpdateStatus {
    data object Idle : UpdateStatus

    data object Checking : UpdateStatus

    data object UpToDate : UpdateStatus

    data class Available(val release: AndroidReleaseDto) : UpdateStatus

    data class Downloading(val release: AndroidReleaseDto, val percent: Int) : UpdateStatus

    /** Android must first allow EchoLedger to install apps ("Install unknown apps"); then Update again. */
    data class NeedsPermission(val release: AndroidReleaseDto) : UpdateStatus

    data class Installing(val release: AndroidReleaseDto) : UpdateStatus

    data class Failed(val release: AndroidReleaseDto?, val message: String) : UpdateStatus
}

/** The rules of the website build's updates (pure, tested in both builds). */
object AppUpdates {
    const val CHECK_EVERY_MS = 12 * 60 * 60 * 1000L
    private val HEX64 = Regex("^[0-9a-f]{64}$")

    /** Time to look again: never looked, 12 hours passed, or the clock went back. */
    fun due(lastCheckMs: Long, nowMs: Long): Boolean = lastCheckMs <= 0 || nowMs < lastCheckMs || nowMs - lastCheckMs >= CHECK_EVERY_MS

    /** The release worth offering to this app ([currentCode] on Android [sdk]): newer, installable here, well formed. */
    fun offer(release: AndroidReleaseDto?, currentCode: Int, sdk: Int): AndroidReleaseDto? = release?.takeIf {
        it.code > currentCode && (it.minSdk ?: 0) <= sdk && HEX64.matches(it.sha256.lowercase()) && it.bytes > 0
    }

    fun sha256(file: File): String {
        val digest = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { input ->
            val buffer = ByteArray(64 * 1024)
            while (true) {
                val read = input.read(buffer)
                if (read < 0) break
                digest.update(buffer, 0, read)
            }
        }
        return digest.digest().joinToString("") { "%02x".format(it) }
    }

    /** The downloaded file is exactly the published one (size first: no hashing of a cut download). */
    fun matches(file: File, release: AndroidReleaseDto): Boolean =
        file.isFile && file.length() == release.bytes && sha256(file) == release.sha256.lowercase()

    fun fileName(release: AndroidReleaseDto): String = "EchoLedger-${release.code}.apk"

    /** Downloads kept in the cache folder that this app no longer needs (its own version or older). */
    fun stale(files: List<File>, currentCode: Int): List<File> = files.filter { file ->
        val code = Regex("""^EchoLedger-(\d+)\.apk(\.part)?$""").find(file.name)?.groupValues?.get(1)?.toIntOrNull()
        code == null || code <= currentCode
    }

    fun percent(done: Long, total: Long): Int = if (total <= 0) 0 else ((done * 100) / total).toInt().coerceIn(0, 100)

    /** In words for the banner and About: "EchoLedger 1.5.1 is ready" and the size. */
    fun headline(release: AndroidReleaseDto): String = "EchoLedger ${release.version} is ready to install"

    fun size(bytes: Long): String = if (bytes >=
        1_048_576
    ) {
        String.format(Locale.US, "%.1f MB", bytes / 1_048_576.0)
    } else {
        "${(bytes / 1024).coerceAtLeast(1)} KB"
    }
}

/** What the phone remembers about updates, on this phone only (never exported with the settings). */
class UpdatePrefs(context: Context) {
    private val prefs = context.getSharedPreferences("updates", Context.MODE_PRIVATE)

    var lastCheckMs: Long
        get() = prefs.getLong("last_check", 0)
        set(value) = prefs.edit().putLong("last_check", value).apply()

    /** The banner of this version was closed (About still offers it). */
    var hiddenCode: Int
        get() = prefs.getInt("hidden_code", 0)
        set(value) = prefs.edit().putInt("hidden_code", value).apply()

    /** The last release offered, so the banner is back after a restart without asking the server again. */
    var offered: AndroidReleaseDto?
        get() = prefs.getString("offered", null)?.let {
            runCatching { ApiJson.decodeFromString(AndroidReleaseDto.serializer(), it) }.getOrNull()
        }
        set(value) {
            prefs.edit().apply {
                if (value ==
                    null
                ) {
                    remove("offered")
                } else {
                    putString("offered", ApiJson.encodeToString(AndroidReleaseDto.serializer(), value))
                }
            }.apply()
        }
}
