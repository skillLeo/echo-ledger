package com.echoledger.mobile.testsupport

import android.os.Looper
import androidx.test.core.app.ApplicationProvider
import com.echoledger.mobile.AppGraph
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.data.RecordingCipher
import com.echoledger.mobile.data.RecordingStore
import com.echoledger.mobile.data.SecretCipher
import java.io.File
import java.io.InputStream
import java.io.OutputStream
import java.nio.channels.SeekableByteChannel
import java.nio.file.Files
import java.nio.file.StandardCopyOption
import java.security.SecureRandom
import java.util.concurrent.CopyOnWriteArrayList
import javax.crypto.Cipher
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.SecretKeySpec
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.emitAll
import kotlinx.coroutines.flow.flow
import mockwebserver3.Dispatcher
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import mockwebserver3.RecordedRequest
import org.mockito.Mockito
import org.mockito.kotlin.any
import org.mockito.kotlin.doAnswer
import org.mockito.kotlin.doReturn
import org.mockito.kotlin.whenever
import org.robolectric.Shadows.shadowOf

/**
 * AES-256-GCM stand-in for the Keystore-backed Tink cipher: authenticated, and bound to the associated context.
 * [onEncrypt] lets a test observe (or prepare for) each encryption.
 */
class AesGcmSecretCipher(private val key: SecretKey = randomKey(), private val onEncrypt: (context: ByteArray) -> Unit = {}) :
    SecretCipher {
    override fun encrypt(plain: ByteArray, context: ByteArray): ByteArray {
        onEncrypt(context)
        val iv = ByteArray(IV_BYTES).also { SecureRandom().nextBytes(it) }
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key, GCMParameterSpec(128, iv))
        cipher.updateAAD(context)
        return iv + cipher.doFinal(plain)
    }

    override fun decrypt(cipher: ByteArray, context: ByteArray): ByteArray {
        require(cipher.size > IV_BYTES) { "ciphertext too short" }
        val engine = Cipher.getInstance("AES/GCM/NoPadding")
        engine.init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(128, cipher.copyOfRange(0, IV_BYTES)))
        engine.updateAAD(context)
        return engine.doFinal(cipher, IV_BYTES, cipher.size - IV_BYTES)
    }

    private companion object {
        const val IV_BYTES = 12

        fun randomKey(): SecretKey = SecretKeySpec(ByteArray(32).also { SecureRandom().nextBytes(it) }, "AES")
    }
}

/** Streams pass through unchanged, so a stored recording can be inspected and sliced byte for byte. */
class PassthroughRecordingCipher : RecordingCipher {
    override fun encrypting(out: OutputStream, recordingId: String): OutputStream = out

    override fun decrypting(input: InputStream, recordingId: String): InputStream = input

    override fun seekableDecrypting(channel: SeekableByteChannel, recordingId: String): SeekableByteChannel = channel
}

/**
 * The stores persist through a temporary file that is renamed over the real one. Android (Linux) replaces the target;
 * `File.renameTo` on a Windows development machine refuses to. These helpers give the stores Linux semantics so the
 * logic above them can be verified on any host. They change nothing about what is written, only how it is moved.
 */
private const val LIBRARY_CONTEXT = "echoledger.library"

private fun EchoLedgerApp.deleteLibraryCacheBeforeRewrite(): (ByteArray) -> Unit = { context ->
    if (String(context) == LIBRARY_CONTEXT) File(noBackupFilesDir, "library.bin").delete()
}

private fun EchoLedgerApp.replacingRecordingStore(real: RecordingStore): RecordingStore {
    val dir = File(noBackupFilesDir, "recordings")
    val store = Mockito.spy(real)
    doAnswer { invocation ->
        val meta = invocation.getArgument<LocalRecording>(0)
        val tmp = File(dir, "${meta.id}.json.tmp")
        tmp.writeText(ApiJson.encodeToString(LocalRecording.serializer(), meta))
        moveOverExisting(tmp, File(dir, "${meta.id}.json"))
        Unit
    }.whenever(store).save(any())
    return store
}

/** Replaces [target] with [source]; on Windows a concurrent reader can make the move fail briefly, so it is retried. */
private fun moveOverExisting(source: File, target: File) {
    var attempt = 0
    while (true) {
        try {
            Files.move(source.toPath(), target.toPath(), StandardCopyOption.REPLACE_EXISTING)
            return
        } catch (e: java.io.IOException) {
            if (++attempt >= 200) throw e
            Thread.sleep(10)
        }
    }
}

/**
 * Reads a recording's metadata the way the app does but without the exclusive file handle `java.io` uses on Windows, so a
 * polling test cannot block the app from replacing or deleting the file it is waiting for.
 */
fun EchoLedgerApp.peekRecording(id: String): LocalRecording? {
    val file = File(noBackupFilesDir, "recordings/$id.json")
    return runCatching { ApiJson.decodeFromString(LocalRecording.serializer(), Files.readString(file.toPath())) }.getOrNull()
}

/** Replaces the Keystore-backed ciphers of the application graph before any store is created from them. */
fun EchoLedgerApp.useTestCiphers(
    secret: SecretCipher = AesGcmSecretCipher(onEncrypt = deleteLibraryCacheBeforeRewrite()),
    recording: RecordingCipher = PassthroughRecordingCipher()
) {
    graph.replaceLazy("secretCipher", secret)
    graph.replaceLazy("recordingCipher", recording)
    graph.replaceLazy("recordings", replacingRecordingStore(RecordingStore(this, recording)))
}

/**
 * Delivers the settings flow's first value after [delayMs], the way a DataStore that has not been read yet does on a real
 * cold start. Until then screens see the placeholder defaults.
 */
fun EchoLedgerApp.delaySettingsEmission(delayMs: Long) {
    val real = graph.settings
    val slow = Mockito.spy(real)
    doReturn(
        flow {
            delay(delayMs)
            emitAll(real.settings)
        }
    ).whenever(slow).settings
    graph.replaceLazy("settings", slow)
}

private fun AppGraph.replaceLazy(property: String, value: Any) {
    val field = AppGraph::class.java.getDeclaredField("$property\$delegate")
    field.isAccessible = true
    field.set(this, lazyOf(value))
}

fun app(): EchoLedgerApp = ApplicationProvider.getApplicationContext()

/** Runs queued main-thread work until [condition] holds (background work completes on real threads meanwhile). */
fun pumpUntil(what: String = "condition", timeoutMs: Long = 20_000, condition: () -> Boolean) {
    val deadline = System.nanoTime() + timeoutMs * 1_000_000
    while (true) {
        shadowOf(Looper.getMainLooper()).idle()
        if (condition()) return
        if (System.nanoTime() > deadline) throw AssertionError("Timed out after ${timeoutMs}ms waiting for $what\n${busyThreads()}")
        Thread.sleep(5)
    }
}

/**
 * Waits until no other thread is running app or DataStore code, so work a test left running (a sync, a token read, a
 * settings write) cannot reach the next test's stores. Main-thread work is processed meanwhile. Gives up quietly after
 * [timeoutMs]; the caller then releases the app anyway.
 */
fun awaitAppIdle(timeoutMs: Long = 5_000) {
    val deadline = System.nanoTime() + timeoutMs * 1_000_000
    val self = Thread.currentThread()
    while (System.nanoTime() < deadline) {
        shadowOf(Looper.getMainLooper()).idle()
        val busy = Thread.getAllStackTraces().any { (thread, frames) -> thread !== self && frames.any(::isAppWork) }
        if (!busy) return
        Thread.sleep(5)
    }
}

/** App and DataStore frames count as work; test classes and this support package (for example a scripted server) do not. */
private fun isAppWork(frame: StackTraceElement): Boolean {
    val name = frame.className
    val testCode = name.startsWith("com.echoledger.mobile.testsupport.") || name.substringBefore('$').endsWith("Test")
    return (name.startsWith("com.echoledger.mobile.") && !testCode) || name.startsWith("androidx.datastore.")
}

/** Names and top frames of the threads that are doing something, to explain a timeout. */
private fun busyThreads(): String = Thread.getAllStackTraces().entries
    .filter { (_, frames) -> frames.any { it.className.startsWith("com.echoledger") || it.className.contains("androidx.datastore") } }
    .joinToString("\n") { (thread, frames) ->
        "  ${thread.name} [${thread.state}]: " +
            frames.take(6).joinToString(" < ") { "${it.className.substringAfterLast('.')}.${it.methodName}" }
    }

/** Clears everything a test can persist through the application graph so tests stay independent. */
suspend fun EchoLedgerApp.resetPersistedState() {
    settingsDataStoreEdit { it.clear() }
    File(noBackupFilesDir, "library.bin").delete()
    File(noBackupFilesDir, "recordings").listFiles()?.forEach { it.deleteRecursively() }
}

/** Pairs the app with [server] without going through the network: server address, device id and an encrypted token. */
suspend fun EchoLedgerApp.pairLocally(server: FakeServer, token: String = "7|eld_testtoken", pins: List<String> = emptyList()) {
    graph.settings.saveToken(token)
    graph.settings.update {
        it.copy(serverUrl = server.baseUrl, deviceId = "01JDEVICE0000000000000000A", deviceName = "Test phone", pins = pins)
    }
    graph.invalidateApi()
}

suspend fun EchoLedgerApp.currentSettings(): AppSettings = graph.settings.current()

fun RecordedRequest.bodyBytes(): ByteArray = body?.toByteArray() ?: ByteArray(0)

fun RecordedRequest.bodyText(): String = body?.utf8().orEmpty()

/**
 * A scripted EchoLedger server. Routes are matched by HTTP method and a regular expression over the request path;
 * every request is recorded in arrival order. Unmatched requests get a 404 JSON error like the real API.
 */
class FakeServer : AutoCloseable {
    private val server = MockWebServer()
    private val routes = CopyOnWriteArrayList<Route>()
    val requests = CopyOnWriteArrayList<RecordedRequest>()

    private class Route(val method: String, val path: Regex, val handler: (RecordedRequest) -> MockResponse)

    val baseUrl: String get() = "http://localhost:${server.port}"

    init {
        server.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse {
                requests += request
                val path = request.url.encodedPath
                val route = routes.lastOrNull { (it.method == ANY || it.method == request.method) && it.path.matches(path) }
                return route?.handler?.invoke(request) ?: json(404, """{"message":"No query results for model."}""")
            }
        }
        server.start()
    }

    fun on(method: String, path: String, handler: (RecordedRequest) -> MockResponse) {
        routes += Route(method, Regex(path), handler)
    }

    fun on(method: String, path: String, response: MockResponse) = on(method, path) { response }

    /** Answers every request, whatever the method and path, until a more specific route is added. */
    fun onAny(response: MockResponse) = on(ANY, ".*") { response }

    fun requestsTo(method: String, path: String): List<RecordedRequest> = requests.filter {
        it.method == method &&
            Regex(path).matches(it.url.encodedPath)
    }

    override fun close() = server.close()

    companion object {
        const val ANY = "*"

        fun json(code: Int, body: String): MockResponse =
            MockResponse.Builder().code(code).addHeader("Content-Type", "application/json").body(body).build()

        fun empty(code: Int): MockResponse = MockResponse.Builder().code(code).build()
    }
}
