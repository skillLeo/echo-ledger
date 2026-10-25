package com.echoledger.mobile.e2e.support

import androidx.test.platform.app.InstrumentationRegistry
import java.net.HttpURLConnection
import java.net.URL
import org.json.JSONArray
import org.json.JSONObject

/**
 * Client for the test backend: a disposable EchoLedger server with a stand-in speech provider, plus a small control
 * endpoint next to it. The emulator reaches both on the host through 10.0.2.2. Addresses can be overridden with the
 * instrumentation arguments `backend.control`, `backend.server` and `backend.flaky`.
 *
 * `flaky` is the same server behind a proxy whose failure mode (dropped connections, rejected requests, slow chunks)
 * can be switched from the tests, so upload recovery can be exercised without stopping the real server.
 */
object Backend {
    private val args = InstrumentationRegistry.getArguments()
    val controlUrl: String = args.getString("backend.control") ?: "http://10.0.2.2:8115"
    val serverUrl: String = args.getString("backend.server") ?: "http://10.0.2.2:8113"
    val flakyServerUrl: String = args.getString("backend.flaky") ?: "http://10.0.2.2:8114"

    data class PairingCode(val display: String, val code: String, val url: String, val payload: String)

    data class Device(val token: String, val id: String, val name: String, val baseUrl: String, val pins: List<String>)

    data class Stats(val chunkPosts: Int, val createPosts: Int)

    data class ServerSession(
        val id: String,
        val clientRef: String,
        val sourceApp: String,
        val status: String,
        val title: String?,
        val finalStatus: String?,
        val deleted: Boolean
    )

    /** Calls the control endpoint; a phone that has just regained its network needs a moment before the host is reachable. */
    private fun call(path: String, timeoutMs: Int = 180_000): String {
        var failure: java.io.IOException? = null
        repeat(20) {
            try {
                return callOnce(path, timeoutMs)
            } catch (e: java.io.IOException) {
                failure = e
                Thread.sleep(1_500)
            }
        }
        throw failure!!
    }

    private fun callOnce(path: String, timeoutMs: Int): String {
        val connection = URL("$controlUrl$path").openConnection() as HttpURLConnection
        connection.connectTimeout = 10_000
        connection.readTimeout = timeoutMs
        try {
            val body = (if (connection.responseCode < 400) connection.inputStream else connection.errorStream).bufferedReader().readText()
            check(connection.responseCode < 400) { "backend $path -> ${connection.responseCode}: $body" }
            return body
        } finally {
            connection.disconnect()
        }
    }

    private fun obj(path: String, timeoutMs: Int = 180_000) = JSONObject(call(path, timeoutMs))

    private fun base(viaFlaky: Boolean) = if (viaFlaky) "&via=proxy" else ""

    /** One unused pairing code, as the admin console would show it. */
    fun pairingCode(viaFlaky: Boolean = false): PairingCode = obj("/pairing-code?x=1${base(viaFlaky)}").let {
        PairingCode(it.getString("display"), it.getString("code"), it.getString("url"), it.getString("payload"))
    }

    /** A phone that is already registered on the server (what a finished pairing leaves behind). */
    fun registerDevice(viaFlaky: Boolean = false, name: String = "Registered phone"): Device =
        obj("/mint?name=${java.net.URLEncoder.encode(name, "UTF-8")}${base(viaFlaky)}").let { json ->
            val pins = json.optJSONArray("pins") ?: JSONArray()
            Device(
                token = json.getString("token"),
                id = json.getString("deviceId"),
                name = json.getString("deviceName"),
                baseUrl = json.getString("baseUrl"),
                pins = List(pins.length()) { pins.getString(it) }
            )
        }

    /** Blocks until the server's pairing rate limit (5 per minute) has room for another attempt. */
    fun awaitPairingBudget() {
        call("/budget", 120_000)
    }

    /** Tells the backend that the app registered directly (not through the flaky proxy), for the rate-limit bookkeeping. */
    fun notePairingAttempt() {
        call("/note-register")
    }

    /** Failure mode of the flaky server: `ok`, `down`, `reject`, `slow:<ms>`, `drop-after:<n>` or `status:<code>`. */
    fun setFlakyMode(spec: String) {
        call("/mode?set=$spec")
    }

    fun resetStats() {
        call("/stats?reset=1")
    }

    fun stats(): Stats = obj("/stats").let { Stats(it.getInt("chunkPosts"), it.getInt("createPosts")) }

    fun deviceNames(): List<String> = JSONArray(call("/devices")).let { rows ->
        List(rows.length()) { rows.getJSONObject(it).getString("name") }
    }

    fun counts(): JSONObject = obj("/counts")

    fun sessions(): List<ServerSession> = JSONArray(call("/sessions")).let { rows ->
        List(rows.length()) {
            val row = rows.getJSONObject(it)
            ServerSession(
                id = row.getString("id"),
                clientRef = row.getString("client_ref"),
                sourceApp = row.getString("source_app"),
                status = row.getString("status"),
                title = row.optString("title").takeIf { t -> t.isNotEmpty() && t != "null" },
                finalStatus = row.optString("final_status").takeIf { s -> s.isNotEmpty() && s != "null" },
                deleted = !row.isNull("deleted_at")
            )
        }
    }

    /** Sessions that still exist on the server (not tombstoned). */
    fun liveSessions(): List<ServerSession> = sessions().filterNot { it.deleted }

    /** Server-side "unpair": removes every device token. */
    fun revokeAllDevices() {
        call("/revoke")
    }

    /** Removes every session so a test can start from an empty library. */
    fun wipeSessions() {
        call("/wipe")
    }

    /** One finished session with a 40 second mono recording (a transcript with a single speaker); returns its title. */
    fun seedLongRecording(app: String = "other"): String {
        val rows = obj("/seed?count=1&app=$app&platform=windows&audio=long", 300_000).getJSONArray("sessions")
        check(rows.length() == 1) { "seeded ${rows.length()} sessions" }
        return "Seeded $app 1"
    }

    /** Removes the sessions with exactly this title (test data only). */
    fun dropSessionsTitled(title: String) {
        call("/drop?title=${java.net.URLEncoder.encode(title, "UTF-8")}")
    }

    /** Finished, transcribed stereo sessions owned by a desktop. Returns their titles ("Seeded <app> <n>"). */
    fun seedDesktopSessions(count: Int, app: String = "zoom", providerMode: String? = null): List<String> {
        val mode = providerMode?.let { "&mode=$it" }.orEmpty()
        val rows = obj("/seed?count=$count&app=$app&platform=windows$mode", 300_000).getJSONArray("sessions")
        check(rows.length() == count) { "seeded ${rows.length()} of $count sessions" }
        return List(count) { "Seeded $app ${it + 1}" }
    }
}
