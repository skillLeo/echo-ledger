package com.echoledger.mobile.ui.v2

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.TranscriptSummary
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.SessionScreen
import com.echoledger.mobile.ui.v2.components.EchoToastHost
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.V2RecordingScreen
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * The recording page, classic | v2 side by side (parity plan 1 and 8): the page, the ⋮ menu, Export, Copy and each tab,
 * light and dark, English and Urdu. Classic has no Urdu screen, so its half is English in the Urdu pictures too. Each
 * picture is checked to have drawn both halves; the Roborazzi record task also writes them to
 * docs/v2/gate3/phone/recording-parity.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w393dp-h852dp-hdpi")
class RecordingParityShotsTest : ScreenTest() {
    private val outDir =
        File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone/recording-parity")
    private val record = System.getProperty("roborazzi.test.record") == "true"

    private var classic by mutableStateOf(true)
    private var dark by mutableStateOf(false)
    private var language by mutableStateOf(EchoLanguage.English)

    @Before
    fun pair() {
        runBlocking { app.pairLocally(server) }
        server.on(
            "GET",
            "/api/transcripts/.+",
            FakeServer.json(
                200,
                ServerFixtures.transcript().trimEnd().removeSuffix("}") +
                    ""","views":{"available":["roman","english"],"status":"ready","default":"original"}}"""
            )
        )
        server.on(
            "GET",
            "/api/sessions/${ServerFixtures.SESSION_ID}",
            FakeServer.json(
                200,
                ServerFixtures.sessionDetail(playbackUrl = null).trimEnd().removeSuffix("}") +
                    ""","markers":[{"id":"m1","at_ms":12400,"tag":"Price"}]}"""
            )
        )
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
    }

    private val item = LibraryItem(
        id = ServerFixtures.SESSION_ID,
        sourceDevice = "phone",
        sourceDeviceLabel = "Pixel 9",
        sourceApp = "whatsapp",
        title = "Call with Acme",
        client = NamedRef("01JCLIENT0000000000000000A", "Acme Holdings"),
        startedAt = "2026-10-20T10:00:00+00:00",
        durationMs = 2_530_000,
        status = "completed",
        transcript = TranscriptSummary(finalStatus = "completed", finalTranscriptId = ServerFixtures.TRANSCRIPT_ID, finalLanguage = "en"),
        updatedAt = "2026-10-20T10:05:00+00:00",
        labels = listOf("pricing"),
        markerCount = 1
    )

    private fun page() = show {
        if (classic) {
            EchoLedgerTheme(themeMode = if (dark) "dark" else "light") {
                Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) { SessionScreen(vm, item, onBack = {}) }
            }
        } else {
            EchoV2Theme(darkTheme = dark, language = language) {
                val toasts = rememberEchoToastState()
                CompositionLocalProvider(LocalEchoToasts provides toasts) {
                    Box { V2RecordingScreen(vm, item, onBack = {}) }
                    EchoToastHost(toasts)
                }
            }
        }
    }

    private fun press(words: String) {
        val node = compose.onAllNodes((hasText(words) or hasContentDescription(words)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    private fun awaitText(words: String) = pumpUntil("\"$words\"", 20_000) {
        compose.onAllNodesWithText(words, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    /** Every window on screen (the page, and a menu, sheet or dialog over it), as Android draws them. */
    private fun screen(name: String): Bitmap? {
        compose.waitForIdle()
        if (!record) return null
        val file = File(tmp, "$name.png")
        com.github.takahirom.roborazzi.captureScreenRoboImage(file)
        return android.graphics.BitmapFactory.decodeFile(file.absolutePath)
    }

    private val tmp = File("build/recording-parity-tmp")

    /** Classic | v2 on one picture, each half on its own background; both must have drawn. */
    private fun sideBySide(name: String, left: Bitmap?, right: Bitmap?) {
        if (left == null || right == null) return
        assertTrue("$name: classic drew nothing", distinct(left) > 2)
        assertTrue("$name: v2 drew nothing", distinct(right) > 2)
        val gap = 24
        val out = Bitmap.createBitmap(left.width + gap + right.width, maxOf(left.height, right.height), Bitmap.Config.ARGB_8888)
        Canvas(out).apply {
            drawColor(if (dark) Color.rgb(40, 40, 40) else Color.rgb(220, 220, 220))
            drawBitmap(left, 0f, 0f, Paint())
            drawBitmap(right, (left.width + gap).toFloat(), 0f, Paint())
        }
        if (!record) return
        outDir.mkdirs()
        val file = File(outDir, "$name-${if (dark) "dark" else "light"}-${if (language == EchoLanguage.Urdu) "ur" else "en"}.png")
        file.outputStream().use { out.compress(Bitmap.CompressFormat.PNG, 100, it) }
    }

    private fun distinct(bitmap: Bitmap): Int {
        val seen = HashSet<Int>()
        for (y in 0 until bitmap.height step 16) for (x in 0 until bitmap.width step 16) seen += bitmap.getPixel(x, y)
        return seen.size
    }

    /** One view: classic after [classicSteps], v2 after [v2Steps]. */
    private var shown = false

    private fun view(name: String, classicSteps: () -> Unit, v2Steps: () -> Unit) {
        classic = true
        if (!shown) {
            page()
            shown = true
        }
        awaitText("Hi, thanks for calling.")
        classicSteps()
        val left = screen("classic")
        classic = false
        compose.waitForIdle()
        awaitText("Hi, thanks for calling.")
        v2Steps()
        sideBySide(name, left, screen("v2"))
    }

    private fun everyVariant(name: String, classicSteps: () -> Unit, v2Steps: () -> Unit) {
        for (l in EchoLanguage.entries) {
            for (d in listOf(false, true)) {
                language = l
                dark = d
                view(name, classicSteps, v2Steps)
            }
        }
    }

    private val urdu get() = language == EchoLanguage.Urdu

    @Test
    fun thePage() = everyVariant("page", {}, {})

    @Test
    fun theMoreMenu() = everyVariant("more-menu", { press("More") }, { press(if (urdu) "مزید" else "More") })

    @Test
    fun theExportMenu() = everyVariant("export-menu", { press("Export") }, {
        press(if (urdu) "مزید" else "More")
        press(if (urdu) "ایکسپورٹ" else "Export")
    })

    @Test
    fun theCopyMenu() = everyVariant("copy-menu", {}, { press(if (urdu) "کاپی کریں" else "Copy") })

    @Test
    fun theTabs() {
        for ((name, en, ur) in listOf(
            Triple("summary", "Summary", "خلاصہ"),
            Triple("moments", "Moments", "لمحات"),
            Triple("details", "Details", "تفصیلات")
        )) {
            everyVariant("tab-$name", {}, { press(if (urdu) ur else en) })
        }
    }
}
