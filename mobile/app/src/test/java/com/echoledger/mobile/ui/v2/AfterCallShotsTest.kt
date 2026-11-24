package com.echoledger.mobile.ui.v2

import android.app.Notification
import android.app.NotificationManager
import android.content.Context
import android.content.res.Configuration
import android.widget.FrameLayout
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.onRoot
import androidx.compose.ui.unit.Density
import androidx.compose.ui.viewinterop.AndroidView
import com.echoledger.mobile.calls.CallChoice
import com.echoledger.mobile.calls.PostCallPrompt
import com.echoledger.mobile.notify.AfterCallNotice
import com.echoledger.mobile.overlay.AfterCallBarContent
import com.echoledger.mobile.overlay.DecisionPopup
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.installWhatsAppStandIn
import com.echoledger.mobile.testsupport.peekRecording
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.storeRecording
import com.echoledger.mobile.ui.v2.live.CallCardScreen
import com.echoledger.mobile.ui.v2.live.CallChoiceCard
import com.echoledger.mobile.ui.v2.live.callCardTitle
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import com.github.takahirom.roborazzi.captureRoboImage
import java.io.File
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import org.robolectric.shadows.ShadowSettings

/**
 * v2.1 UX-13 on the narrowest phone (360 dp), light and dark, English and Urdu, at the phone's text size and the
 * largest: before, the big after-call card over the app (before-card-*.png); after, the after-call notification as
 * Android draws it, expanded with the first-time hint (after-notice-*), back-to-back calls in one (after-grouped-*),
 * and the small bar the person can turn on (after-bar-*), with the call's app's own icon. Written to
 * docs/design/screens/phone/after-call when
 * recording, otherwise only drawn.
 */
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w360dp-h780dp-xhdpi")
class AfterCallShotsTest : ScreenTest() {
    private val out = File(File(System.getProperty("screenshots.dir") ?: "build/screenshots"), "phone/after-call")
    private val record = System.getProperty("roborazzi.test.record") == "true"

    private var scale by mutableFloatStateOf(1f)
    private var dark by mutableStateOf(false)
    private var language by mutableStateOf(EchoLanguage.English)
    private var round by mutableIntStateOf(0)
    private var part by mutableStateOf<(@androidx.compose.runtime.Composable () -> Unit)?>(null)

    private val variants = listOf(
        Triple(false, EchoLanguage.English, 1f),
        Triple(true, EchoLanguage.English, 1f),
        Triple(false, EchoLanguage.Urdu, 1f),
        Triple(true, EchoLanguage.Urdu, 1f),
        Triple(false, EchoLanguage.English, 2f),
        Triple(false, EchoLanguage.Urdu, 2f)
    )
    private val all = listOf("light-en", "dark-en", "light-ur", "dark-ur", "light-en-200", "light-ur-200")

    /** Android draws its notifications in the phone's own mode: the dark ones need the phone in dark mode (night). */
    private val lightOnes = variants.filterNot { it.first }
    private val darkOnes = variants.filter { it.first }

    @Before
    fun allow() {
        ShadowSettings.setCanDrawOverlays(true)
        shadowOf(app).grantPermissions(android.Manifest.permission.POST_NOTIFICATIONS)
        com.echoledger.mobile.notify.Notifications.createChannels(app)
        // The call's app on the phone, so the card, the bar and the notification show its own icon (DES-07).
        app.installWhatsAppStandIn()
    }

    @After
    fun close() {
        DecisionPopup.resetForTest()
        AfterCallNotice.resetForTest(app)
    }

    /** Draws [part] in every variant, on the app's canvas colour; saves `<name>-<variant>.png`. */

    /** The part drawn shows the app's own logo (the card, the bar): its icon is waited for. */
    private var logo = false

    private fun shoot(
        name: String,
        only: List<Triple<Boolean, EchoLanguage, Float>> = variants,
        before: (dark: Boolean, urdu: Boolean, scale: Float) -> Unit
    ): List<String> {
        show {
            val base = LocalContext.current
            val config = LocalConfiguration.current
            val scaled = remember(scale) { base.createConfigurationContext(Configuration(config).apply { fontScale = scale }) }
            CompositionLocalProvider(LocalDensity provides Density(scaled), LocalConfiguration provides scaled.resources.configuration) {
                key(round) {
                    EchoV2Theme(darkTheme = dark, language = language) {
                        Box(Modifier.fillMaxWidth().background(EchoV2.colors.bgCanvas).padding(vertical = EchoTokens.Space.s16)) {
                            part?.invoke()
                        }
                    }
                }
            }
        }
        val drawn = mutableListOf<String>()
        for ((d, l, s) in only) {
            dark = d
            language = l
            scale = s
            before(d, l == EchoLanguage.Urdu, s)
            round++
            compose.waitForIdle()
            // The app's icon on the card and the bar is loaded off the main thread: in the picture before it is taken.
            if (logo) {
                val px = with(compose.density) { EchoTokens.Component.avatarSize.roundToPx() }
                compose.waitUntil(5_000) { com.echoledger.mobile.ui.design.AppIcons.cached("v2-source:WHATSAPP:$px") != null }
                compose.waitForIdle()
            }
            val variant = "${if (d) "dark" else "light"}-${if (l == EchoLanguage.Urdu) "ur" else "en"}${if (s > 1f) "-200" else ""}"
            if (record) compose.onRoot().captureRoboImage(File(out, "$name-$variant.png").path)
            drawn += variant
        }
        return drawn
    }

    private fun language(urdu: Boolean) = runBlocking { app.graph.settings.update { it.copy(appLanguage = if (urdu) "ur" else "en") } }

    /** The notification [id] as Android draws it expanded, in the phone's look ([dark], [fontScale]). */
    private fun drawn(id: Int, fontScale: Float): @androidx.compose.runtime.Composable () -> Unit {
        val n: Notification = shadowOf(app.getSystemService(NotificationManager::class.java)).getNotification(id)
        // The phone's look at this text size; its mode is the test's (night for the dark ones).
        val config = Configuration(app.resources.configuration).apply { this.fontScale = fontScale }
        val look: Context = android.view.ContextThemeWrapper(
            app.createConfigurationContext(config),
            android.R.style.Theme_DeviceDefault_DayNight
        )
        // Without the time: Robolectric's clock is not the phone's ("in 57y").
        // A copy built in that context (the posted one stays as it is), not in the app's own one.
        val copy = n.clone().apply { extras.remove("android.appInfo") }
        val builder = Notification.Builder.recoverBuilder(look, copy).setShowWhen(false)
        val views = builder.createBigContentView() ?: builder.createContentView()
        return {
            AndroidView(
                factory = {
                    FrameLayout(look).also { frame ->
                        runCatching { frame.addView(views.apply(look, frame)) }.onFailure {
                            println("DRAWFAIL")
                            it.printStackTrace(System.out)
                        }
                    }
                },
                modifier = Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Space.s8)
            )
        }
    }

    private fun call(id: String, name: String?): com.echoledger.mobile.data.LocalRecording {
        app.storeRecording(id = id, state = "pending_decision", sourceApp = "whatsapp", durationMs = 158_000)
        // A number is the call's number (kept left to right in Urdu), anything else its name.
        name?.let { n -> app.graph.recordings.update(id) { if (n.startsWith("+")) it.copy(callerNumber = n) else it.copy(callerName = n) } }
        return app.peekRecording(id)!!
    }

    private fun notifying(on: Boolean) {
        runBlocking {
            app.graph.settings.update { it.copy(uiFlags = it.uiFlags + (CallChoice.NOTIFY_FLAG to on), callChoiceHintShown = false) }
        }
        pumpUntil("the app sees the flag") { app.graph.liveSettings.value?.uiFlags?.get(CallChoice.NOTIFY_FLAG) == on }
    }

    @Test
    fun beforeTheCard() = assertEquals(
        all,
        shoot("before-card") { _, urdu, _ ->
            logo = true
            language(urdu)
            val rec = call("card", "Ali")
            val title = callCardTitle(app.resources, rec)
            part = {
                CallChoiceCard(
                    screen = CallCardScreen.Ask(if (urdu) "علی" else title, rec.durationMs, rec.sourceApp, hint = true),
                    onTranscribe = {},
                    onDelete = {},
                    onAudio = {},
                    onUndo = {},
                    still = true,
                    modifier = Modifier.padding(horizontal = EchoTokens.Space.s16)
                )
            }
        }
    )

    private fun notice(dark: Boolean, urdu: Boolean, s: Float) {
        notifying(true)
        language(urdu)
        AfterCallNotice.resetForTest(app)
        val rec = call("call-$round", if (urdu) "علی" else "Ali")
        PostCallPrompt.show(app, rec, if (dark) "dark" else "light")
        pumpUntil("asked") { shadowOf(app.getSystemService(NotificationManager::class.java)).getNotification(rec.id.hashCode()) != null }
        part = drawn(rec.id.hashCode(), s)
    }

    private fun grouped(dark: Boolean, urdu: Boolean, s: Float) {
        notifying(true)
        language(urdu)
        AfterCallNotice.resetForTest(app)
        AfterCallNotice.cancelGrouped(app)
        listOf(if (urdu) "علی" else "Ali", "Bilal Traders", "+92 300 1234567").forEachIndexed { i, name ->
            PostCallPrompt.show(app, call("g$round-$i", name), if (dark) "dark" else "light")
        }
        pumpUntil("all three in one") {
            shadowOf(app.getSystemService(NotificationManager::class.java)).getNotification(AfterCallNotice.GROUPED_ID)?.number == 3
        }
        part = drawn(AfterCallNotice.GROUPED_ID, s)
    }

    @Test
    fun afterTheNotification() = assertEquals(all.filterNot { it.startsWith("dark") }, shoot("after-notice", lightOnes, ::notice))

    @Test
    @Config(sdk = [34], qualifiers = "w360dp-h780dp-night-xhdpi")
    fun afterTheNotificationInDarkMode() = assertEquals(all.filter { it.startsWith("dark") }, shoot("after-notice", darkOnes, ::notice))

    @Test
    fun afterBackToBackCalls() = assertEquals(all.filterNot { it.startsWith("dark") }, shoot("after-grouped", lightOnes, ::grouped))

    @Test
    @Config(sdk = [34], qualifiers = "w360dp-h780dp-night-xhdpi")
    fun afterBackToBackCallsInDarkMode() = assertEquals(all.filter { it.startsWith("dark") }, shoot("after-grouped", darkOnes, ::grouped))

    @Test
    fun afterTheSmallBar() = assertEquals(
        all,
        shoot("after-bar") { _, urdu, _ ->
            logo = true
            language(urdu)
            val rec = call("bar", "Ali")
            val shown = if (urdu) rec.copy(callerName = "علی") else rec
            part = { AfterCallBarContent(shown, onTranscribe = {}, onDelete = {}, modifier = Modifier.padding(EchoTokens.Space.s8)) }
        }
    )
}
