package com.echoledger.mobile.ui.v2

import android.app.Application
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onRoot
import com.echoledger.mobile.data.BinItemDto
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.library.LibraryState
import com.echoledger.mobile.ui.v2.library.TranscriptState
import com.echoledger.mobile.ui.v2.library.V2LibraryContent
import com.echoledger.mobile.ui.v2.library.V2RecordingContent
import com.echoledger.mobile.ui.v2.library.V2TrashContent
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.Profile
import com.echoledger.mobile.ui.v2.shell.ProfileState
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.shell.V2SettingsHomeContent
import com.echoledger.mobile.ui.v2.shell.V2Text
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import java.time.ZonedDateTime
import java.util.Locale
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

/**
 * The screens that run on the app's data (behind `ui_v2.library` and `ui_v2.shell`), drawn from server-shaped items at
 * the S25's size: Library, a recording, Trash and Settings, light and dark, English and Urdu. Recorded to
 * docs/v2/gate3/phone/app/ (`-Proborazzi.test.record=true`).
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-420dpi", application = Application::class)
class V2ScreenShotsTest {
    @get:Rule
    val compose = createComposeRule()

    private val outDir =
        File(File(System.getProperty("screenshots.dir") ?: "build/screenshots").parentFile!!.parentFile, "v2/gate3/phone/app")
    private val now = ZonedDateTime.parse("2026-11-17T12:00:00+05:00[Asia/Karachi]")

    private var dark by mutableStateOf(false)
    private var language by mutableStateOf(EchoLanguage.English)

    private fun shootAll(name: String, screen: @Composable (urdu: Boolean, text: V2Text) -> Unit) {
        compose.setContent {
            EchoV2Theme(darkTheme = dark, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    screen(language == EchoLanguage.Urdu, v2Text)
                }
            }
        }
        for (lang in EchoLanguage.entries) {
            for (isDark in listOf(false, true)) {
                language = lang
                dark = isDark
                compose.waitForIdle()
                val file = "$name-${if (isDark) "dark" else "light"}-${if (lang == EchoLanguage.Urdu) "ur" else "en"}.png"
                compose.onRoot().captureRoboImage(File(outDir, file))
            }
        }
    }

    private fun locale(urdu: Boolean) = if (urdu) Locale.forLanguageTag("ur-PK") else Locale.UK

    @Test
    fun library() = shootAll("library") { urdu, text ->
        val rows = LibraryModel.rows(V2Fixtures.library(urdu), emptySet(), "", now, text, locale(urdu))
        V2LibraryContent(
            state = LibraryState.Ready(rows, emptyList(), offline = false, anyAtAll = true),
            query = "",
            onQuery = {},
            views = emptySet(),
            onToggleView = {},
            initials = if (urdu) "ع" else "AS",
            onOpen = {},
            onDelete = {},
            onRetryUpload = {},
            onRetry = {},
            onTrash = {},
            onAccount = {}
        )
    }

    @Test
    fun recording() = shootAll("recording") { urdu, _ ->
        val lines = if (urdu) {
            listOf(
                SegmentDto(2, "Client", 4_000, 10_000, "السلام علیکم عائشہ صاحبہ۔ باسمتی کے چالیس کارٹن کا نیا ریٹ بھیج دیں۔"),
                SegmentDto(1, "Me", 11_000, 25_000, "وعلیکم السلام۔ اب ریٹ 4,200 روپے فی کارٹن ہے، جمعرات تک حیدرآباد گودام پہنچ جائے گا۔"),
                SegmentDto(2, "Client", 26_000, 37_000, "ٹھیک ہے۔ ادائیگی 45 دن میں، اور انوائس پر ہمارا PO نمبر ضرور لکھیں۔")
            )
        } else {
            listOf(
                SegmentDto(2, "Client", 4_000, 10_000, "Assalam o alaikum Ayesha. Please send the new rate for forty cartons of basmati."),
                SegmentDto(
                    1,
                    "Me",
                    11_000,
                    25_000,
                    "Walaikum assalam. It is Rs 4,200 a carton now, delivered to your Hyderabad godown by Thursday."
                ),
                SegmentDto(2, "Client", 26_000, 37_000, "Fine. Payment in 45 days, and put our PO number on the invoice this time.")
            )
        }
        V2RecordingContent(
            item = V2Fixtures.library(urdu).first(),
            transcript = TranscriptState.Lines(lines),
            positionMs = 221_000,
            durationMs = 728_000,
            playing = false,
            speed = 1f,
            onPlayPause = {},
            onSeek = {},
            onSpeed = {},
            onShare = {},
            onDelete = {},
            onRetry = {},
            onBack = {}
        )
    }

    @Test
    fun trash() = shootAll("trash") { urdu, _ ->
        V2TrashContent(
            items = listOf(
                BinItemDto(
                    "01JX",
                    if (urdu) "کراچی کارگو سروسز" else "Karachi Cargo Services",
                    deletedAt = "2026-11-17T05:00:00Z",
                    eraseAt = "2026-11-19T05:00:00Z"
                ),
                BinItemDto(
                    "01JY",
                    if (urdu) "وائس نوٹ" else "Voice note",
                    deletedAt = "2026-11-16T08:30:00Z",
                    eraseAt = "2026-11-18T08:30:00Z"
                )
            ),
            keepHours = 48,
            onBack = {},
            onRestore = {},
            onErase = {}
        )
    }

    @Test
    fun settings() = shootAll("settings") { urdu, _ ->
        V2SettingsHomeContent(
            state = ProfileState.Ready(
                Profile(
                    name = if (urdu) "عائشہ صدیقی" else "Ayesha Siddiqui",
                    email = "ayesha@bilaltraders.pk",
                    plan = "Starter",
                    usedMinutes = 212,
                    monthlyMinutes = 300,
                    storageBytes = 1_240_000_000,
                    recordings = 318
                )
            ),
            flags = UiFlags(mapOf("ui_v2.shell" to true, "ui_v2.library" to true)),
            onSwitchToClassic = {},
            onUpgrade = {},
            onAllSettings = {},
            onRetry = {},
            onBack = {}
        )
    }
}
